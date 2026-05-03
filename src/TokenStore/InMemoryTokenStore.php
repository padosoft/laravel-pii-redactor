<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\TokenStore;

/**
 * Default array-backed driver. Zero I/O, zero dependencies.
 *
 * Behaves identically to the v0.1 in-class `$tokenToOriginal` map so
 * existing callers see no change when they construct a TokeniseStrategy
 * without an explicit store.
 *
 * Process-local: a restart discards every mapping. Use the
 * DatabaseTokenStore (or a future cache-backed driver) when token
 * survival across deploys is required.
 */
final class InMemoryTokenStore implements TokenStore
{
    /**
     * @var array<string, string> token => original
     */
    private array $map = [];

    public function put(string $token, string $original): void
    {
        // Re-applying the same input always yields the same token, so
        // overwriting an existing entry with the identical original is
        // a no-op semantically. We still assign rather than guard so
        // any divergent original (which would be a programming error
        // upstream) surfaces during tests rather than silently sticks
        // with the first writer.
        $this->map[$token] = $original;
    }

    public function get(string $token): ?string
    {
        return $this->map[$token] ?? null;
    }

    public function has(string $token): bool
    {
        return isset($this->map[$token]);
    }

    public function clear(): void
    {
        $this->map = [];
    }

    /**
     * @return array<string, string>
     */
    public function dump(): array
    {
        return $this->map;
    }

    /**
     * @param  array<string, string>  $map
     */
    public function load(array $map): void
    {
        $this->map = $map;
    }
}
