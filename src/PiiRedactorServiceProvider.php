<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Padosoft\PiiRedactor\Console\PiiScanCommand;
use Padosoft\PiiRedactor\CustomRules\CustomRuleDetector;
use Padosoft\PiiRedactor\CustomRules\YamlCustomRuleLoader;
use Padosoft\PiiRedactor\Detectors\Detector;
use Padosoft\PiiRedactor\Exceptions\CustomRuleException;
use Padosoft\PiiRedactor\Exceptions\DetectorException;
use Padosoft\PiiRedactor\Exceptions\StrategyException;
use Padosoft\PiiRedactor\Ner\NerDriver;
use Padosoft\PiiRedactor\Ner\StubNerDriver;
use Padosoft\PiiRedactor\Packs\DetectorPackRegistry;
use Padosoft\PiiRedactor\Packs\PackContract;
use Padosoft\PiiRedactor\Strategies\RedactionStrategy;
use Padosoft\PiiRedactor\Strategies\RedactionStrategyFactory;
use Padosoft\PiiRedactor\TokenStore\CacheTokenStore;
use Padosoft\PiiRedactor\TokenStore\DatabaseTokenStore;
use Padosoft\PiiRedactor\TokenStore\InMemoryTokenStore;
use Padosoft\PiiRedactor\TokenStore\TokenStore;

/**
 * Wires the package into a Laravel host:
 *  - publishes the config file
 *  - resolves the active RedactionStrategy from config('pii-redactor.strategy')
 *  - builds a singleton RedactorEngine with the configured detector list
 *  - registers the Pii facade accessor
 *  - registers the pii:scan Artisan command
 */
