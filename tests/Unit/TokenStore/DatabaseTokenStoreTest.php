<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\TokenStore;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;
use Padosoft\PiiRedactor\PiiRedactorServiceProvider;
use Padosoft\PiiRedactor\TokenStore\DatabaseTokenStore;

final class DatabaseTokenStoreTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [
            PiiRedactorServiceProvider::class,
        ];
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

    public function test_new_store_dump_is_empty(): void
    {
        $store = new DatabaseTokenStore;

        $this->assertSame([], $store->dump());
    }

    public function test_put_then_get_returns_original(): void
    {
        $store = new DatabaseTokenStore;
        $store->put('[tok:email:abcdef0123456789]', 'mario.rossi@example.com');

        $this->assertSame('mario.rossi@example.com', $store->get('[tok:email:abcdef0123456789]'));
    }

    public function test_get_returns_null_for_unknown_token(): void
    {
        $store = new DatabaseTokenStore;

        $this->assertNull($store->get('[tok:email:missing]'));
    }

    public function test_has_reflects_presence(): void
    {
        $store = new DatabaseTokenStore;
        $store->put('[tok:email:abc]', 'one@x.com');

        $this->assertTrue($store->has('[tok:email:abc]'));
        $this->assertFalse($store->has('[tok:email:missing]'));
    }

    public function test_put_is_idempotent_on_duplicate_token(): void
    {
        $store = new DatabaseTokenStore;
        $store->put('[tok:email:abc]', 'one@x.com');
        $store->put('[tok:email:abc]', 'one@x.com');

        $this->assertSame('one@x.com', $store->get('[tok:email:abc]'));
        $this->assertCount(1, $store->dump());
    }

    public function test_clear_empties_the_store(): void
    {
        $store = new DatabaseTokenStore;
        $store->put('[tok:email:abc]', 'one@x.com');
        $store->put('[tok:email:def]', 'two@x.com');
        $store->clear();

        $this->assertSame([], $store->dump());
        $this->assertFalse($store->has('[tok:email:abc]'));
    }

    public function test_load_populates_then_dump_returns_loaded_map(): void
    {
        $store = new DatabaseTokenStore;
        $payload = [
            '[tok:email:111]' => 'alpha@x.com',
            '[tok:phone_it:222]' => '+39 333 1234567',
        ];

        $store->load($payload);

        $this->assertSame($payload, $store->dump());
        $this->assertSame('alpha@x.com', $store->get('[tok:email:111]'));
    }

    /**
     * Two DatabaseTokenStore instances pointing at the same table see
     * each other's writes — the cross-process invariant the in-memory
     * driver cannot satisfy.
     */
    public function test_two_instances_see_each_others_writes(): void
    {
        $a = new DatabaseTokenStore;
        $b = new DatabaseTokenStore;

        $a->put('[tok:email:cross]', 'shared@x.com');

        $this->assertTrue($b->has('[tok:email:cross]'));
        $this->assertSame('shared@x.com', $b->get('[tok:email:cross]'));
    }

    public function test_load_upsert_overwrites_existing_original(): void
    {
        $store = new DatabaseTokenStore;
        $store->put('[tok:email:dup]', 'old@x.com');

        // Load is the rehydrate path; same token with new original
        // must overwrite (last write wins).
        $store->load(['[tok:email:dup]' => 'new@x.com']);

        $this->assertSame('new@x.com', $store->get('[tok:email:dup]'));
    }

    public function test_load_empty_map_is_a_noop(): void
    {
        $store = new DatabaseTokenStore;
        $store->load([]);

        $this->assertSame([], $store->dump());
    }
}
