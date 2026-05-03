<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Robustness;

use Padosoft\PiiRedactor\CustomRules\CustomRule;
use Padosoft\PiiRedactor\CustomRules\CustomRuleDetector;
use Padosoft\PiiRedactor\CustomRules\CustomRuleSet;
use Padosoft\PiiRedactor\Detectors\Detection;
use Padosoft\PiiRedactor\Detectors\Detector;
use Padosoft\PiiRedactor\Detectors\EmailDetector;
use Padosoft\PiiRedactor\Facades\Pii;
use Padosoft\PiiRedactor\Ner\NerDriver;
use Padosoft\PiiRedactor\RedactorEngine;
use Padosoft\PiiRedactor\Strategies\MaskStrategy;
use Padosoft\PiiRedactor\Tests\TestCase;

/**
 * Robustness tests covering category 4 (subsystem integration).
 *
 * These tests exercise the engine's overlap-resolver under combined
 * pressure from multiple sources at once: first-party detectors + NER
 * driver + custom rule packs + stacked Pii::extend() registrations.
 * The single-source tests in tests/Unit cover each detector in
 * isolation; this suite pins what happens at the interaction surface.
 *
 * Each test method documents the regression it would catch — typically
 * a double-redaction, a wrong-detector-wins resolution, or a duplicate
 * registration that silently appended instead of overwriting.
 */
final class IntegrationOverlapTest extends TestCase
{
    /**
     * Build a NER driver that emits a Detection at a fixed offset/length
     * for any input. Used to simulate a HuggingFace/spaCy hit that
     * overlaps a first-party regex match.
     */
    private function fakeNerDriver(string $detectorName, int $offset, int $length, string $value): NerDriver
    {
        return new class($detectorName, $offset, $length, $value) implements NerDriver
        {
            public function __construct(
                private string $detectorName,
                private int $offset,
                private int $length,
                private string $value,
            ) {}

            public function name(): string
            {
                return 'fake_ner';
            }

            public function detect(string $text): array
            {
                if (strlen($text) < $this->offset + $this->length) {
                    return [];
                }

                return [
                    new Detection(
                        detector: $this->detectorName,
                        value: $this->value,
                        offset: $this->offset,
                        length: $this->length,
                    ),
                ];
            }
        };
    }

    // ---------------------------------------------------------------
    // First-party + NER overlap.
    // ---------------------------------------------------------------

    /**
     * Catches: an overlap-resolver regression that lets two detections
     * cover the same span survive into the substitution loop. The
     * resulting redact() output would contain the mask token TWICE,
     * or a partial-string replacement with broken byte offsets.
     */
    public function test_first_party_email_and_ner_at_same_offset_yield_single_replacement(): void
    {
        $text = 'Contact mario@example.com today.';
        $emailOffset = strpos($text, 'mario@example.com');
        $emailLen = strlen('mario@example.com');
        $this->assertNotFalse($emailOffset);

        // Fake NER emits a detection at the SAME offset+length as the email.
        $ner = $this->fakeNerDriver('person', $emailOffset, $emailLen, 'mario@example.com');

        $engine = new RedactorEngine(new MaskStrategy('[X]'), nerDriver: $ner);
        $engine->register(new EmailDetector);

        $output = $engine->redact($text);

        // Single replacement — not double.
        $this->assertSame('Contact [X] today.', $output);
        $this->assertSame(1, substr_count($output, '[X]'));
    }

    /**
     * Catches: a tie-break regression on identical offset + identical
     * length. Per the engine's documented contract, "ties are broken
     * by longer match"; on identical length, the first detector by
     * registration order wins. That's PHP's stable usort + iteration
     * order over the registered detectors map.
     */
    public function test_overlap_at_same_offset_same_length_first_registered_wins(): void
    {
        $text = 'Contact mario@example.com today.';
        $offset = strpos($text, 'mario@example.com');
        $this->assertNotFalse($offset);

        // NER fires FIRST (registered via constructor); EmailDetector second.
        $ner = $this->fakeNerDriver('person', $offset, 17, 'mario@example.com');

        $engine = new RedactorEngine(new MaskStrategy('[X]'), nerDriver: $ner);
        $engine->register(new EmailDetector);

        // scan() returns the surviving detection — verify which one.
        $report = $engine->scan($text);
        $this->assertSame(1, $report->total());

        $detections = $report->detections();
        $this->assertCount(1, $detections);
        // The collectDetections() ordering iterates registered detectors
        // FIRST, then NER. With usort being stable, on a tie the
        // first-encountered detection wins → email_detector.
        $this->assertSame('email', $detections[0]->detector);
    }

