<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit;

use Padosoft\PiiRedactor\Detectors\Detection;
use Padosoft\PiiRedactor\Detectors\Detector;
use Padosoft\PiiRedactor\Detectors\EmailDetector;
use Padosoft\PiiRedactor\Detectors\IbanDetector;
use Padosoft\PiiRedactor\Exceptions\DetectorException;
use Padosoft\PiiRedactor\RedactorEngine;
use Padosoft\PiiRedactor\Strategies\DropStrategy;
use Padosoft\PiiRedactor\Strategies\MaskStrategy;
use PHPUnit\Framework\TestCase;

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
}
