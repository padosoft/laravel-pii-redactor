<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\TokenStore;

use Illuminate\Contracts\Cache\Repository;

/**
 * Cache-backed TokenStore — uses Laravel's cache abstraction so deployments
 * can swap between Redis / Memcached / DynamoDB / array (test) drivers
 * without touching the package code.
 *
 * Use this driver when:
 * - You want token persistence across requests / queue workers.
 * - You DON'T need permanent retention (cache TTL is honored).
 * - You want simpler operational surface than DatabaseTokenStore (no
 *   migration to run, no row-level encryption to plan).
 *
 * Caveats:
 * - The store maintains an `index` cache key listing every token so that
 *   `dump()` and `clear()` work without scanning the entire backend
 *   keyspace. The index is updated on every write — keep this in mind
 *   when choosing a TTL: when an individual token entry expires, the
 *   index will still list it until clear() or load() rebuilds the index.
 *   `get()` handles missing entries gracefully (returns null) so a stale
 *   index is not a correctness bug, just a minor `dump()` over-report.
 * - For workloads where TTL expiry is frequent, prefer DatabaseTokenStore
 *   (no expiry by default) or a TTL-less Redis namespace.
 * - **Concurrent writes**: `addToIndex()` performs a non-atomic
 *   read-check-write sequence. Under concurrent `put()` calls from
 *   multiple workers, one worker's index write can overwrite another's,
 *   causing a token to be silently dropped from the index. `get()` and
 *   `has()` remain correct (they address the token key directly); only
 *   `dump()` and `clear()` can be incomplete. For high-concurrency
 *   workloads that rely on `dump()` completeness, prefer
 *   DatabaseTokenStore or a dedicated Redis SADD-backed index.
 *
 * Memory hygiene (CLAUDE.md R3): `dump()` reads the index then issues
 * one `get()` per indexed token. The index itself can grow unbounded if
 * the host never calls `clear()`; consumers should monitor the index
 * size and run periodic rotations.
 */
final class CacheTokenStore implements TokenStore
{
    public function __construct(
        private readonly Repository $cache,
        private readonly string $prefix = 'pii_token:',
        private readonly ?int $ttlSeconds = null,
    ) {}

    public function put(string $token, string $original): void
    {
        $key = $this->keyFor($token);
        if ($this->ttlSeconds !== null && $this->ttlSeconds > 0) {
            $this->cache->put($key, $original, $this->ttlSeconds);
        } else {
            $this->cache->forever($key, $original);
        }
        $this->addToIndex($token);
    }

    public function get(string $token): ?string
    {
        $value = $this->cache->get($this->keyFor($token));

        return is_string($value) ? $value : null;
    }

    public function has(string $token): bool
    {
        return $this->cache->has($this->keyFor($token));
    }

    public function clear(): void
    {
        foreach ($this->index() as $token) {
            $this->cache->forget($this->keyFor($token));
        }
        $this->cache->forget($this->indexKey());
    }

    /**
     * @return array<string, string>
     */
    public function dump(): array
    {
        $out = [];
        foreach ($this->index() as $token) {
            $original = $this->cache->get($this->keyFor($token));
            if (is_string($original)) {
                $out[$token] = $original;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $map
     */
    public function load(array $map): void
    {
        $this->clear();

        foreach ($map as $token => $original) {
            $this->put((string) $token, (string) $original);
        }
    }

    private function keyFor(string $token): string
    {
        return $this->prefix.hash('sha256', $token);
    }

    private function indexKey(): string
    {
        return $this->prefix.'__index';
    }

    /** @return list<string> */
    private function index(): array
    {
        $idx = $this->cache->get($this->indexKey());

        return is_array($idx) ? array_values(array_filter($idx, 'is_string')) : [];
    }

    private function addToIndex(string $token): void
    {
        $idx = $this->index();
        if (! in_array($token, $idx, true)) {
            $idx[] = $token;
        }

        // Re-persist the index key on EVERY put() — even for already-
        // indexed tokens — so the index TTL stays in lockstep with the
        // most-recently-refreshed token entry. Without this refresh, a
        // long-running cache (TTL 1h) where the same token is re-applied
        // every 30 minutes would still see the index key expire after
        // the original 1h while the token entry keeps getting refreshed.
        // dump() / clear() would then stop seeing live tokens.
        if ($this->ttlSeconds !== null && $this->ttlSeconds > 0) {
            $this->cache->put($this->indexKey(), $idx, $this->ttlSeconds);
        } else {
            $this->cache->forever($this->indexKey(), $idx);
        }
    }
}
