<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Tenancy;

use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;
use Padosoft\PiiRedactor\Contracts\TenantResolver;
use Padosoft\PiiRedactor\PiiRedactorServiceProvider;
use Padosoft\PiiRedactor\Strategies\RedactionStrategy;

/**
 * v1.4 — the singleton token store + strategy hold a LAZY resolver proxy, so a
 * host that rebinds (or scopes) its TenantResolver per request/job is honoured
 * on every operation instead of being frozen to the first instance.
 */
final class LazyTenantResolverBindingTest extends TestCase
{
    /**
     * @return array<int, class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [PiiRedactorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('pii-redactor.strategy', 'tokenise');
        $app['config']->set('pii-redactor.salt', 'base-salt');
        $app['config']->set('pii-redactor.token_store.driver', 'memory');
    }

    private function bindTenant(string $id): void
    {
        $this->app->bind(TenantResolver::class, fn () => new class($id) implements TenantResolver
        {
            public function __construct(private readonly string $id) {}

            public function currentTenantId(): string
            {
                return $this->id;
            }
        });
    }

    public function test_singleton_strategy_follows_a_rebound_resolver(): void
    {
        $this->bindTenant('tenant-a');

        /** @var RedactionStrategy $strategy */
        $strategy = $this->app->make(RedactionStrategy::class); // singleton
        $tokenA = $strategy->apply('mario.rossi@example.com', 'email');

        // Rebind the resolver (simulating the next request/job's tenant). The
        // SAME singleton strategy must now mint under tenant-b.
        $this->bindTenant('tenant-b');
        $tokenB = $strategy->apply('mario.rossi@example.com', 'email');

        $this->assertNotSame(
            $tokenA,
            $tokenB,
            'the singleton strategy must re-resolve the rebound resolver, not freeze the first instance',
        );
    }
}
