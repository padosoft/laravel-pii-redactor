<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Strategies;

/**
 * Contract for replacement policies.
 *
 * Implementations receive the original matched substring + the producing
 * detector name so they can apply detector-specific behaviour (e.g.
 * preserve the last 4 of a PAN, hash with a per-detector namespace).
 *
 * Implementations are encouraged — but not required — to be deterministic:
 * MaskStrategy, HashStrategy and the post-refactor TokeniseStrategy are
 * pure functions of (original, detectorName) (TokeniseStrategy also
 * carries an in-memory map for reversibility, but apply() itself is
 * deterministic w.r.t. its inputs). DropStrategy is trivially constant.
 * Implementations that need internal state (e.g. a future
 * RotatingTokenStrategy) MUST document their state semantics on the
 * concrete class, since callers may persist their output and later try
 * to reproduce it from a fresh instance.
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
