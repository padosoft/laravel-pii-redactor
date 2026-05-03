<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\CustomRules;

use Padosoft\PiiRedactor\CustomRules\CustomRuleSet;
use Padosoft\PiiRedactor\CustomRules\YamlCustomRuleLoader;
use Padosoft\PiiRedactor\Exceptions\CustomRuleException;
use PHPUnit\Framework\TestCase;

final class YamlCustomRuleLoaderTest extends TestCase
{
    private string $fixtureRoot;

    /**
     * @var list<string>
     */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = dirname(__DIR__, 2).'/fixtures/custom-rules';
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];
        parent::tearDown();
    }

    public function test_loads_valid_fixture_returns_two_rules(): void
    {
        $loader = new YamlCustomRuleLoader;
        $set = $loader->load($this->fixtureRoot.'/it-albo.yaml');

        $this->assertInstanceOf(CustomRuleSet::class, $set);
        $this->assertSame(2, $set->count());
        $this->assertFalse($set->isEmpty());

        $this->assertSame('iscrizione_albo', $set->rules[0]->name);
        $this->assertSame('ISCR-\d{6,}', $set->rules[0]->pattern);
        $this->assertSame('u', $set->rules[0]->flags);

        $this->assertSame('tessera_ordine', $set->rules[1]->name);
    }

    public function test_missing_file_throws_custom_rule_exception(): void
    {
        $loader = new YamlCustomRuleLoader;

        $this->expectException(CustomRuleException::class);
        $this->expectExceptionMessage('Custom rule YAML file not found or unreadable');

        $loader->load($this->fixtureRoot.'/does-not-exist.yaml');
    }

    public function test_malformed_yaml_throws_custom_rule_exception(): void
    {
        $path = $this->writeTempYaml("rules:\n  - name: bad\n    pattern: 'unterminated\n");

        $loader = new YamlCustomRuleLoader;

        $this->expectException(CustomRuleException::class);
        $this->expectExceptionMessage('is malformed');

        $loader->load($path);
    }

    public function test_empty_yaml_yields_empty_set(): void
    {
        $path = $this->writeTempYaml('');

        $loader = new YamlCustomRuleLoader;
        $set = $loader->load($path);

        $this->assertTrue($set->isEmpty());
        $this->assertSame(0, $set->count());
        $this->assertSame([], $set->rules);
    }

    public function test_yaml_without_rules_key_yields_empty_set(): void
    {
        $path = $this->writeTempYaml("metadata:\n  description: pack with no rules\n");

        $loader = new YamlCustomRuleLoader;
        $set = $loader->load($path);

        $this->assertTrue($set->isEmpty());
    }

    public function test_rules_section_must_be_a_list(): void
    {
        $path = $this->writeTempYaml("rules: 'not a list'\n");

        $loader = new YamlCustomRuleLoader;

        $this->expectException(CustomRuleException::class);
        $this->expectExceptionMessage('invalid `rules` section');

        $loader->load($path);
    }

    public function test_rule_missing_name_field_throws(): void
    {
        $path = $this->writeTempYaml("rules:\n  - pattern: '\\d+'\n");

        $loader = new YamlCustomRuleLoader;

        $this->expectException(CustomRuleException::class);
        $this->expectExceptionMessage('missing a non-empty `name` field');

        $loader->load($path);
    }

    public function test_rule_missing_pattern_field_throws(): void
    {
        $path = $this->writeTempYaml("rules:\n  - name: only_name\n");

        $loader = new YamlCustomRuleLoader;

        $this->expectException(CustomRuleException::class);
        $this->expectExceptionMessage('missing a non-empty `pattern` field');

        $loader->load($path);
    }

    public function test_rule_with_invalid_pcre_pattern_loads_but_compile_throws(): void
    {
        // Invalid regex (unclosed group) — load() succeeds, compiledPattern() throws.
        $path = $this->writeTempYaml("rules:\n  - name: broken\n    pattern: '(unclosed'\n");

        $loader = new YamlCustomRuleLoader;
        $set = $loader->load($path);

        $this->assertSame(1, $set->count());

        $this->expectException(CustomRuleException::class);
        $this->expectExceptionMessage('invalid PCRE pattern');

        $set->rules[0]->compiledPattern();
    }

    private function writeTempYaml(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pii-rule-').'.yaml';
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }
}
