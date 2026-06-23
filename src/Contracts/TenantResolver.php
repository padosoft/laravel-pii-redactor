<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Contracts;

/**
 * Resolves the active tenant for the reversible token vault.
 *
 * The package is tenant-agnostic by default (single-tenant hosts never
 * notice): the bundled {@see \Padosoft\PiiRedactor\Tenancy\DefaultTenantResolver}
 * always returns the configured default id. A multi-tenant host binds its
 * own implementation (e.g. over its TenantContext) so the token vault is
 * isolated per tenant — the SAME PII value in two tenants yields two
 * DIFFERENT tokens (per-tenant salt) and a token minted for tenant A can
 * never be detokenised while tenant B is active (`UNIQUE(tenant_id, token)`
 * + tenant-scoped reads).
 *
 * Cross-tenant leakage of a reverse-identification map is a GDPR
 * catastrophe, so this boundary is the package's single source of truth
 * for "whose vault am I touching right now".
 */
interface TenantResolver
{
    /**
     * The active tenant id. MUST be a stable, non-empty string; hosts that
     * are not multi-tenant return a constant (the configured default).
     */
    public function currentTenantId(): string;
}
