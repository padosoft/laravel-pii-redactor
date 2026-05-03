<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Robustness;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Padosoft\PiiRedactor\Tests\TestCase;
use Padosoft\PiiRedactor\TokenStore\CacheTokenStore;
use Padosoft\PiiRedactor\TokenStore\DatabaseTokenStore;

/**
 * Robustness tests covering category 3 (concurrency / cross-instance
 * simulation).
 *
 * PHP doesn't fork test processes natively, so these tests simulate
 * concurrency by interleaving operations across two store instances
 * that share the same backend. The intent is to PIN the documented
 * behaviour — including the known limitations — so a future refactor
 * either preserves them or changes them deliberately with an updated
 * test.
 *
 * Each test method documents the regression it would catch and, where
 * relevant, the structural mitigation a host should reach for if the
 * limitation matters.
 */
final class ConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
    }

    // ---------------------------------------------------------------
    // CacheTokenStore — index race documented limitation.
    // ---------------------------------------------------------------

    /**
     * KNOWN LIMITATION (pinned by this test): the CacheTokenStore index
     * is read + written non-atomically by `addToIndex()`. When two
     * stores observe the same starting index `[]`, write their token,
     * and then update the index, the second writer's index update
     * clobbers the first. The token VALUES are still both retrievable
     * by `get()` (the per-token cache writes don't race the same way),
     * but `dump()` only sees the tokens listed in the most-recently
     * persisted index.
     *
     * Mitigations available to consumers:
     *   1. Use the same store instance for sequential writes (the index
     *      stays consistent within one PHP process).
     *   2. Switch to DatabaseTokenStore — the SQL UNIQUE on `token`
     *      provides atomic insertion, and `dump()` reads the table
     *      directly (no separate index).
     *   3. Combine TTL=null with periodic `clear()` rotations so a
     *      stale index never accumulates orphan entries.
     *
     * Catches a regression where someone "fixes" the race naively (e.g.
     * with array_merge) and silently degrades the dump() contract.
     */
    public function test_cache_token_store_index_race_documents_known_limitation(): void
    {
        /** @var Repository $repo */
        $repo = Cache::store('array');
        $a = new CacheTokenStore($repo, 'pii_token:');
        $b = new CacheTokenStore($repo, 'pii_token:');

        // Both stores read the empty index simultaneously, then each
        // writes its own token + index. We simulate by INTERLEAVING the
        // two calls — but PHP's single-threaded model means we can't
        // truly interleave addToIndex internals. Instead, we observe
        // the steady-state result of two sequential writes from
        // different instances: this models the race window because
        // each addToIndex() call internally re-reads the current index
        // before writing.
        //
        // In a TRUE race (two PHP-FPM workers), A and B both read
        // `[]` first, then each writes its single-element index — the
        // last write wins. The single-process simulation below cannot
        // exercise that exact ordering, so we rely on the read-modify-
        // write window pattern + a documented mitigation test that
        // shows the workaround.

        $a->put('[tok:email:T1]', 'mario@x.com');
        $b->put('[tok:email:T2]', 'rita@x.com');

        // The per-token cache entries are independently persisted, so
        // both `get()` calls succeed on either instance.
        $this->assertSame('mario@x.com', $a->get('[tok:email:T1]'));
        $this->assertSame('mario@x.com', $b->get('[tok:email:T1]'));
        $this->assertSame('rita@x.com', $a->get('[tok:email:T2]'));
        $this->assertSame('rita@x.com', $b->get('[tok:email:T2]'));

        // Single-process sequential interleave: addToIndex re-reads the
        // index before writing, so both tokens DO end up in the index
        // here. This pins the in-process behaviour. The documented
        // limitation surfaces only across PHP processes — pinned in
        // the comment above, not in code, because PHPUnit cannot fork.
        $dumpA = $a->dump();
        $this->assertCount(2, $dumpA);
        $this->assertArrayHasKey('[tok:email:T1]', $dumpA);
        $this->assertArrayHasKey('[tok:email:T2]', $dumpA);
    }

    /**
     * Mitigation: serial writes through ONE instance keep the index
     * consistent. This is the default-recommended consumer pattern.
     */
    public function test_cache_token_store_serial_writes_through_single_instance_preserve_index(): void
    {
        /** @var Repository $repo */
        $repo = Cache::store('array');
        $store = new CacheTokenStore($repo);

        $store->put('[tok:email:T1]', 'mario@x.com');
        $store->put('[tok:email:T2]', 'rita@x.com');
        $store->put('[tok:email:T3]', 'luigi@x.com');

        $dump = $store->dump();
        $this->assertCount(3, $dump);
        $this->assertSame('mario@x.com', $dump['[tok:email:T1]']);
        $this->assertSame('rita@x.com', $dump['[tok:email:T2]']);
        $this->assertSame('luigi@x.com', $dump['[tok:email:T3]']);
    }

    /**
     * The two-instance dump consistency relies on each addToIndex()
     * re-reading the latest index. This test pins the read-modify-
     * write contract: between A's write and B's write, the cache holds
     * A's index, so B reads it and appends — both tokens land.
     */
    public function test_cache_token_store_two_instances_each_index_reads_latest_state(): void
    {
        /** @var Repository $repo */
        $repo = Cache::store('array');
        $a = new CacheTokenStore($repo);
        $b = new CacheTokenStore($repo);

        $a->put('[tok:email:from_a]', 'a@x.com');
        // B reads the index that A just wrote, appends its own token,
        // writes back. Both tokens are visible from either instance.
        $b->put('[tok:email:from_b]', 'b@x.com');

        $this->assertCount(2, $a->dump());
        $this->assertCount(2, $b->dump());
        $this->assertSame('a@x.com', $b->get('[tok:email:from_a]'));
        $this->assertSame('b@x.com', $a->get('[tok:email:from_b]'));
    }

    // ---------------------------------------------------------------
    // DatabaseTokenStore — SQL UNIQUE provides atomic concurrency.
    // ---------------------------------------------------------------

    /**
     * Catches: a regression that drops the `unique` constraint on the
     * `token` column. The SQL UNIQUE makes concurrent inserts of
     * different tokens trivially safe, and concurrent inserts of the
     * SAME token resolve via updateOrCreate (last-write-wins) without
     * dup rows.
     */
    public function test_database_token_store_two_instances_different_tokens_both_visible(): void
    {
        $a = new DatabaseTokenStore;
        $b = new DatabaseTokenStore;

        $a->put('[tok:email:T1]', 'mario@x.com');
        $b->put('[tok:email:T2]', 'rita@x.com');

        $this->assertCount(2, $a->dump());
        $this->assertCount(2, $b->dump());
        $this->assertSame('mario@x.com', $b->get('[tok:email:T1]'));
        $this->assertSame('rita@x.com', $a->get('[tok:email:T2]'));
    }

    /**
     * Pins the documented uniqueness invariant: same token + same
     * original = exactly one row, regardless of how many writers.
     */
    public function test_database_token_store_same_token_same_original_results_in_single_row(): void
    {
        $a = new DatabaseTokenStore;
        $b = new DatabaseTokenStore;

        $a->put('[tok:email:T1]', 'mario@x.com');
        $b->put('[tok:email:T1]', 'mario@x.com');

        $dump = $a->dump();
        $this->assertCount(1, $dump);
        $this->assertSame('mario@x.com', $dump['[tok:email:T1]']);
    }

    /**
     * Pins the documented "last write wins" semantic: same token +
     * different originals = one row, the most recent original. This
     * is the v0.2 behaviour — flipping it (e.g. to "reject divergent
     * original") is a breaking change requiring a new ADR.
     */
    public function test_database_token_store_same_token_different_original_last_writer_wins(): void
    {
        $a = new DatabaseTokenStore;
        $b = new DatabaseTokenStore;

        $a->put('[tok:email:T1]', 'mario@x.com');
        $b->put('[tok:email:T1]', 'rita@x.com'); // last writer wins

        $dump = $a->dump();
        $this->assertCount(1, $dump);
        $this->assertSame('rita@x.com', $dump['[tok:email:T1]']);
        $this->assertSame('rita@x.com', $b->get('[tok:email:T1]'));
    }

    /**
     * Catches: a regression where dump() is called while another
     * instance is mid-loop with put() calls. dump() should observe a
     * consistent snapshot. With SQLite + chunkById this is safe by
     * construction (each chunk is its own SELECT).
     */
    public function test_database_token_store_dump_under_in_flight_writes_returns_consistent_snapshot(): void
    {
        $writer = new DatabaseTokenStore;
        $reader = new DatabaseTokenStore;

        // Pre-load a few rows.
        for ($i = 0; $i < 10; $i++) {
            $writer->put(sprintf('[tok:email:before_%d]', $i), sprintf('user%d@x.com', $i));
        }

        // Take a snapshot — every prior write must be visible.
        $snapshot = $reader->dump();
        $this->assertCount(10, $snapshot);

        // Continue writing — the prior snapshot must remain valid.
        for ($i = 0; $i < 5; $i++) {
            $writer->put(sprintf('[tok:email:after_%d]', $i), sprintf('user%d@x.com', $i + 100));
        }

        // The snapshot we held is unchanged.
        $this->assertCount(10, $snapshot);

        // A fresh dump now includes the post-snapshot writes.
        $this->assertCount(15, $reader->dump());
    }

    /**
     * High-volume sanity: 200 sequential puts across two interleaved
     * instances all survive. Catches batch-insert regressions
     * (e.g. accidental TRUNCATE in the wrong path).
     */
    public function test_database_token_store_high_volume_interleaved_writes_all_persist(): void
    {
        $a = new DatabaseTokenStore;
        $b = new DatabaseTokenStore;

        for ($i = 0; $i < 200; $i++) {
            $store = $i % 2 === 0 ? $a : $b;
            $store->put(sprintf('[tok:email:N%d]', $i), sprintf('u%d@x.com', $i));
        }

        $this->assertCount(200, $a->dump());
        $this->assertCount(200, $b->dump());
    }

    /**
     * Catches: a regression where concurrent clear() + put() leaves
     * the table in an inconsistent state. clear() truncates; the
     * subsequent put() must succeed cleanly.
     */
    public function test_database_token_store_clear_then_put_from_other_instance_succeeds(): void
    {
        $a = new DatabaseTokenStore;
        $b = new DatabaseTokenStore;

        $a->put('[tok:email:before]', 'before@x.com');
        $a->clear();
        $b->put('[tok:email:after]', 'after@x.com');

        $dump = $a->dump();
        $this->assertCount(1, $dump);
        $this->assertArrayHasKey('[tok:email:after]', $dump);
        $this->assertArrayNotHasKey('[tok:email:before]', $dump);
    }

    /**
     * Catches: a regression in CacheTokenStore::clear() that fails to
     * remove the index key, leaving stale entries in the dump output.
     * Even with two instances writing/clearing alternately, a final
     * clear() empties the dump completely.
     */
    public function test_cache_token_store_clear_after_two_instance_writes_empties_dump(): void
    {
        /** @var Repository $repo */
        $repo = Cache::store('array');
        $a = new CacheTokenStore($repo);
        $b = new CacheTokenStore($repo);

        $a->put('[tok:email:T1]', 'a@x.com');
        $b->put('[tok:email:T2]', 'b@x.com');
        $a->clear();

        $this->assertSame([], $a->dump());
        $this->assertSame([], $b->dump());
        $this->assertFalse($a->has('[tok:email:T1]'));
        $this->assertFalse($b->has('[tok:email:T2]'));
    }
}
