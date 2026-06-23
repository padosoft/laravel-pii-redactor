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

        if (! Schema::hasColumn('pii_token_maps', 'tenant_id')) {
            Schema::table('pii_token_maps', function (Blueprint $table): void {
                $table->string('tenant_id', 64)->default('default')->after('id')->index();
            });
        }

        // Swap the global unique for the per-tenant composite unique. The old
        // index was auto-named `pii_token_maps_token_unique` by the v1.3
        // create migration. Wrap the drop so a host that already lacks it
        // (manual edit / fresh consolidated schema) does not fail the upgrade.
        Schema::table('pii_token_maps', function (Blueprint $table): void {
            try {
                $table->dropUnique('pii_token_maps_token_unique');
            } catch (\Throwable) {
                // Already dropped or never existed under that name — fine.
            }
        });

        Schema::table('pii_token_maps', function (Blueprint $table): void {
            $table->unique(['tenant_id', 'token'], 'pii_token_maps_tenant_token_unique');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pii_token_maps')) {
            return;
        }

        Schema::table('pii_token_maps', function (Blueprint $table): void {
            try {
                $table->dropUnique('pii_token_maps_tenant_token_unique');
            } catch (\Throwable) {
            }
        });

        Schema::table('pii_token_maps', function (Blueprint $table): void {
            $table->unique('token', 'pii_token_maps_token_unique');
        });

        if (Schema::hasColumn('pii_token_maps', 'tenant_id')) {
            Schema::table('pii_token_maps', function (Blueprint $table): void {
                $table->dropIndex(['tenant_id']);
                $table->dropColumn('tenant_id');
            });
        }
    }
};
