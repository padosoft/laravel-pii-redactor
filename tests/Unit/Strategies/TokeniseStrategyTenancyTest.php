<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Strategies;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;
use Padosoft\PiiRedactor\Contracts\TenantResolver;
use Padosoft\PiiRedactor\PiiRedactorServiceProvider;
use Padosoft\PiiRedactor\Strategies\TokeniseStrategy;
use Padosoft\PiiRedactor\TokenStore\DatabaseTokenStore;
use Padosoft\PiiRedactor\TokenStore\InMemoryTokenStore;

/**
 * v1.4 — per-tenant salt + tenant-scoped vault on the tokenise strategy:
 * the SAME PII value yields a DIFFERENT token per tenant (no cross-tenant
 * correlation), and a token minted in one tenant cannot be detokenised in
 * another.
 */
final class TokeniseStrategyTenancyTest extends TestCase
{
    use RefreshDatabase;

    private object $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = new class implements TenantResolver
        {
            public string $id = 'tenant-a';

            public function currentTenantId(): string
            {
                return $this->id;
            }
        };
    }

    /**
     * @return array<int, class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [PiiRedactorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
    }

    public function test_same_value_yields_a_different_token_per_tenant(): void
    {
        /** @var TenantResolver $resolver */
        $resolver = $this->tenant;
        $strategy = new TokeniseStrategy('base-salt', 16, new InMemoryTokenStore, $resolver);

        $this->tenant->id = 'tenant-a';
        $tokenA = $strategy->apply('mario.rossi@example.com', 'email');

        $this->tenant->id = 'tenant-b';
        $tokenB = $strategy->apply('mario.rossi@example.com', 'email');

        $this->assertNotSame($tokenA, $tokenB, 'per-tenant salt must yield distinct tokens for the same PII');
    }

    public function test_a_token_cannot_be_detokenised_in_another_tenant(): void
    {
        /** @var TenantResolver $resolver */
        $resolver = $this->tenant;
        $strategy = new TokeniseStrategy('base-salt', 16, new DatabaseTokenStore(tenants: $resolver), $resolver);

        $this->tenant->id = 'tenant-a';
        $token = $strategy->apply('mario.rossi@example.com', 'email');
        $this->assertSame('mario.rossi@example.com', $strategy->detokenise($token));

        // Switch tenant: the token is unknown in tenant-b's vault, so it is
        // left verbatim (never resolved to the foreign tenant's PII).
        $this->tenant->id = 'tenant-b';
        $this->assertNull($strategy->detokenise($token));
        $this->assertSame('A '.$token.' B', $strategy->detokeniseString('A '.$token.' B'));

        // Back to tenant-a: still resolvable.
        $this->tenant->id = 'tenant-a';
        $this->assertSame('mario.rossi@example.com', $strategy->detokenise($token));
    }

    public function test_default_tenant_mints_the_same_token_as_no_resolver(): void
    {
        // P2 compat: a single-tenant upgrade (default resolver, default id)
        // must mint byte-for-byte the pre-v1.4 token — the legacy tenant uses
        // the BARE salt, not `salt:default`.
        $legacy = new TokeniseStrategy('base-salt', 16, new InMemoryTokenStore);

        $defaultResolver = new class implements TenantResolver
        {
            public function currentTenantId(): string
            {
                return 'default';
            }
        };
        $tenantAware = new TokeniseStrategy('base-salt', 16, new InMemoryTokenStore($defaultResolver), $defaultResolver, 'default');

        $this->assertSame(
            $legacy->apply('mario.rossi@example.com', 'email'),
            $tenantAware->apply('mario.rossi@example.com', 'email'),
            'the default/legacy tenant must keep the pre-v1.4 token id',
        );
    }

    public function test_in_memory_store_isolates_tenants(): void
    {
        // P1: the process-local memory driver must also be tenant-scoped, or a
        // long-lived multi-tenant worker leaks tenant A's PII when tenant B
        // detokenises the same literal.
        /** @var TenantResolver $resolver */
        $resolver = $this->tenant;
        $strategy = new TokeniseStrategy('base-salt', 16, new InMemoryTokenStore($resolver), $resolver);

        $this->tenant->id = 'tenant-a';
        $token = $strategy->apply('mario.rossi@example.com', 'email');
        $this->assertSame('mario.rossi@example.com', $strategy->detokenise($token));

        $this->tenant->id = 'tenant-b';
        $this->assertNull($strategy->detokenise($token));
        $this->assertSame('X '.$token.' Y', $strategy->detokeniseString('X '.$token.' Y'));
    }

    public function test_without_a_resolver_behaviour_is_unchanged(): void
    {
        // R43 backward-compat: no resolver → single deterministic salt.
        $a = new TokeniseStrategy('base-salt', 16, new InMemoryTokenStore);
        $b = new TokeniseStrategy('base-salt', 16, new InMemoryTokenStore);

        $this->assertSame(
            $a->apply('mario.rossi@example.com', 'email'),
            $b->apply('mario.rossi@example.com', 'email'),
            'no-resolver tokens must stay deterministic across instances (pre-v1.4 behaviour)',
        );
    }
}
