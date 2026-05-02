<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Strategies;

/**
 * Removes the matched substring entirely. Useful for cases where leaving a
 * placeholder would itself signal that PII was present (e.g. forensic
 * exports forwarded to lossy downstream systems).
 *
 * Adjacent whitespace is NOT collapsed — callers that need pristine output
 * should run a second pass over the result.
 */
final class DropStrategy implements RedactionStrategy
{
    public function name(): string
    {
        return 'drop';
    }

    public function apply(string $original, string $detectorName): string
    {
        return '';
    }
}
