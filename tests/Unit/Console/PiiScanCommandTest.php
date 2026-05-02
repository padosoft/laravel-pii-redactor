<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Console;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;
use Padosoft\PiiRedactor\PiiRedactorServiceProvider;

final class PiiScanCommandTest extends TestCase
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PiiRedactorServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('pii-redactor.strategy', 'mask');
    }

    public function test_scan_outputs_json_report_for_a_file(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pii-scan-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'Email: mario.rossi@example.com.');

        $this->artisan('pii:scan', ['path' => $tmp])
            ->expectsOutputToContain('"email":1')
            ->assertExitCode(0);

        @unlink($tmp);
    }

    public function test_scan_masks_samples_by_default(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pii-scan-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'Email: mario.rossi@example.com.');

        // The raw email value MUST NOT appear in the default output —
        // running pii:scan in CI must not leak customer data into logs.
        $this->artisan('pii:scan', ['path' => $tmp])
            ->doesntExpectOutputToContain('mario.rossi@example.com')
            ->expectsOutputToContain('"[email]"')
            ->assertExitCode(0);

        @unlink($tmp);
    }

    public function test_scan_emits_raw_samples_with_show_samples_flag(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pii-scan-');
        $this->assertNotFalse($tmp);
        file_put_contents($tmp, 'Email: mario.rossi@example.com.');

        $this->artisan('pii:scan', ['path' => $tmp, '--show-samples' => true])
            ->expectsOutputToContain('mario.rossi@example.com')
            ->assertExitCode(0);

        @unlink($tmp);
    }

    public function test_scan_returns_non_zero_when_file_missing(): void
    {
        $this->artisan('pii:scan', ['path' => '/nonexistent/path/file.txt'])
            ->assertExitCode(2);
    }

    public function test_scan_rejects_conflicting_arguments(): void
    {
        $this->artisan('pii:scan', ['path' => 'x', '--from' => 'stdin'])
            ->assertExitCode(1);
    }

    public function test_scan_returns_code_1_when_no_arguments_provided(): void
    {
        $this->artisan('pii:scan')
            ->assertExitCode(1);
    }
}
