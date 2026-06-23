---
title: Token Stores
description: Reversible token map storage.
---

# Token Stores

`TokeniseStrategy` writes a reversible map from `[tok:detector:id]` to the original value. That map is sensitive and must be protected like the source PII.

| Driver | Persistence | Typical use |
| --- | --- | --- |
| `memory` | Process-local | Tests, short scripts, previews |
| `database` | Eloquent table | Cross-worker detokenisation |
| `cache` | Laravel cache repository | Redis-backed operational stores |

::: steps
1. **Choose tokenise**
   Set `PII_REDACTOR_STRATEGY=tokenise`.
2. **Set a salt**
   Set `PII_REDACTOR_SALT` from secret storage.
3. **Choose a persistent store**
   Set `PII_REDACTOR_TOKEN_STORE=database` or `cache`.
4. **Publish migration if using database**
   Run `php artisan vendor:publish --tag=pii-redactor-migrations && php artisan migrate`.
:::

## Tenant isolation (v1.4)

The reversible vault is **tenant-aware on every driver** — `database` (rows
scoped by `tenant_id`, `UNIQUE(tenant_id, token)`), `memory` (per-tenant
buckets), and `cache` (per-tenant key namespace). `TokeniseStrategy` also
namespaces its salt per tenant, so the **same PII value yields a different
token per tenant** and a token can only ever be detokenised within its own
tenant — including via `detokeniseString()`, which never resolves a foreign
tenant's literal. Cross-tenant reverse-identification is a GDPR catastrophe;
this boundary makes a single shared store safe across tenants.

- **Single-tenant hosts** notice nothing: the bundled `DefaultTenantResolver`
  uses one constant id (`pii-redactor.tenant.default_id`, env
  `PII_REDACTOR_DEFAULT_TENANT_ID`, default `default`). The **legacy/default
  tenant keeps the pre-v1.4 representation** — bare salt + unsegmented cache
  keys — so an upgrade mints byte-for-byte the same `[tok:...]` ids and finds
  its existing cache/DB rows. Only non-default tenants get a namespaced salt +
  segmented keys.
- **Multi-tenant hosts** bind their own resolver so the vault follows the
  request's tenant:

  ```php
  use Padosoft\PiiRedactor\Contracts\TenantResolver;

  $this->app->bind(TenantResolver::class, fn () => new class implements TenantResolver {
      public function currentTenantId(): string
      {
          return app(\App\Support\TenantContext::class)->current();
      }
  });
  ```

  The salt is resolved **per `apply()` call**, so a singleton strategy stays
  correct even when one queue worker processes jobs for several tenants.
- **Upgrade:** run the `add_tenant_id_to_pii_token_maps_table` migration — it
  adds `tenant_id` (existing rows backfill to `default`) and replaces
  `UNIQUE(token)` with `UNIQUE(tenant_id, token)`.
- **Scoping:** `clear()` wipes only the active tenant's vault (never global);
  `dump()` and `load()` are likewise scoped to the active tenant.
