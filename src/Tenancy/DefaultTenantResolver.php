<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tenancy;

use Padosoft\PiiRedactor\Contracts\TenantResolver;

/**
 * Single-tenant default: always returns the configured default id
 * (`pii-redactor.tenant.default_id`, falling back to `'default'`).
 *
 * This keeps the package's behaviour identical to the pre-tenancy
 * releases for every non-multi-tenant host — the token vault simply
 * lives under one constant tenant id.
 */
final class DefaultTenantResolver implements TenantResolver
{
    public function __construct(private readonly string $tenantId = 'default')
    {
    }

    public function currentTenantId(): string
    {
        return $this->tenantId !== '' ? $this->tenantId : 'default';
    }
}
