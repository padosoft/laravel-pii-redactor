<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\TokenStore;

use Padosoft\PiiRedactor\Contracts\TenantResolver;

/**
 * Default array-backed driver. Zero I/O, zero dependencies.
 *
 * Behaves identically to the v0.1 in-class `$tokenToOriginal` map when no
 * {@see TenantResolver} is wired (single bucket), so existing callers see no
 * change when they construct a TokeniseStrategy without an explicit store.
 *
 * When a resolver IS wired (multi-tenant), the map is partitioned per tenant
 * so a token minted while tenant A is active can never be read, dumped, or
 * cleared while tenant B is active — the same tenant-isolation guarantee the
 * database driver gives, but for the process-local map. Without this a
 * long-lived multi-tenant worker could leak tenant A's PII when tenant B
 * detokenises the same literal.
 *
 * Process-local: a restart discards every mapping. Use the
 * DatabaseTokenStore (or the cache driver) when token survival across
 * deploys is required.
 */
final class InMemoryTokenStore implements TokenStore
{
    /** Bucket key used when no resolver is wired (pre-tenancy behaviour). */
    private const SINGLE = "\0single\0";

    private readonly ?TenantResolver $tenants;

    /**
     * @var array<string, array<string, string>> tenantId => (token => original)
     */
    private array $byTenant = [];

    public function __construct(?TenantResolver $tenants = null)
    {
        $this->tenants = $tenants;
    }

    private function bucketKey(): string
    {
        return $this->tenants?->currentTenantId() ?? self::SINGLE;
    }

    public function put(string $token, string $original): void
    {
        // Re-applying the same input always yields the same token, so
        // overwriting an existing entry with the identical original is
        // a no-op semantically. We still assign rather than guard so
        // any divergent original (a programming error upstream) surfaces
        // during tests rather than silently sticking with the first writer.
        $this->byTenant[$this->bucketKey()][$token] = $original;
    }

    public function get(string $token): ?string
    {
        return $this->byTenant[$this->bucketKey()][$token] ?? null;
    }

    public function has(string $token): bool
    {
        return isset($this->byTenant[$this->bucketKey()][$token]);
    }

    public function clear(): void
    {
        // Tenant-scoped: clears ONLY the active tenant's bucket.
        unset($this->byTenant[$this->bucketKey()]);
    }

    /**
     * @return array<string, string>
     */
    public function dump(): array
    {
        return $this->byTenant[$this->bucketKey()] ?? [];
    }

    /**
     * @param  array<string, string>  $map
     */
    public function load(array $map): void
    {
        $this->byTenant[$this->bucketKey()] = $map;
    }
}