    /**
     * Catches: an overlap regression where NER emits a LONGER span
     * containing the regex match. The lower-offset detection wins,
     * but only if length resolves correctly. When NER starts BEFORE
     * the email and the email starts AFTER, NER must win because of
     * lower offset.
     */
    public function test_ner_with_lower_offset_wins_over_first_party_detection(): void
    {
        $text = 'User: mario@example.com.';
        $userOffset = strpos($text, 'User');
        $emailOffset = strpos($text, 'mario@example.com');
        $this->assertNotFalse($userOffset);
        $this->assertNotFalse($emailOffset);

        // NER emits a span covering "User: mario@example.com" (full label).
        $nerLength = $emailOffset + 17 - $userOffset;
        $ner = $this->fakeNerDriver(
            'person_label',
            $userOffset,
            $nerLength,
            substr($text, $userOffset, $nerLength),
        );

        $engine = new RedactorEngine(new MaskStrategy('[X]'), nerDriver: $ner);
        $engine->register(new EmailDetector);

        $output = $engine->redact($text);

        // The wider NER span replaces the whole `User: mario@example.com`,
        // and the email match is dropped because it overlaps.
        $this->assertSame('[X].', $output);
    }

    // ---------------------------------------------------------------
    // First-party + custom rule pack overlap.
    // ---------------------------------------------------------------

    /**
     * Catches: an overlap-resolver regression that lets a custom rule
     * AND a first-party detector both consume the same span. Result
     * would be a double-redact in the redact() output.
     */
    public function test_first_party_email_and_custom_rule_at_same_offset_yield_single_replacement(): void
    {
        // Custom rule that literally matches `mario@example.com`.
        $custom = new CustomRuleDetector(
            'custom_emails',
            new CustomRuleSet([
                new CustomRule('explicit_email', 'mario@example\.com'),
            ]),
        );

        $text = 'Send to mario@example.com.';
        $engine = new RedactorEngine(new MaskStrategy('[X]'));
        $engine->register(new EmailDetector);
        $engine->register($custom);

        $output = $engine->redact($text);

        $this->assertSame('Send to [X].', $output);
        $this->assertSame(1, substr_count($output, '[X]'));
    }

    /**
     * Pins which detector survives when first-party + custom-rule
     * fire at the same offset + same length. Iteration order is the
     * detectors map order: register order. EmailDetector is registered
     * first, so it wins.
     */
    public function test_first_party_email_registered_first_wins_over_custom_rule_on_tie(): void
    {
        $custom = new CustomRuleDetector(
            'custom_emails',
            new CustomRuleSet([new CustomRule('explicit_email', 'mario@example\.com')]),
        );

        $engine = new RedactorEngine(new MaskStrategy('[X]'));
        $engine->register(new EmailDetector);   // registered first
        $engine->register($custom);             // registered second

        $report = $engine->scan('Send to mario@example.com.');

        $this->assertSame(1, $report->total());
        $detections = $report->detections();
        $this->assertSame('email', $detections[0]->detector);
    }

    /**
     * Pins the inverse: register the custom rule FIRST, and the
     * custom rule wins on tie. Documents that registration order is
     * load-bearing — a future refactor that sorts detectors
     * alphabetically would silently flip every consumer's tie-break.
     */
    public function test_custom_rule_registered_first_wins_over_first_party_email_on_tie(): void
    {
        $custom = new CustomRuleDetector(
            'custom_emails',
            new CustomRuleSet([new CustomRule('explicit_email', 'mario@example\.com')]),
        );

        $engine = new RedactorEngine(new MaskStrategy('[X]'));
        $engine->register($custom);             // registered first
        $engine->register(new EmailDetector);   // registered second

        $report = $engine->scan('Send to mario@example.com.');

        $this->assertSame(1, $report->total());
        $detections = $report->detections();
        $this->assertSame('custom_emails', $detections[0]->detector);
    }

    /**
     * Catches: longer-match-wins broken on otherwise-tied offsets.
     * Custom rule matches a LONGER span than the first-party email
     * (e.g., "Send to mario@example.com" — full sentence). The longer
     * match must win.
     */
    public function test_longer_custom_rule_match_wins_over_shorter_first_party_at_same_offset(): void
    {
        // The first-party email match starts at offset 8.
        // The custom rule matches the longer span "to mario@example.com"
        // starting at offset 5 — but offset 5 is LOWER, so it wins by
        // offset, not length. To test the "longer-wins on tie", we
        // need both at SAME offset.
        //
        // We'll force same offset by emitting a custom rule that
        // matches starting at offset 8 with length > 17 (the email).
        $custom = new CustomRuleDetector(
            'custom_long',
            new CustomRuleSet([
                // Matches "mario@example.com today" (23 chars).
                new CustomRule('long_match', 'mario@example\.com today'),
            ]),
        );

        $engine = new RedactorEngine(new MaskStrategy('[X]'));
        $engine->register(new EmailDetector);
        $engine->register($custom);

        $text = 'Send to mario@example.com today.';
        $output = $engine->redact($text);

        // Same offset (start of `mario`), longer match wins → custom rule
        // consumes "mario@example.com today" → "Send to [X]."
        $this->assertSame('Send to [X].', $output);
    }

    // ---------------------------------------------------------------
    // Stacked Pii::extend() registrations.
    // ---------------------------------------------------------------

