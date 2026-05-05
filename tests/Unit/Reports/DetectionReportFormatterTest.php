<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Reports;

use Padosoft\PiiRedactor\Detectors\Detection;
use Padosoft\PiiRedactor\Reports\DetectionReport;
use Padosoft\PiiRedactor\Reports\DetectionReportFormatter;
use PHPUnit\Framework\TestCase;

final class DetectionReportFormatterTest extends TestCase
{
    public function test_masks_samples_by_default(): void
    {
        $report = new DetectionReport([
            new Detection('email', 'mario@example.com', 0, 17),
            new Detection('iban', 'IT60X0542811101000000123456', 20, 27),
        ]);

        $payload = (new DetectionReportFormatter)->safeArray($report);

        $this->assertSame(2, $payload['total']);
        $this->assertSame(['email' => ['[email]'], 'iban' => ['[iban]']], $payload['samples']);
    }

    public function test_can_include_raw_samples_when_explicitly_requested(): void
    {
        $report = new DetectionReport([
            new Detection('email', 'mario@example.com', 0, 17),
        ]);

        $payload = (new DetectionReportFormatter)->safeArray($report, includeRawSamples: true);

        $this->assertSame(['email' => ['mario@example.com']], $payload['samples']);
    }

    public function test_preserves_total_and_counts_shape(): void
    {
        $report = new DetectionReport([
            new Detection('email', 'a@example.com', 0, 13),
            new Detection('email', 'b@example.com', 20, 13),
        ]);

        $payload = (new DetectionReportFormatter)->safeArray($report);

        $this->assertSame(2, $payload['total']);
        $this->assertSame(['email' => 2], $payload['counts']);
    }
}
