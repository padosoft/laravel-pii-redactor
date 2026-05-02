<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Strategies;

use Padosoft\PiiRedactor\Exceptions\StrategyException;

/**
 * Reversible pseudonymisation strategy.
 *
 * Replaces each detection with a deterministic token in the form
 * `[tok:<detector>:<id>]` and stores the bidirectional mapping in process
 * memory for the lifetime of the strategy instance. Callers recover the
 * original via TokeniseStrategy::detokenise($token), or detokenise an
 * entire redacted string via TokeniseStrategy::detokeniseString($text).
 *
 * In v0.1 the map is in-memory only — restart of the process discards it.
 * v0.2 will introduce a pluggable TokenStore (cache / DB / KMS-encrypted
 * blob) so the map survives deploys. Until then, callers that need to
 * detokenise must hold onto the strategy instance, or persist the result
 * of TokeniseStrategy::dumpMap() themselves.
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

    private int $sequence = 0;

    public function __construct(
        private readonly string $salt,
    ) {
        if ($salt === '') {
            throw new StrategyException(
                'TokeniseStrategy requires a non-empty salt for deterministic id derivation.',
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

        // Salt + sequence keep the public token short while remaining
        // unguessable for callers that only see the redacted text.
        $this->sequence++;
        $idHex = substr(hash('sha256', $this->salt.':'.$key.':'.$this->sequence), 0, 8);
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
     * The reverse index is fully reconstructed so that subsequent calls to
     * apply() reuse the tokens already in the map instead of minting new ones.
     *
     * @param  array<string, string>  $map
     */
    public function loadMap(array $map): void
    {
        $this->tokenToOriginal = $map;
        $this->reverseIndex = [];

        // Token format: [tok:<detector>:<8hex>] — parse the detector name so
        // the reverse index key (<detector>:<original>) can be reconstructed.
        foreach ($map as $token => $original) {
            if (preg_match('/^\[tok:([^:]+):[0-9a-f]{8}\]$/', $token, $m)) {
                $this->reverseIndex[$m[1].':'.$original] = $token;
            }
        }
    }
}
