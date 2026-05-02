<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Reports;

use Padosoft\PiiRedactor\Detectors\Detection;
use Padosoft\PiiRedactor\Reports\DetectionReport;
use PHPUnit\Framework\TestCase;

final class DetectionReportTest extends TestCase
{
    public function test_counts_by_detector(): void
    {
        $report = new DetectionReport([
            new Detection('email', 'a@x.io', 0, 6),
            new Detection('email', 'b@x.io', 10, 6),
            new Detection('p_iva', '12345678903', 20, 11),
        ]);

        $this->assertSame(3, $report->total());
        $this->assertFalse($report->isEmpty());
        $this->assertSame(['email' => 2, 'p_iva' => 1], $report->countsByDetector());
    }

    public function test_samples_dedupe_and_cap(): void
    {
        $detections = [];
        for ($i = 0; $i < 10; $i++) {
            $detections[] = new Detection('email', 'dup@x.io', $i, 8);
        }
        for ($i = 0; $i < 4; $i++) {
            $detections[] = new Detection('email', "u{$i}@x.io", 100 + $i * 10, 8);
        }

        $report = new DetectionReport($detections, sampleCap: 5);

        $samples = $report->samplesByDetector();
        $this->assertArrayHasKey('email', $samples);
        $this->assertCount(5, $samples['email']);
        $this->assertSame('dup@x.io', $samples['email'][0]);
    }

    public function test_to_array(): void
    {
        $report = new DetectionReport([
            new Detection('iban', 'IT60X0542811101000000123456', 0, 27),
        ]);

        $payload = $report->toArray();

        $this->assertSame(1, $payload['total']);
        $this->assertSame(['iban' => 1], $payload['counts']);
        $this->assertSame(['iban' => ['IT60X0542811101000000123456']], $payload['samples']);
    }

    public function test_empty_report(): void
    {
        $report = new DetectionReport([]);
        $this->assertTrue($report->isEmpty());
        $this->assertSame(0, $report->total());
        $this->assertSame([], $report->countsByDetector());
    }
}