    /**
     * Catches: a regression where Pii::extend() somehow appends to a
     * list rather than upserting on the detector NAME — three
     * different-named detectors should all end up registered together.
     */
    public function test_three_extend_calls_with_distinct_names_all_registered(): void
    {
        $detectorOne = new CustomRuleDetector(
            'custom_one',
            new CustomRuleSet([new CustomRule('rule_one', 'X-\d+')]),
        );
        $detectorTwo = new CustomRuleDetector(
            'custom_two',
            new CustomRuleSet([new CustomRule('rule_two', 'Y-\d+')]),
        );
        $detectorThree = new CustomRuleDetector(
            'custom_three',
            new CustomRuleSet([new CustomRule('rule_three', 'Z-\d+')]),
        );

        Pii::extend('custom_one', $detectorOne);
        Pii::extend('custom_two', $detectorTwo);
        Pii::extend('custom_three', $detectorThree);

        $detectors = Pii::detectors();

        $this->assertArrayHasKey('custom_one', $detectors);
        $this->assertArrayHasKey('custom_two', $detectors);
        $this->assertArrayHasKey('custom_three', $detectors);

        // Sanity round-trip: every pack actually fires.
        $report = Pii::scan('X-100 Y-200 Z-300');
        $this->assertSame(3, $report->total());
    }

    /**
     * Catches: a regression where re-registering with the same name
     * APPENDS instead of overwriting. The `detectors` map is keyed
     * by detector name, so re-registering must replace.
     */
    public function test_re_register_with_same_name_overwrites_does_not_duplicate(): void
    {
        $first = new CustomRuleDetector(
            'custom_overlap',
            new CustomRuleSet([new CustomRule('first_rule', 'A-\d+')]),
        );
        $second = new CustomRuleDetector(
            'custom_overlap',
            new CustomRuleSet([new CustomRule('second_rule', 'B-\d+')]),
        );

        Pii::extend('custom_overlap', $first);
        Pii::extend('custom_overlap', $second);

        $detectors = Pii::detectors();

        // The map must contain exactly one entry under 'custom_overlap',
        // and that entry must be the second detector — proves overwrite.
        $this->assertArrayHasKey('custom_overlap', $detectors);
        $this->assertSame($second, $detectors['custom_overlap']);

        // The original `A-\d+` rule is gone; only `B-\d+` fires.
        $report = Pii::scan('A-1 B-2');
        $this->assertSame(1, $report->total());
        $this->assertSame('B-2', $report->detections()[0]->value);
    }

    /**
     * Catches: a regression where a custom anonymous Detector that
     * implements the interface but is not part of the canonical roster
     * fails to register via Pii::extend(). The contract is "any
     * Detector implementor", not "first-party only".
     */
    public function test_extend_accepts_arbitrary_detector_implementations(): void
    {
        $customDetector = new class implements Detector
        {
            public function name(): string
            {
                return 'arbitrary_keyword';
            }

            public function detect(string $text): array
            {
                $hits = [];
                if (preg_match_all('/\bSECRET\b/', $text, $matches, PREG_OFFSET_CAPTURE)) {
                    foreach ($matches[0] as $m) {
                        $hits[] = new Detection(
                            detector: 'arbitrary_keyword',
                            value: (string) $m[0],
                            offset: (int) $m[1],
                            length: strlen((string) $m[0]),
                        );
                    }
                }

                return $hits;
            }
        };

        Pii::extend('arbitrary_keyword', $customDetector);

        $report = Pii::scan('This is a SECRET document with two SECRET sections.');

        $this->assertSame(2, $report->total());
        $this->assertSame('arbitrary_keyword', $report->detections()[0]->detector);
    }

    /**
     * Catches: a regression in collectDetections() where the iteration
     * order over registered detectors becomes non-deterministic.
     * Three detectors fire on disjoint offsets — the surviving order
     * must be offset-asc deterministically.
     */
    public function test_three_detector_disjoint_matches_resolve_in_offset_order(): void
    {
        $packA = new CustomRuleDetector(
            'pack_a',
            new CustomRuleSet([new CustomRule('a_rule', 'AAA-\d+')]),
        );
        $packB = new CustomRuleDetector(
            'pack_b',
            new CustomRuleSet([new CustomRule('b_rule', 'BBB-\d+')]),
        );
        $packC = new CustomRuleDetector(
            'pack_c',
            new CustomRuleSet([new CustomRule('c_rule', 'CCC-\d+')]),
        );

        $engine = new RedactorEngine(new MaskStrategy('[X]'));
        // Register in reverse alphabetical to stress the offset-asc invariant.
        $engine->register($packC);
        $engine->register($packB);
        $engine->register($packA);

        // BBB at offset 0, AAA at 9, CCC at 18 — input order is B/A/C.
        $text = 'BBB-100 / AAA-200 / CCC-300';
        $report = $engine->scan($text);
        $detections = $report->detections();

        $this->assertCount(3, $detections);
        $this->assertSame('BBB-100', $detections[0]->value);
        $this->assertSame('AAA-200', $detections[1]->value);
        $this->assertSame('CCC-300', $detections[2]->value);
    }
}
