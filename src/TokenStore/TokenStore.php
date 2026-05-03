<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\TokenStore;

/**
 * Persistence contract for the reversible TokeniseStrategy.
 *
 * v0.1 of the package kept the token → original mapping inside the
 * strategy instance. v0.2 introduces this interface so the same map
 * can be backed by an in-memory array (default), an Eloquent table,
 * a cache, or any future driver — without changing the strategy
 * surface area.
 *
 * The interface is intentionally narrow: it speaks of literal
 * `[tok:detector:hex]` tokens and their original PII values, nothing
 * else. Drivers SHOULD treat `put()` as idempotent on duplicate token
 * (re-applying the same input yields the same token, so re-storing
 * the same pair is normal — never a unique-constraint failure that
 * propagates to the caller).
 */
interface TokenStore
{
    /**
     * Persist a token → original mapping. Idempotent on duplicate token.
     */
    public function put(string $token, string $original): void;

    /**
     * Retrieve the original; null if unknown.
     */
    public function get(string $token): ?string;

    public function has(string $token): bool;

    /**
     * Remove all entries — used by tests and operator-driven rotations.
     */
    public function clear(): void;

    /**
     * @return array<string, string> token => original
     */
    public function dump(): array;

    /**
     * @param  array<string, string>  $map
     */
    public function load(array $map): void;
}
