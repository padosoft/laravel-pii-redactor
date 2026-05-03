<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Padosoft\PiiRedactor\Detectors\Detection;
use Padosoft\PiiRedactor\Detectors\Detector;
use Padosoft\PiiRedactor\Detectors\EmailDetector;
use Padosoft\PiiRedactor\Detectors\IbanDetector;
use Padosoft\PiiRedactor\Events\PiiRedactionPerformed;
use Padosoft\PiiRedactor\Exceptions\DetectorException;
use Padosoft\PiiRedactor\Ner\NerDriver;
use Padosoft\PiiRedactor\Ner\StubNerDriver;
use Padosoft\PiiRedactor\RedactorEngine;
use Padosoft\PiiRedactor\Strategies\DropStrategy;
use Padosoft\PiiRedactor\Strategies\MaskStrategy;
use Padosoft\PiiRedactor\Tests\TestCase;

final class RedactorEngineTest extends TestCase
{
    public function test_redact_replaces_every_detection(): void
    {
        $engine = new RedactorEngine(new MaskStrategy('[X]'));
        $engine->register(new EmailDetector);
        $engine->register(new IbanDetector);

        $input = 'A: a@x.io, B: b@y.io, IBAN: IT60X0542811101000000123456.';
        $out = $engine->redact($input);

        $this->assertSame('A: [X], B: [X], IBAN: [X].', $out);
    }

    public function test_redact_returns_input_unchanged_when_no_detectors_match(): void
    {
        $engine = new RedactorEngine(new MaskStrategy);
        $engine->register(new EmailDetector);

        $input = 'Plain text without sensitive content.';
        $this->assertSame($input, $engine->redact($input));
    }

    public function test_scan_returns_detection_report_with_counts(): void
    {
        $engine = new RedactorEngine(new MaskStrategy);
        $engine->register(new EmailDetector);

        $report = $engine->scan('Email: a@x.io, b@y.io, c@z.io.');

        $this->assertSame(3, $report->total());
        $this->assertSame(['email' => 3], $report->countsByDetector());
    }

    public function test_extend_alias_must_match_detector_name(): void
    {
        $engine = new RedactorEngine(new MaskStrategy);

        $this->expectException(DetectorException::class);
        $engine->extend('wrong_name', new EmailDetector);
    }

    public function test_extend_registers_a_custom_detector(): void
    {
        $engine = new RedactorEngine(new DropStrategy);

        $custom = new class implements Detector
        {
            public function name(): string
            {
                return 'custom_albo';
            }

            public function detect(string $text): array
            {
                if (! str_contains($text, 'ISCR-')) {
                    return [];
                }

                $matches = [];
                preg_match_all('/ISCR-\d{4,}/', $text, $matches, PREG_OFFSET_CAPTURE);
                $hits = [];
                foreach ($matches[0] as $m) {
                    $hits[] = new Detection('custom_albo', (string) $m[0], (int) $m[1], strlen((string) $m[0]));
                }

                return $hits;
            }
        };

        $engine->extend('custom_albo', $custom);

        $out = $engine->redact('Avvocato ISCR-12345 di Milano.');
        $this->assertSame('Avvocato  di Milano.', $out);
    }

    public function test_overlapping_detections_are_resolved_left_to_right(): void
    {
        // Two detectors emit overlapping ranges; engine keeps the first by
        // lower offset, dropping the overlapping latecomer.
        $a = new class implements Detector
        {
            public function name(): string
            {
                return 'a';
            }

            public function detect(string $text): array
            {
                return [new Detection('a', substr($text, 0, 5), 0, 5)];
            }
        };
        $b = new class implements Detector
        {
            public function name(): string
            {
                return 'b';
            }

            public function detect(string $text): array
            {
                return [new Detection('b', substr($text, 2, 5), 2, 5)];
            }
        };

        $engine = new RedactorEngine(new MaskStrategy('[X]'));
        $engine->register($a);
        $engine->register($b);

        // 5 chars consumed at offset 0; second detection at offset 2 dropped.
        $out = $engine->redact('abcdefghij');
        $this->assertSame('[X]fghij', $out);
    }

    public function test_with_strategy_returns_a_clone(): void
    {
        $engine = new RedactorEngine(new MaskStrategy);
        $engine->register(new EmailDetector);

        $clone = $engine->withStrategy(new DropStrategy);

        $this->assertNotSame($engine, $clone);
        $this->assertSame(DropStrategy::class, $clone->strategy()::class);
        $this->assertSame(MaskStrategy::class, $engine->strategy()::class);
    }

