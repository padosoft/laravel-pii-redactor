<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1.4 — make the reversible token vault TENANT-AWARE.
 *
 * Adds `tenant_id` and replaces the global `UNIQUE(token)` with a
 * per-tenant `UNIQUE(tenant_id, token)` so the SAME token literal can
 * exist independently in two tenants' vaults (each minted under a
 * per-tenant salt) and a token can only ever be resolved within its own
 * tenant. Existing rows backfill to the `'default'` tenant, preserving
 * single-tenant deployments byte-for-byte.
 *
 * Idempotent + driver-tolerant: guarded by column existence so re-runs
 * and fresh installs (where the create migration may already include the
 * column in a future consolidation) are safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pii_token_maps')) {
            return;
        }

        // Backfill existing rows under the CONFIGURED legacy tenant id — not a
        // hard-coded 'default' — so that when a host runs with
        // PII_REDACTOR_DEFAULT_TENANT_ID set to something else, the store
        // (which queries by that configured id) still finds its pre-v1.4 rows.
        // Explicit null/'' check (not `?:`) so the valid tenant id "0" is
        // preserved rather than falsy-coerced to 'default'.
        $rawLegacy = config('pii-redactor.tenant.default_id');
        $legacyTenantId = is_string($rawLegacy) && $rawLegacy !== '' ? $rawLegacy : 'default';

        if (! Schema::hasColumn('pii_token_maps', 'tenant_id')) {
            Schema::table('pii_token_maps', function (Blueprint $table) use ($legacyTenantId): void {
                $table->string('tenant_id', 64)->default($legacyTenantId)->after('id')->index();
            });
        }

        // Swap the global unique for the per-tenant composite unique. Guard with
        // schema INTROSPECTION (not a try/catch) — Blueprint only QUEUES the DDL
        // inside the closure; Schema::table() runs it AFTER the closure returns,
        // so a try/catch around the queue call never catches the execution-time
        // error. hasIndex() checks make this idempotent on the manual /
        // consolidated-schema path where the old index is absent or renamed.
        if (Schema::hasIndex('pii_token_maps', 'pii_token_maps_token_unique')) {
            Schema::table('pii_token_maps', function (Blueprint $table): void {
                $table->dropUnique('pii_token_maps_token_unique');
            });
        }

        if (! Schema::hasIndex('pii_token_maps', 'pii_token_maps_tenant_token_unique')) {
            Schema::table('pii_token_maps', function (Blueprint $table): void {
                $table->unique(['tenant_id', 'token'], 'pii_token_maps_tenant_token_unique');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('pii_token_maps')) {
            return;
        }

        if (Schema::hasIndex('pii_token_maps', 'pii_token_maps_tenant_token_unique')) {
            Schema::table('pii_token_maps', function (Blueprint $table): void {
                $table->dropUnique('pii_token_maps_tenant_token_unique');
            });
        }

        if (! Schema::hasIndex('pii_token_maps', 'pii_token_maps_token_unique')) {
            Schema::table('pii_token_maps', function (Blueprint $table): void {
                $table->unique('token', 'pii_token_maps_token_unique');
            });
        }

        if (Schema::hasColumn('pii_token_maps', 'tenant_id')) {
            Schema::table('pii_token_maps', function (Blueprint $table): void {
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }
    }
};