final class PiiRedactorServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/pii-redactor.php', 'pii-redactor');

        $this->app->singleton(TokenStore::class, fn (Application $app): TokenStore => $this->buildTokenStore($app));

        $this->app->singleton(RedactionStrategyFactory::class, fn (Application $app): RedactionStrategyFactory => new RedactionStrategyFactory(
            $app['config'],
            $app->make(TokenStore::class),
        ));

        $this->app->singleton(RedactionStrategy::class, fn (Application $app): RedactionStrategy => $app
            ->make(RedactionStrategyFactory::class)
            ->make());

        $this->app->singleton(DetectorPackRegistry::class, function (Application $app): DetectorPackRegistry {
            $packs = (array) $app['config']->get('pii-redactor.packs', []);

            /** @var list<class-string<PackContract>|string> $packs */
            return new DetectorPackRegistry($app, array_values($packs));
        });

        $this->app->singleton(NerDriver::class, function (Application $app): NerDriver {
            $config = $app['config'];
            if (! (bool) $config->get('pii-redactor.ner.enabled', false)) {
                return new StubNerDriver;
            }

            $driverName = (string) $config->get('pii-redactor.ner.driver', 'stub');
            $driverMap = (array) $config->get('pii-redactor.ner.drivers', []);
            $driverClass = $driverMap[$driverName] ?? StubNerDriver::class;

            if (! is_string($driverClass) || ! class_exists($driverClass)) {
                return new StubNerDriver;
            }

            $driver = $app->make($driverClass);
            if (! $driver instanceof NerDriver) {
                throw new DetectorException(sprintf(
                    'NER driver class [%s] in config[pii-redactor.ner.drivers] must implement %s.',
                    $driverClass,
                    NerDriver::class,
                ));
            }

            return $driver;
        });

        $this->app->singleton(RedactorEngine::class, function (Application $app): RedactorEngine {
            // Honor BOTH the structured v0.2 key and the v0.1 flat key —
            // either being truthy enables the audit trail. The previous
            // null-coalescing form short-circuited on the first key
            // because the package's own default is `false` (non-null),
            // so the flat fallback never fired even when callers had
            // explicitly set `audit_trail_enabled => true` in their
            // legacy config.
            $structured = (bool) $app['config']->get('pii-redactor.audit_trail.enabled', false);
            $flat = (bool) $app['config']->get('pii-redactor.audit_trail_enabled', false);
            $auditTrailEnabled = $structured || $flat;
            $nerDriver = $app->make(NerDriver::class);

            $engine = new RedactorEngine(
                $app->make(RedactionStrategy::class),
                (bool) $app['config']->get('pii-redactor.enabled', true),
                $auditTrailEnabled,
                $nerDriver,
            );

            $detectors = (array) $app['config']->get('pii-redactor.detectors', []);
            foreach ($detectors as $detectorClass) {
                if (! is_string($detectorClass) || ! class_exists($detectorClass)) {
                    continue;
                }
                $detector = $app->make($detectorClass);
                if (! $detector instanceof Detector) {
                    throw new DetectorException(sprintf(
                        'Detector class [%s] in config[pii-redactor.detectors] must implement %s.',
                        $detectorClass,
                        Detector::class,
                    ));
                }
                $engine->register($detector);
            }

            // v1.0: pack-aggregated detectors registered ON TOP of the existing
            // `pii-redactor.detectors` list. Both surfaces coexist for backward
            // compat — the flat list is the legacy entry point, packs are the
            // preferred jurisdiction-aware grouping. The DetectorPackRegistry
            // throws PackException on misconfiguration; that surfaces here at
            // boot rather than silently shipping a host with disabled coverage.
            $registry = $app->make(DetectorPackRegistry::class);
            foreach ($registry->detectors() as $detector) {
                $engine->register($detector);
            }

            return $engine;
        });

        $this->app->alias(RedactorEngine::class, 'pii-redactor');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/pii-redactor.php' => $this->app->configPath('pii-redactor.php'),
            ], 'pii-redactor-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'pii-redactor-migrations');

            $this->commands([
                PiiScanCommand::class,
            ]);
        }

        $this->autoRegisterCustomRulePacks();
    }

    /**
     * Walk `config('pii-redactor.custom_rules.packs')` at boot and register
     * each YAML pack as a CustomRuleDetector with the engine. Closes the
     * v0.3 deferred TODO — hosts list their packs in config and the SP
     * does the wiring instead of every host repeating the boilerplate.
     *
     * Skipped entirely when `custom_rules.auto_register` is false (the
     * default), so v0.3 hosts that already wire packs manually via
     * `Pii::extend()` are unaffected.
     *
     * Each pack entry must be `['name' => '...', 'path' => '...']`. Both
     * fields are required and non-empty; missing / invalid entries surface
     * as `CustomRuleException` so misconfiguration fails fast at boot.
     */
    private function autoRegisterCustomRulePacks(): void
    {
        $config = $this->app['config'];
        if (! (bool) $config->get('pii-redactor.custom_rules.auto_register', false)) {
            return;
        }
        $packs = (array) $config->get('pii-redactor.custom_rules.packs', []);
        if ($packs === []) {
            return;
        }

        $loader = new YamlCustomRuleLoader;
        $engine = $this->app->make(RedactorEngine::class);

        foreach ($packs as $i => $entry) {
            if (! is_array($entry)) {
                throw new CustomRuleException(sprintf(
                    'pii-redactor.custom_rules.packs[%d] must be an array with `name` and `path` keys.',
                    $i,
                ));
            }
            $name = isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : '';
            $path = isset($entry['path']) && is_string($entry['path']) ? $entry['path'] : '';
            if ($name === '' || $path === '') {
                throw new CustomRuleException(sprintf(
                    'pii-redactor.custom_rules.packs[%d] requires a non-empty `name` and `path`.',
                    $i,
                ));
            }

            $set = $loader->load($path);
            $engine->register(new CustomRuleDetector($name, $set));
        }
    }

    private function buildTokenStore(Application $app): TokenStore
    {
        $config = $app['config'];
        $driver = (string) $config->get('pii-redactor.token_store.driver', 'memory');

        return match ($driver) {
            'memory' => new InMemoryTokenStore,
            'database' => new DatabaseTokenStore(
                connection: $this->stringOrNull($config->get('pii-redactor.token_store.database.connection')),
                table: (string) $config->get('pii-redactor.token_store.database.table', 'pii_token_maps'),
            ),
            'cache' => new CacheTokenStore(
                cache: $this->resolveCacheRepository($app, $config),
                prefix: (string) $config->get('pii-redactor.token_store.cache.prefix', 'pii_token:'),
                ttlSeconds: $this->intOrNull($config->get('pii-redactor.token_store.cache.ttl')),
            ),
            default => throw new StrategyException(sprintf(
                'Unknown TokenStore driver [%s]. Valid: memory, database, cache.',
                $driver,
            )),
        };
    }

    private function stringOrNull(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $s = (string) $raw;

        return $s === '' ? null : $s;
    }

    private function resolveCacheRepository(Application $app, \Illuminate\Contracts\Config\Repository $config): Repository
    {
        $store = $config->get('pii-redactor.token_store.cache.store');
        if ($store === null || $store === '') {
            return $app->make(Repository::class);
        }

        return $app->make(Factory::class)->store((string) $store);
    }

    private function intOrNull(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $i = (int) $raw;

        return $i > 0 ? $i : null;
    }
}
