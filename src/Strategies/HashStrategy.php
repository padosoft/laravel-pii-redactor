<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Strategies;

use Padosoft\PiiRedactor\Exceptions\StrategyException;

/**
 * Replaces every detection with a salted SHA-256 prefix in the form
 * `[hash:<hex>]` (16-char hex by default = 64-bit namespace). Deterministic —
 * identical input under the same salt produces the same hash, which is useful
 * for downstream joins on pseudonymised data without revealing the original.
 *
 * The 16-char default matches the `hash_hex_length` config key default so that
 * constructing the strategy directly or via the service provider produces the
 * same output length. Lower values increase the chance of hash collisions
 * (at 8 hex chars the birthday bound is reached at ~30k distinct values).
 *
 * The salt MUST be set explicitly (constructor) or via the configured env
 * var; an empty salt would cause cross-deployment leakage of identical
 * hashes for identical PII and is treated as a configuration error.
 */
final class HashStrategy implements RedactionStrategy
{
    public function __construct(
        private readonly string $salt,
        private readonly int $hexLength = 16,
    ) {
        if ($salt === '') {
            throw new StrategyException(
                'HashStrategy requires a non-empty salt — set PII_REDACTOR_SALT in your environment.',
            );
        }
        if ($hexLength < 4 || $hexLength > 64) {
            throw new StrategyException(
                'HashStrategy hex length must be between 4 and 64 characters.',
            );
        }
    }

    public function name(): string
    {
        return 'hash';
    }

    public function apply(string $original, string $detectorName): string
    {
        $payload = $detectorName.':'.$this->salt.':'.$original;
        $hex = substr(hash('sha256', $payload), 0, $this->hexLength);

        return '[hash:'.$hex.']';
    }
}
