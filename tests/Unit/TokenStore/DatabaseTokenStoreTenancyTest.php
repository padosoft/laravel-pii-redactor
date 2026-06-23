<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\TokenStore;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;
use Padosoft\PiiRedactor\Contracts\TenantResolver;
use Padosoft\PiiRedactor\PiiRedactorServiceProvider;
use Padosoft\PiiRedactor\TokenStore\DatabaseTokenStore;

/**
 * v1.4 — the reversible vault is tenant-isolated. A token minted in one
 * tenant's vault must never be readable, present, dumped, or cleared from
 * another tenant — cross-tenant reverse-identification is a GDPR catastrophe.
 */
final class DatabaseTokenStoreTenancyTest extends TestCase
{
    use RefreshDatabase;

    /** Mutable resolver so a single store instance can switch tenants per call. */
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

    private function store(): DatabaseTokenStore
    {
        /** @var TenantResolver $resolver */
        $resolver = $this->tenant;

        return new DatabaseTokenStore(tenants: $resolver);
    }

    public function test_a_token_is_invisible_to_another_tenant(): void
    {
        $store = $this->store();

        $this->tenant->id = 'tenant-a';
        $store->put('[tok:email:x]', 'mario@a.com');

        $this->tenant->id = 'tenant-b';
        $this->assertNull($store->get('[tok:email:x]'));
        $this->assertFalse($store->has('[tok:email:x]'));
        $this->assertSame([], $store->dump());

        $this->tenant->id = 'tenant-a';
        $this->assertSame('mario@a.com', $store->get('[tok:email:x]'));
        $this->assertTrue($store->has('[tok:email:x]'));
    }

    public function test_same_token_literal_coexists_per_tenant(): void
    {
        // UNIQUE(tenant_id, token) — the same literal can map to different
        // originals in two tenants without a unique-constraint clash.
        $store = $this->store();

        $this->tenant->id = 'tenant-a';
        $store->put('[tok:email:dup]', 'a@x.com');

        $this->tenant->id = 'tenant-b';
        $store->put('[tok:email:dup]', 'b@x.com');

        $this->tenant->id = 'tenant-a';
        $this->assertSame('a@x.com', $store->get('[tok:email:dup]'));

        $this->tenant->id = 'tenant-b';
        $this->assertSame('b@x.com', $store->get('[tok:email:dup]'));
    }

    public function test_clear_only_wipes_the_active_tenant(): void
    {
        $store = $this->store();

        $this->tenant->id = 'tenant-a';
        $store->put('[tok:email:a]', 'a@x.com');
        $this->tenant->id = 'tenant-b';
        $store->put('[tok:email:b]', 'b@x.com');

        // Clear tenant-a's vault.
        $this->tenant->id = 'tenant-a';
        $store->clear();
        $this->assertSame([], $store->dump());

        // tenant-b's vault is untouched.
        $this->tenant->id = 'tenant-b';
        $this->assertSame('b@x.com', $store->get('[tok:email:b]'));
        $this->assertCount(1, $store->dump());
    }

    public function test_load_replaces_only_the_active_tenant(): void
    {
        $store = $this->store();

        $this->tenant->id = 'tenant-b';
        $store->put('[tok:email:keep]', 'kept@b.com');

        $this->tenant->id = 'tenant-a';
        $store->load(['[tok:email:new]' => 'new@a.com']);

        $this->assertSame(['[tok:email:new]' => 'new@a.com'], $store->dump());

        // tenant-b survives tenant-a's load/replace.
        $this->tenant->id = 'tenant-b';
        $this->assertSame('kept@b.com', $store->get('[tok:email:keep]'));
    }
}
