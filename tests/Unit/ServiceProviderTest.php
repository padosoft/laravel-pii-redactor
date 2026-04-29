<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit;

use Orchestra\Testbench\TestCase;
use Padosoft\PiiRedactor\PiiRedactorServiceProvider;

/**
 * Smoke test — verifies the service provider boots inside a Testbench
 * Laravel application.
 *
 * This is the v0.0.1 scaffold gate: as concrete bindings and tests
 * land during v4.0 development (regex EU + NER + LLM-based redaction
 * pipeline), this file stays as the "package health" check.
 */
final class ServiceProviderTest extends TestCase
{
    public function test_service_provider_boots_without_errors(): void
    {
        $providers = $this->app->getLoadedProviders();

        $this->assertArrayHasKey(PiiRedactorServiceProvider::class, $providers);
        $this->assertTrue($providers[PiiRedactorServiceProvider::class]);
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PiiRedactorServiceProvider::class];
    }
}
