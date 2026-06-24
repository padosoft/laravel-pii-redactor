<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\TokenStore;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;
use Padosoft\PiiRedactor\PiiRedactorServiceProvider;

/**
 * v1.4 — when the host runs with a non-`default` legacy tenant id, the
 * migration must backfill pre-v1.4 rows under THAT id (the column default is
 * config-driven) so the tenant-scoped store still finds them after upgrade.
 */
final class DatabaseTokenStoreLegacyTenantTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, class-string<ServiceProvider>>
     */
    protected function getPackageProviders($app): array
    {
        return [PiiRedactorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        // Non-default legacy tenant — the migration must use this for the
        // backfill / column default, not a hard-coded 'default'.
        $app['config']->set('pii-redactor.tenant.default_id', 'acme');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
    }

    public function test_column_default_uses_the_configured_legacy_tenant(): void
    {
        // A raw insert WITHOUT tenant_id (simulating a backfilled pre-v1.4 row)
        // takes the column default — which must be the configured legacy id.
        DB::table('pii_token_maps')->insert([
            'token' => '[tok:email:legacy]',
            'original' => 'legacy@example.com',
            'detector' => 'email',
        ]);

        $row = DB::table('pii_token_maps')->where('token', '[tok:email:legacy]')->first();

        $this->assertNotNull($row);
        $this->assertSame('acme', $row->tenant_id);
    }
}
