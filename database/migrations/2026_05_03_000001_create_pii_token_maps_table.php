<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persistent storage for the reversible TokeniseStrategy map.
 *
 * Stores `[tok:<detector>:<hex>]` literals against their original PII
 * values so the reverse map survives deploys + queue worker restarts +
 * horizontal scale-out. The `original` column is plaintext by design:
 * encryption-at-rest, row-level security, and access control are the
 * host's responsibility (see SECURITY.md).
 *
 * The `detector` column is denormalised out of the token literal so
 * operators can scope dumps / rotations to a single detector type
 * without parsing the token string in SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pii_token_maps', function (Blueprint $table): void {
            $table->id();
            $table->string('token', 255)->unique();
            $table->text('original');
            $table->string('detector', 64);
            $table->timestamp('created_at')->useCurrent();
            $table->index('detector');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pii_token_maps');
    }
};
