<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Reports;

final class DetectionReportFormatter
{
    /**
     * @return array{
     *     total: int,
     *     counts: array<string, int>,
     *     samples: array<string, list<string>>,
     * }
     */
    public function safeArray(DetectionReport $report, bool $includeRawSamples = false): array
    {
        $payload = $report->toArray();
        if ($includeRawSamples) {
            return $payload;
        }

        $maskedSamples = [];
        foreach ($payload['samples'] as $detector => $samples) {
            $maskedSamples[$detector] = array_fill(0, count($samples), '['.$detector.']');
        }
        $payload['samples'] = $maskedSamples;

        return $payload;
    }
}
