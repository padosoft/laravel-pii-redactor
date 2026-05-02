<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Strategies;

use Padosoft\PiiRedactor\Exceptions\StrategyException;

/**
 * Reversible pseudonymisation strategy.
 *
 * Replaces each detection with a deterministic token in the form
 * `[tok:<detector>:<id>]` where `<id>` is the SHA-256 prefix of
 * `salt + ':' + detector + ':' + original`. The token is a pure
 * function of those inputs — same value under the same salt always
 * yields the same token, regardless of encounter order or whether
 * other values were tokenised first. That property is what makes the
 * tokens stable across process boundaries when the salt is shared.
 *
 * The strategy keeps the bidirectional mapping in process memory so
 * that detokenise() / detokeniseString() can reverse the redaction
 * without consulting an external store. In v0.1 the map is in-memory
 * only — a process restart discards it. v0.2 will introduce a
 * pluggable TokenStore (cache / DB / KMS-encrypted blob) so the map
 * survives deploys. Until then, callers that need to detokenise must
 * either hold onto the strategy instance or persist the result of
 * dumpMap() and restore it via loadMap().
 *
 * Token ID width is configurable through the constructor (default 16
 * hex chars = 64 bits of namespace) so the chance of an accidental
 * collision under typical workloads (low millions of distinct values)
 * stays negligible. Bumping it to 32 hex chars effectively eliminates
 * collision concerns at any realistic corpus size.
 */
final class TokeniseStrategy implements RedactionStrategy
{
    /**
     * @var array<string, string> token => original
     */
    private array $tokenToOriginal = [];

    /**
     * @var array<string, string> "<detector>:<original>" => token
     */
    private array $reverseIndex = [];

    public function __construct(
        private readonly string $salt,
        private readonly int $idHexLength = 16,
    ) {
        if ($salt === '') {
            throw new StrategyException(
                'TokeniseStrategy requires a non-empty salt for deterministic id derivation.',
            );
        }
        if ($idHexLength < 8 || $idHexLength > 64) {
            throw new StrategyException(
                'TokeniseStrategy idHexLength must be between 8 and 64 (default 16 = 64-bit namespace).',
            );
        }
    }

    public function name(): string
    {
        return 'tokenise';
    }

    public function apply(string $original, string $detectorName): string
    {
        $key = $detectorName.':'.$original;
        if (isset($this->reverseIndex[$key])) {
            return $this->reverseIndex[$key];
        }

        // Pure function of (salt, detector, original): same value always
        // hashes to the same id regardless of encounter order or prior
        // calls, so tokens are stable across process boundaries.
        $idHex = substr(hash('sha256', $this->salt.':'.$key), 0, $this->idHexLength);
        $token = '[tok:'.$detectorName.':'.$idHex.']';

        $this->tokenToOriginal[$token] = $original;
        $this->reverseIndex[$key] = $token;

        return $token;
    }

    public function detokenise(string $token): ?string
    {
        return $this->tokenToOriginal[$token] ?? null;
    }

    public function detokeniseString(string $text): string
    {
        if ($this->tokenToOriginal === []) {
            return $text;
        }

        return strtr($text, $this->tokenToOriginal);
    }

    /**
     * @return array<string, string>
     */
    public function dumpMap(): array
    {
        return $this->tokenToOriginal;
    }

    /**
     * Restore a previously-dumped map. Used to recover state across
     * process boundaries before v0.2's persistent TokenStore lands.
     *
     * The reverse index is fully reconstructed by parsing the token
     * format `[tok:<detector>:<hex>]` so subsequent apply() calls reuse
     * the loaded tokens instead of minting new ones.
     *
     * @param  array<string, string>  $map
     */
    public function loadMap(array $map): void
    {
        $this->tokenToOriginal = $map;
        $this->reverseIndex = [];

        foreach ($map as $token => $original) {
            if (preg_match('/^\[tok:([^:]+):[0-9a-f]+\]$/', $token, $m) === 1) {
                $this->reverseIndex[$m[1].':'.$original] = $token;
            }
        }
    }
}
