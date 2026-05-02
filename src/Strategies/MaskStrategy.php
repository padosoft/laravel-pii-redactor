<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Strategies;

/**
 * Replaces every detection with a fixed mask token (default `[REDACTED]`).
 *
 * Predictable and reversible only via the structural detection report —
 * the redacted text contains no information about the original value.
 */
final class MaskStrategy implements RedactionStrategy
{
    public function __construct(
        private readonly string $mask = '[REDACTED]',
    ) {}

    public function name(): string
    {
        return 'mask';
    }

    public function apply(string $original, string $detectorName): string
    {
        return $this->mask;
    }
}
