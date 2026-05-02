<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Reports;

use Padosoft\PiiRedactor\Detectors\Detection;

/**
 * Aggregated, immutable result of a scan.
 *
 * Exposes:
 *  - countsByDetector(): how many detections per detector type
 *  - detections(): the raw list (ordered by offset asc)
 *  - samplesByDetector(): deduplicated samples per type (capped per
 *    constructor argument so a chatty input doesn't blow memory)
 *  - total(): total detection count
 */
final class DetectionReport
{
    /**
     * @param  list<Detection>  $detections
     */
    public function __construct(
        private readonly array $detections,
        private readonly int $sampleCap = 5,
    ) {}

    /**
     * @return list<Detection>
     */
    public function detections(): array
    {
        return $this->detections;
    }

    public function total(): int
    {
        return count($this->detections);
    }

    public function isEmpty(): bool
    {
        return $this->detections === [];
    }

    /**
     * @return array<string, int>
     */
    public function countsByDetector(): array
    {
        $out = [];
        foreach ($this->detections as $d) {
            $out[$d->detector] = ($out[$d->detector] ?? 0) + 1;
        }

        return $out;
    }

    /**
     * @return array<string, list<string>>
     */
    public function samplesByDetector(): array
    {
        $out = [];
        $seen = [];
        foreach ($this->detections as $d) {
            $key = $d->detector;
            $out[$key] ??= [];
            $seen[$key] ??= [];
            if (isset($seen[$key][$d->value])) {
                continue;
            }
            if (count($out[$key]) >= $this->sampleCap) {
                continue;
            }
            $out[$key][] = $d->value;
            $seen[$key][$d->value] = true;
        }

        return $out;
    }

    /**
     * @return array{
     *     total: int,
     *     counts: array<string, int>,
     *     samples: array<string, list<string>>,
     * }
     */
    public function toArray(): array
    {
        return [
            'total' => $this->total(),
            'counts' => $this->countsByDetector(),
            'samples' => $this->samplesByDetector(),
        ];
    }
}
