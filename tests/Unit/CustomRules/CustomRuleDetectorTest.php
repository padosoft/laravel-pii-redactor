<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\CustomRules;

use Padosoft\PiiRedactor\CustomRules\CustomRule;
use Padosoft\PiiRedactor\CustomRules\CustomRuleDetector;
use Padosoft\PiiRedactor\CustomRules\CustomRuleSet;
use Padosoft\PiiRedactor\Exceptions\CustomRuleException;
use Padosoft\PiiRedactor\Facades\Pii;
use Padosoft\PiiRedactor\RedactorEngine;
use Padosoft\PiiRedactor\Strategies\MaskStrategy;
use Padosoft\PiiRedactor\Tests\TestCase;

final class CustomRuleDetectorTest extends TestCase
{
    public function test_name_returns_pack_name(): void
    {
        $detector = new CustomRuleDetector(
            'custom_it_albo',
            new CustomRuleSet([new CustomRule('iscrizione_albo', 'ISCR-\d{6,}')]),
        );

        $this->assertSame('custom_it_albo', $detector->name());
    }

    public function test_constructor_rejects_empty_pack_name(): void
    {
        $this->expectException(CustomRuleException::class);
        $this->expectExceptionMessage('packName must not be empty');

        new CustomRuleDetector('', new CustomRuleSet([]));
    }

    public function test_empty_text_returns_empty(): void
    {
        $detector = new CustomRuleDetector(
            'custom',
            new CustomRuleSet([new CustomRule('iscr', 'ISCR-\d{6,}')]),
        );

        $this->assertSame([], $detector->detect(''));
    }

    public function test_empty_rule_set_returns_empty(): void
    {
        $detector = new CustomRuleDetector('custom_empty', new CustomRuleSet([]));

        $this->assertSame([], $detector->detect('ISCR-123456 is here'));
    }

    public function test_single_rule_match_returns_offset_and_length(): void
    {
        $detector = new CustomRuleDetector(
            'custom_it_albo',
            new CustomRuleSet([new CustomRule('iscrizione_albo', 'ISCR-\d{6,}')]),
        );

        $text = 'Iscrizione: ISCR-123456 (verified).';
        $hits = $detector->detect($text);

        $this->assertCount(1, $hits);
        $this->assertSame('custom_it_albo', $hits[0]->detector);
        $this->assertSame('ISCR-123456', $hits[0]->value);
        $this->assertSame(12, $hits[0]->offset);
        $this->assertSame(11, $hits[0]->length);
        $this->assertSame('ISCR-123456', substr($text, $hits[0]->offset, $hits[0]->length));
    }

    public function test_multiple_rules_emit_one_detection_per_match(): void
    {
        $detector = new CustomRuleDetector(
            'custom_it_albo',
            new CustomRuleSet([
                new CustomRule('iscrizione_albo', 'ISCR-\d{6,}'),
                new CustomRule('tessera_ordine', '\bTess-[A-Z]{2}-\d{4,8}\b'),
            ]),
        );

        $text = 'Member ISCR-987654 with card Tess-IT-12345.';
        $hits = $detector->detect($text);

        $this->assertCount(2, $hits);
        $values = array_map(static fn ($d) => $d->value, $hits);
        $this->assertContains('ISCR-987654', $values);
        $this->assertContains('Tess-IT-12345', $values);
        foreach ($hits as $hit) {
            $this->assertSame('custom_it_albo', $hit->detector);
        }
    }

    public function test_multiple_matches_of_same_rule_each_emitted(): void
    {
        $detector = new CustomRuleDetector(
            'custom_it_albo',
            new CustomRuleSet([new CustomRule('iscrizione_albo', 'ISCR-\d{6,}')]),
        );

        $text = 'Two members: ISCR-111111 and ISCR-222222.';
        $hits = $detector->detect($text);

        $this->assertCount(2, $hits);
        $this->assertSame('ISCR-111111', $hits[0]->value);
        $this->assertSame('ISCR-222222', $hits[1]->value);
        $this->assertGreaterThan($hits[0]->offset, $hits[1]->offset);
    }

    public function test_integrates_with_redactor_engine_via_facade_extend(): void
    {
        $detector = new CustomRuleDetector(
            'custom_it_albo',
            new CustomRuleSet([
                new CustomRule('iscrizione_albo', 'ISCR-\d{6,}'),
                new CustomRule('tessera_ordine', '\bTess-[A-Z]{2}-\d{4,8}\b'),
            ]),
        );

        Pii::extend('custom_it_albo', $detector);

        $redacted = Pii::redact('Iscrizione ISCR-987654 con tessera Tess-IT-12345 valida.');

        $this->assertStringNotContainsString('ISCR-987654', $redacted);
        $this->assertStringNotContainsString('Tess-IT-12345', $redacted);
        $this->assertStringContainsString('[REDACTED]', $redacted);
    }

    public function test_integrates_directly_with_engine_instance(): void
    {
        $engine = new RedactorEngine(new MaskStrategy('[X]'));
        $engine->register(new CustomRuleDetector(
            'custom_it_albo',
            new CustomRuleSet([new CustomRule('iscrizione_albo', 'ISCR-\d{6,}')]),
        ));

        $report = $engine->scan('Member ISCR-654321 active.');

        $this->assertSame(1, $report->total());
        $this->assertSame('[X]', '[X]'); // sanity guard
        $this->assertSame('Member [X] active.', $engine->redact('Member ISCR-654321 active.'));
    }
}
