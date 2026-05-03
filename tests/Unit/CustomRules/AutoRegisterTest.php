<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\CustomRules;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;
use Padosoft\PiiRedactor\CustomRules\CustomRuleDetector;
use Padosoft\PiiRedactor\Exceptions\CustomRuleException;
use Padosoft\PiiRedactor\PiiRedactorServiceProvider;
use Padosoft\PiiRedactor\RedactorEngine;

/**
 * Verifies the v1.0 SP boot loop that auto-registers YAML custom-rule packs
 * listed under `pii-redactor.custom_rules.packs` when
 * `pii-redactor.custom_rules.auto_register` is true.
 *
 * Closes the deferred TODO from v0.3 — hosts no longer need to repeat the
 * `Pii::extend(new CustomRuleDetector(...))` boilerplate in their own
 * bootstrap when their packs are described declaratively in config.
 */
final class AutoRegisterTest extends TestCase
{
    private string $fixturePath;

    /**
     * @return array<int, class-string>
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
        $app['config']->set('pii-redactor.salt', 'test-salt-do-not-use-in-prod');
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturePath = dirname(__DIR__, 2).'/fixtures/custom-rules/it-albo.yaml';
    }

    public function test_auto_register_disabled_skips_packs_even_when_listed(): void
    {
        $this->app['config']->set('pii-redactor.custom_rules.auto_register', false);
        $this->app['config']->set('pii-redactor.custom_rules.packs', [
            ['name' => 'custom_it_albo', 'path' => $this->fixturePath],
        ]);

        // Re-trigger the SP boot pipeline so the auto-register loop runs
        // under the freshly-mutated config.
        $this->reboot();

        /** @var RedactorEngine $engine */
        $engine = $this->app->make(RedactorEngine::class);
        $this->assertArrayNotHasKey('custom_it_albo', $engine->detectors());
    }

    public function test_auto_register_enabled_registers_pack_and_detector_observes_match(): void
    {
        $this->app['config']->set('pii-redactor.custom_rules.auto_register', true);
        $this->app['config']->set('pii-redactor.custom_rules.packs', [
            ['name' => 'custom_it_albo', 'path' => $this->fixturePath],
        ]);

        $this->reboot();

        /** @var RedactorEngine $engine */
        $engine = $this->app->make(RedactorEngine::class);

        $this->assertArrayHasKey('custom_it_albo', $engine->detectors());
        $this->assertInstanceOf(CustomRuleDetector::class, $engine->detectors()['custom_it_albo']);

        // Round-trip — the registered detector actually scans + redacts the
        // YAML-defined pattern, proving the wiring is end-to-end correct.
        $input = 'Albo: ISCR-987654 — fine.';
        $redacted = $engine->redact($input);

        $this->assertNotSame($input, $redacted);
        $this->assertStringNotContainsString('ISCR-987654', $redacted);
    }

    public function test_missing_yaml_file_throws_custom_rule_exception_at_boot(): void
    {
        $this->app['config']->set('pii-redactor.custom_rules.auto_register', true);
        $this->app['config']->set('pii-redactor.custom_rules.packs', [
            ['name' => 'custom_missing', 'path' => '/tmp/this-file-does-not-exist-pii-test.yaml'],
        ]);

        $this->expectException(CustomRuleException::class);
        $this->expectExceptionMessage('not found or unreadable');

        $this->reboot();
    }

    public function test_pack_entry_missing_name_throws_custom_rule_exception(): void
    {
        $this->app['config']->set('pii-redactor.custom_rules.auto_register', true);
        $this->app['config']->set('pii-redactor.custom_rules.packs', [
            ['path' => $this->fixturePath],
        ]);

        $this->expectException(CustomRuleException::class);
        $this->expectExceptionMessage('requires a non-empty `name` and `path`');

        $this->reboot();
    }

    public function test_pack_entry_missing_path_throws_custom_rule_exception(): void
    {
        $this->app['config']->set('pii-redactor.custom_rules.auto_register', true);
        $this->app['config']->set('pii-redactor.custom_rules.packs', [
            ['name' => 'custom_no_path'],
        ]);

        $this->expectException(CustomRuleException::class);
        $this->expectExceptionMessage('requires a non-empty `name` and `path`');

        $this->reboot();
    }

    public function test_non_array_pack_entry_throws_custom_rule_exception(): void
    {
        $this->app['config']->set('pii-redactor.custom_rules.auto_register', true);
        $this->app['config']->set('pii-redactor.custom_rules.packs', [
            'not-an-array',
        ]);

        $this->expectException(CustomRuleException::class);
        $this->expectExceptionMessage('must be an array with `name` and `path` keys');

        $this->reboot();
    }

    public function test_empty_packs_list_with_auto_register_true_is_a_noop(): void
    {
        $this->app['config']->set('pii-redactor.custom_rules.auto_register', true);
        $this->app['config']->set('pii-redactor.custom_rules.packs', []);

        $this->reboot();

        /** @var RedactorEngine $engine */
        $engine = $this->app->make(RedactorEngine::class);

        // Default detectors are still registered; no custom packs added.
        $this->assertArrayHasKey('email', $engine->detectors());
        $this->assertArrayNotHasKey('custom_it_albo', $engine->detectors());
    }

    public function test_multiple_packs_are_all_registered(): void
    {
        $secondFixture = $this->writeTempPack();

        $this->app['config']->set('pii-redactor.custom_rules.auto_register', true);
        $this->app['config']->set('pii-redactor.custom_rules.packs', [
            ['name' => 'custom_it_albo', 'path' => $this->fixturePath],
            ['name' => 'custom_extra', 'path' => $secondFixture],
        ]);

        $this->reboot();

        try {
            /** @var RedactorEngine $engine */
            $engine = $this->app->make(RedactorEngine::class);

            $this->assertArrayHasKey('custom_it_albo', $engine->detectors());
            $this->assertArrayHasKey('custom_extra', $engine->detectors());
        } finally {
            if (is_file($secondFixture)) {
                @unlink($secondFixture);
            }
        }
    }

    /**
     * Forces the SP boot pipeline to re-run with the freshly-mutated config.
     * Testbench builds the app once per test — config mutations after that
     * point need an explicit forget + boot to take effect on the engine
     * singleton + the boot-time auto-register loop.
     */
    private function reboot(): void
    {
        $this->app->forgetInstance(RedactorEngine::class);
        $provider = new PiiRedactorServiceProvider($this->app);
        $provider->boot();
    }

    private function writeTempPack(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pii-pack-').'.yaml';
        file_put_contents(
            $path,
            "rules:\n  - name: extra_id\n    pattern: 'EXTRA-\\d{3,}'\n    flags: u\n",
        );

        return $path;
    }
}
