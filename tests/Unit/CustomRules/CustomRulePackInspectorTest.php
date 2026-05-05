<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\CustomRules;

use Padosoft\PiiRedactor\CustomRules\CustomRulePackInspector;
use Padosoft\PiiRedactor\Tests\TestCase;

final class CustomRulePackInspectorTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        parent::tearDown();
    }

    public function test_reports_valid_pack_rule_count(): void
    {
        $path = $this->writeTempYaml("rules:\n  - name: custom_id\n    pattern: 'CID-\\d+'\n");
        $this->app['config']->set('pii-redactor.custom_rules.packs', [
            ['name' => 'tenant_rules', 'path' => $path],
        ]);

        $packs = (new CustomRulePackInspector($this->app['config']))->configuredPacks();

        $this->assertCount(1, $packs);
        $this->assertSame('tenant_rules', $packs[0]['name']);
        $this->assertTrue($packs[0]['exists']);
        $this->assertTrue($packs[0]['readable']);
        $this->assertTrue($packs[0]['valid']);
        $this->assertSame(1, $packs[0]['rule_count']);
        $this->assertNull($packs[0]['error']);
    }

    public function test_reports_missing_file_without_throwing(): void
    {
        $this->app['config']->set('pii-redactor.custom_rules.packs', [
            ['name' => 'missing', 'path' => sys_get_temp_dir().'/missing-pii-rules.yaml'],
        ]);

        $packs = (new CustomRulePackInspector($this->app['config']))->configuredPacks();

        $this->assertFalse($packs[0]['exists']);
        $this->assertFalse($packs[0]['readable']);
        $this->assertFalse($packs[0]['valid']);
        $this->assertIsString($packs[0]['error']);
    }

    public function test_reports_malformed_yaml_without_throwing(): void
    {
        $path = $this->writeTempYaml("rules:\n  - name: bad\n    pattern: 'unterminated\n");
        $this->app['config']->set('pii-redactor.custom_rules.packs', [
            ['name' => 'bad', 'path' => $path],
        ]);

        $packs = (new CustomRulePackInspector($this->app['config']))->configuredPacks();

        $this->assertFalse($packs[0]['valid']);
        $this->assertStringContainsString('malformed', (string) $packs[0]['error']);
    }

    public function test_empty_config_returns_empty_list(): void
    {
        $this->app['config']->set('pii-redactor.custom_rules.packs', []);

        $this->assertSame([], (new CustomRulePackInspector($this->app['config']))->configuredPacks());
    }

    private function writeTempYaml(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pii-rule-').'.yaml';
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