    public function test_disabled_engine_returns_text_unchanged(): void
    {
        $engine = new RedactorEngine(new MaskStrategy, enabled: false);
        $engine->register(new EmailDetector);

        $input = 'Contact: a@x.io for details.';
        $this->assertSame($input, $engine->redact($input));
    }

    public function test_with_enabled_returns_a_clone(): void
    {
        $engine = new RedactorEngine(new MaskStrategy);
        $engine->register(new EmailDetector);

        $disabled = $engine->withEnabled(false);

        $this->assertNotSame($engine, $disabled);
        $this->assertTrue($engine->isEnabled());
        $this->assertFalse($disabled->isEnabled());
    }

    public function test_scan_runs_even_when_engine_disabled(): void
    {
        $engine = new RedactorEngine(new MaskStrategy, enabled: false);
        $engine->register(new EmailDetector);

        $report = $engine->scan('Email: a@x.io.');

        // scan() is always active regardless of the enabled flag.
        $this->assertSame(1, $report->total());
    }

    public function test_audit_trail_event_is_fired_when_enabled(): void
    {
        Event::fake();

        $engine = new RedactorEngine(
            new MaskStrategy('[X]'),
            enabled: true,
            auditTrailEnabled: true,
        );
        $engine->register(new EmailDetector);

        $engine->redact('Email a@x.io and b@y.io.');

        Event::assertDispatched(
            PiiRedactionPerformed::class,
            fn (PiiRedactionPerformed $e) => $e->total === 2
                && $e->countsByDetector === ['email' => 2]
                && $e->strategyName === 'mask',
        );
    }

    public function test_audit_trail_event_is_not_fired_by_default(): void
    {
        Event::fake();

        // Default constructor: auditTrailEnabled defaults to false.
        $engine = new RedactorEngine(new MaskStrategy('[X]'));
        $engine->register(new EmailDetector);

        $engine->redact('Email a@x.io.');

        Event::assertNotDispatched(PiiRedactionPerformed::class);
    }

    public function test_audit_trail_event_is_not_fired_when_no_detections(): void
    {
        Event::fake();

        $engine = new RedactorEngine(
            new MaskStrategy('[X]'),
            enabled: true,
            auditTrailEnabled: true,
        );
        $engine->register(new EmailDetector);

        // No PII in the input — early return before the dispatch.
        $engine->redact('Plain text without sensitive content.');

        Event::assertNotDispatched(PiiRedactionPerformed::class);
    }

    public function test_with_audit_trail_returns_a_clone(): void
    {
        $engine = new RedactorEngine(new MaskStrategy);
        $enabled = $engine->withAuditTrail(true);

        $this->assertNotSame($engine, $enabled);
    }

    public function test_ner_driver_detections_are_merged_into_output(): void
    {
        $fakeNer = new class implements NerDriver
        {
            public function name(): string
            {
                return 'fake_ner';
            }

            public function detect(string $text): array
            {
                if (! str_contains($text, 'Mario')) {
                    return [];
                }
                $offset = strpos($text, 'Mario');

                return [new Detection('person_ner', 'Mario', (int) $offset, 5)];
            }
        };

        $engine = new RedactorEngine(
            new MaskStrategy('[X]'),
            enabled: true,
            auditTrailEnabled: false,
            nerDriver: $fakeNer,
        );

        $out = $engine->redact('Mario lives in Roma.');

        $this->assertSame('[X] lives in Roma.', $out);
    }

    public function test_with_ner_driver_returns_a_clone(): void
    {
        $engine = new RedactorEngine(new MaskStrategy);
        $clone = $engine->withNerDriver(new StubNerDriver);

        $this->assertNotSame($engine, $clone);
    }

    public function test_stub_ner_driver_returns_no_detections(): void
    {
        // Engine wired with the stub ner driver behaves like a v0.1 engine.
        $engine = new RedactorEngine(
            new MaskStrategy('[X]'),
            enabled: true,
            auditTrailEnabled: false,
            nerDriver: new StubNerDriver,
        );
        $engine->register(new EmailDetector);

        $out = $engine->redact('Mario at a@x.io is here.');

        // Only the email got redacted; the stub returned no NER hits.
        $this->assertSame('Mario at [X] is here.', $out);
    }

    public function test_engine_without_ner_driver_behaves_as_v01(): void
    {
        // No nerDriver passed: backward-compat with v0.1 callers.
        $engine = new RedactorEngine(new MaskStrategy('[X]'));
        $engine->register(new EmailDetector);

        $out = $engine->redact('Email a@x.io.');

        $this->assertSame('Email [X].', $out);
    }
}
