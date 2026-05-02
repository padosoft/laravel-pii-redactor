<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Detectors;

/**
 * Contract for every PII detector.
 *
 * Implementations must be deterministic and side-effect free: given the same
 * input, the same detection list (in the same order) is returned.
 */
interface Detector
{
    /**
     * Stable, snake_case identifier. Used in DetectionReport keys and as the
     * argument to RedactionStrategy::apply().
     */
    public function name(): string;

    /**
     * Scan the input and return zero or more Detection entries.
     *
     * @return list<Detection>
     */
    public function detect(string $text): array;
}
