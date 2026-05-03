<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor;

use Illuminate\Contracts\Cache\Factory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Padosoft\PiiRedactor\Console\PiiScanCommand;
use Padosoft\PiiRedactor\Detectors\Detector;
use Padosoft\PiiRedactor\Exceptions\DetectorException;
use Padosoft\PiiRedactor\Exceptions\StrategyException;
use Padosoft\PiiRedactor\Ner\NerDriver;
use Padosoft\PiiRedactor\Ner\StubNerDriver;
use Padosoft\PiiRedactor\Strategies\DropStrategy;
use Padosoft\PiiRedactor\Strategies\HashStrategy;
use Padosoft\PiiRedactor\Strategies\MaskStrategy;
use Padosoft\PiiRedactor\Strategies\RedactionStrategy;
use Padosoft\PiiRedactor\Strategies\TokeniseStrategy;
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

        $this->app->singleton(RedactionStrategy::class, fn (Application $app): RedactionStrategy => $this->buildStrategy($app));

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
    }

    private function buildStrategy(Application $app): RedactionStrategy
    {
        $config = $app['config'];
        $name = (string) $config->get('pii-redactor.strategy', 'mask');

        return match ($name) {
            'mask' => new MaskStrategy((string) $config->get('pii-redactor.mask_token', '[REDACTED]')),
            'hash' => new HashStrategy(
                salt: $this->requireSalt($config->get('pii-redactor.salt')),
                hexLength: (int) $config->get('pii-redactor.hash_hex_length', 16),
            ),
            'tokenise' => new TokeniseStrategy(
                salt: $this->requireSalt($config->get('pii-redactor.salt')),
                idHexLength: (int) $config->get('pii-redactor.token_hex_length', 16),
                store: $app->make(TokenStore::class),
            ),
            'drop' => new DropStrategy,
            default => throw new StrategyException(sprintf(
                'Unknown PII redaction strategy [%s]. Valid: mask, hash, tokenise, drop.',
                $name,
            )),
        };
    }

    private function requireSalt(mixed $raw): string
    {
        $salt = is_string($raw) ? $raw : '';
        if ($salt === '') {
            throw new StrategyException(
                'PII_REDACTOR_SALT must be a non-empty string when using hash or tokenise strategies.',
            );
        }

        return $salt;
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
