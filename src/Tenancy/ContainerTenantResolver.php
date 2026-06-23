<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tenancy;

use Illuminate\Contracts\Container\Container;
use Padosoft\PiiRedactor\Contracts\TenantResolver;

/**
 * A stable proxy that resolves the host's bound {@see TenantResolver} from the
 * container ON EVERY CALL.
 *
 * The token store and strategy are SINGLETONS, so if they captured a concrete
 * resolver instance at build time they would freeze it — a host that binds its
 * resolver as `scoped()` (a fresh instance per request/job) would then keep
 * minting/reading tokens against the FIRST request's tenant. This proxy is the
 * stable singleton the store/strategy hold; each `currentTenantId()` re-resolves
 * the currently-bound resolver, so per-request/scoped bindings work correctly.
 *
 * It is never itself bound as `TenantResolver::class`, so resolving `$abstract`
 * returns the host's (or the bundled default) resolver — no recursion.
 */
final class ContainerTenantResolver implements TenantResolver
{
    /**
     * @param  class-string<TenantResolver>  $abstract
     */
    public function __construct(
        private readonly Container $app,
        private readonly string $abstract = TenantResolver::class,
    ) {}

    public function currentTenantId(): string
    {
        $resolver = $this->app->make($this->abstract);

        // Defensive: never recurse if the host (mis)bound the abstract to this
        // very proxy — fall back to the bundled default's constant id.
        if ($resolver instanceof self) {
            return (new DefaultTenantResolver(
                (string) ($this->app->make('config')->get('pii-redactor.tenant.default_id') ?: 'default'),
            ))->currentTenantId();
        }

        return $resolver->currentTenantId();
    }
}
