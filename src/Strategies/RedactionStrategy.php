<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Strategies;

/**
 * Contract for replacement policies. Stateless and deterministic.
 *
 * Implementations receive the original matched substring + the producing
 * detector name so they can apply detector-specific behaviour (e.g.
 * preserve the last 4 of a PAN, hash with a per-detector namespace).
 */
interface RedactionStrategy
{
    /**
     * Stable identifier (mask | hash | tokenise | drop).
     */
    public function name(): string;

    /**
     * Return the replacement substring for an original match.
     */
    public function apply(string $original, string $detectorName): string;
}
