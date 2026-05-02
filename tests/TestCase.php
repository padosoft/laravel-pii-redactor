<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests;

use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Padosoft\PiiRedactor\Facades\Pii;
use Padosoft\PiiRedactor\PiiRedactorServiceProvider;

/**
 * Convenience base class for tests that want the full Testbench harness
 * with the package provider + facade alias already wired. Tests targeting
 * pure value objects (detectors, strategies, reports) can keep extending
 * `\PHPUnit\Framework\TestCase` directly — Testbench is only needed for
 * service-container resolution and Artisan command exercising.
 */
abstract class TestCase extends Orchestra
{
    /**
     * @return array<int, class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PiiRedactorServiceProvider::class,
        ];
    }

    /**
     * @return array<string, class-string>
     */
    protected function getPackageAliases($app): array
    {
        return [
            'Pii' => Pii::class,
        ];
    }
}
