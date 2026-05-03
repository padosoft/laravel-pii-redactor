<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\TokenStore;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;
use Padosoft\PiiRedactor\PiiRedactorServiceProvider;
use Padosoft\PiiRedactor\TokenStore\CacheTokenStore;

final class CacheTokenStoreTest extends TestCase
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

    protected function defineEnvironment($app): void
    {
        // The `array` cache driver is in-memory and ships with Laravel
        // out of the box — no Redis/Memcached server required, so the
        // CacheTokenStore can be exercised end-to-end through the real
        // Repository contract under PHPUnit.
        $app['config']->set('cache.default', 'array');
    }

    private function makeStore(?int $ttlSeconds = null, string $prefix = 'pii_token:'): CacheTokenStore
    {
        /** @var Repository $repo */
        $repo = Cache::store('array');

        return new CacheTokenStore($repo, $prefix, $ttlSeconds);
    }

    public function test_new_store_dump_is_empty(): void
    {
        $store = $this->makeStore();

        $this->assertSame([], $store->dump());
    }

    public function test_put_then_get_returns_original(): void
    {
        $store = $this->makeStore();
        $store->put('[tok:email:abcdef0123456789]', 'mario.rossi@example.com');

        $this->assertSame('mario.rossi@example.com', $store->get('[tok:email:abcdef0123456789]'));
    }

    public function test_get_returns_null_for_unknown_token(): void
    {
        $store = $this->makeStore();

        $this->assertNull($store->get('[tok:email:missing]'));
    }

    public function test_has_reflects_presence(): void
    {
        $store = $this->makeStore();
        $store->put('[tok:email:abc]', 'one@x.com');

        $this->assertTrue($store->has('[tok:email:abc]'));
        $this->assertFalse($store->has('[tok:email:missing]'));
    }

    public function test_put_is_idempotent_on_duplicate_token(): void
    {
        $store = $this->makeStore();
        $store->put('[tok:email:abc]', 'one@x.com');
        $store->put('[tok:email:abc]', 'one@x.com');

        $this->assertSame('one@x.com', $store->get('[tok:email:abc]'));
        $this->assertCount(1, $store->dump());
    }

    public function test_clear_empties_the_store(): void
    {
        $store = $this->makeStore();
        $store->put('[tok:email:abc]', 'one@x.com');
        $store->put('[tok:email:def]', 'two@x.com');
        $store->clear();

        $this->assertSame([], $store->dump());
        $this->assertFalse($store->has('[tok:email:abc]'));
    }

    public function test_load_replaces_existing_entries(): void
    {
        // load() is the rehydrate path: callers expect the post-load
        // store to contain EXACTLY the supplied map — not a merge with
        // whatever was there before. Same semantic as the in-memory and
        // database drivers.
        $store = $this->makeStore();
        $store->put('[tok:email:keep]', 'kept@x.com');
        $store->put('[tok:email:drop]', 'dropped@x.com');

        $store->load(['[tok:email:replaced]' => 'replaced@x.com']);

        $this->assertSame(
            ['[tok:email:replaced]' => 'replaced@x.com'],
            $store->dump(),
            'load() must replace the existing entries, not merge with them',
        );
        $this->assertFalse($store->has('[tok:email:keep]'));
        $this->assertFalse($store->has('[tok:email:drop]'));
    }

    public function test_load_empty_map_clears_existing_entries(): void
    {
        $store = $this->makeStore();
        $store->put('[tok:email:abc]', 'one@x.com');

        $store->load([]);

        $this->assertSame([], $store->dump());
        $this->assertFalse($store->has('[tok:email:abc]'));
    }

    /**
     * Two CacheTokenStore instances pointing at the same Repository see
     * each other's writes — the cross-process invariant that motivates
     * picking the cache driver over the in-memory one.
     */
    public function test_two_instances_see_each_others_writes(): void
    {
        /** @var Repository $repo */
        $repo = Cache::store('array');
        $a = new CacheTokenStore($repo);
        $b = new CacheTokenStore($repo);

        $a->put('[tok:email:cross]', 'shared@x.com');

        $this->assertTrue($b->has('[tok:email:cross]'));
        $this->assertSame('shared@x.com', $b->get('[tok:email:cross]'));
    }

    public function test_ttl_is_honoured_when_provided(): void
    {
        // The `array` cache driver in Laravel honours TTL semantics by
        // tracking expiry timestamps internally; a 1-second TTL plus a
        // 2-second sleep is enough to observe expiry without flakiness.
        // If this proves slow on a constrained CI runner, the test can
        // be split off behind a CI marker — but at ~2s wall-clock cost
        // it's well below the rest of the suite's overhead.
        $store = $this->makeStore(ttlSeconds: 1);
        $store->put('[tok:email:expires]', 'transient@x.com');
        $this->assertSame('transient@x.com', $store->get('[tok:email:expires]'));

        sleep(2);

        $this->assertNull(
            $store->get('[tok:email:expires]'),
            'After the TTL elapses the cache entry must be gone — proves the ttlSeconds parameter is wired through.',
        );
    }

    public function test_load_with_replacement_value_for_same_token(): void
    {
        $store = $this->makeStore();
        $store->put('[tok:email:dup]', 'old@x.com');

        $store->load(['[tok:email:dup]' => 'new@x.com']);

        $this->assertSame('new@x.com', $store->get('[tok:email:dup]'));
    }

    public function test_distinct_prefixes_isolate_stores(): void
    {
        // Two stores sharing a Repository but with different prefixes
        // must not see each other's data — important when a host wants
        // multiple tokenisation namespaces on the same Redis instance.
        /** @var Repository $repo */
        $repo = Cache::store('array');
        $a = new CacheTokenStore($repo, 'pii_token_a:');
        $b = new CacheTokenStore($repo, 'pii_token_b:');

        $a->put('[tok:email:x]', 'a-side@x.com');
        $b->put('[tok:email:x]', 'b-side@x.com');

        $this->assertSame('a-side@x.com', $a->get('[tok:email:x]'));
        $this->assertSame('b-side@x.com', $b->get('[tok:email:x]'));
        $this->assertCount(1, $a->dump());
        $this->assertCount(1, $b->dump());
    }

    public function test_repeated_put_of_same_token_does_not_duplicate_in_index(): void
    {
        // Calling put() multiple times for the same token must not inflate the
        // index — dump() should return exactly one entry.
        $store = $this->makeStore();
        $store->put('[tok:email:dup]', 'same@x.com');
        $store->put('[tok:email:dup]', 'same@x.com');
        $store->put('[tok:email:dup]', 'same@x.com');

        $this->assertCount(1, $store->dump());
    }

    public function test_index_ttl_is_refreshed_on_repeated_put(): void
    {
        // addToIndex() must persist the index on EVERY put() (not just when a new
        // token is added) so the index TTL keeps pace with the token entry TTL.
        // Without this, an index created at t=0 with TTL=1 would expire even
        // though a repeated put() at t=0.5 refreshed the token entry's TTL.
        //
        // We verify this by: putting a token with TTL=2, sleeping 1s, then
        // putting the same token again (refreshes both TTLs to 2s), sleeping
        // another 1s, and confirming the token and its index entry are still
        // alive. Total elapsed: 2s but each TTL was refreshed to 2s at t=1s.
        $store = $this->makeStore(ttlSeconds: 2);
        $store->put('[tok:email:refresh]', 'ttl-refreshed@x.com');

        sleep(1);

        // Repeat put(): this must refresh the index TTL so it does not expire.
        $store->put('[tok:email:refresh]', 'ttl-refreshed@x.com');

        sleep(1);

        // If the index TTL was NOT refreshed it would have expired by now.
        $this->assertSame('ttl-refreshed@x.com', $store->get('[tok:email:refresh]'),
            'Token value must still be alive after TTL refresh');
        $this->assertArrayHasKey('[tok:email:refresh]', $store->dump(),
            'dump() must still see the token after its TTL was refreshed via a repeated put()');
    }
}
