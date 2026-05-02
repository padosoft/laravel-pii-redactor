<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Padosoft\PiiRedactor\Console\PiiScanCommand;
use Padosoft\PiiRedactor\Detectors\Detector;
use Padosoft\PiiRedactor\Exceptions\DetectorException;
use Padosoft\PiiRedactor\Exceptions\StrategyException;
use Padosoft\PiiRedactor\Strategies\DropStrategy;
use Padosoft\PiiRedactor\Strategies\HashStrategy;
use Padosoft\PiiRedactor\Strategies\MaskStrategy;
use Padosoft\PiiRedactor\Strategies\RedactionStrategy;
use Padosoft\PiiRedactor\Strategies\TokeniseStrategy;

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

        $this->app->singleton(RedactionStrategy::class, fn (Application $app): RedactionStrategy => $this->buildStrategy($app));

        $this->app->singleton(RedactorEngine::class, function (Application $app): RedactorEngine {
            $engine = new RedactorEngine(
                $app->make(RedactionStrategy::class),
                (bool) $app['config']->get('pii-redactor.enabled', true),
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
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/pii-redactor.php' => $this->app->configPath('pii-redactor.php'),
            ], 'pii-redactor-config');

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
}
