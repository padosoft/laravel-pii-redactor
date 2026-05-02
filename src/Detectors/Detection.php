<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Detectors;

/**
 * Single detection emitted by a Detector. Immutable value object.
 *
 * - $detector: the Detector::name() that produced the match.
 * - $value: the literal substring matched in the source text.
 * - $offset: byte offset into the source string (0-indexed).
 * - $length: byte length of the match.
 */
final class Detection
{
    public function __construct(
        public readonly string $detector,
        public readonly string $value,
        public readonly int $offset,
        public readonly int $length,
    ) {}

    public function endOffset(): int
    {
        return $this->offset + $this->length;
    }
}
