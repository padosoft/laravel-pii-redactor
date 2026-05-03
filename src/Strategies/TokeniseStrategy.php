<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Strategies;

use Padosoft\PiiRedactor\Exceptions\StrategyException;
use Padosoft\PiiRedactor\TokenStore\DatabaseTokenStore;
use Padosoft\PiiRedactor\TokenStore\InMemoryTokenStore;
use Padosoft\PiiRedactor\TokenStore\TokenStore;

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
 * v0.1 kept the bidirectional mapping inside the strategy instance.
 * v0.2 delegates persistence to a pluggable {@see TokenStore}: the
 * default {@see InMemoryTokenStore} reproduces the v0.1 behaviour
 * (process-local map), while {@see DatabaseTokenStore}
 * persists the map into the `pii_token_maps` table so the reverse map
 * survives deploys and horizontal scale-out.
 *
 * Public surface — `apply()`, `detokenise()`, `detokeniseString()`,
 * `dumpMap()`, `loadMap()` — is identical to v0.1 byte-for-byte. The
 * only addition is the optional third constructor argument and the
 * `store()` getter for tests / operator-driven rotations.
 *
 * Token ID width is configurable through the constructor (default 16
 * hex chars = 64 bits of namespace) so the chance of an accidental
 * collision under typical workloads (low millions of distinct values)
 * stays negligible. Bumping it to 32 hex chars effectively eliminates
 * collision concerns at any realistic corpus size.
 */
final class TokeniseStrategy implements RedactionStrategy
{
    private readonly TokenStore $store;

    public function __construct(
        private readonly string $salt,
        private readonly int $idHexLength = 16,
        ?TokenStore $store = null,
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

        $this->store = $store ?? new InMemoryTokenStore;
    }

    public function name(): string
    {
        return 'tokenise';
    }

    public function apply(string $original, string $detectorName): string
    {
        // Pure function of (salt, detector, original): same value always
        // hashes to the same id regardless of encounter order or prior
        // calls, so tokens are stable across process boundaries.
        $idHex = substr(hash('sha256', $this->salt.':'.$detectorName.':'.$original), 0, $this->idHexLength);
        $token = '[tok:'.$detectorName.':'.$idHex.']';

        // put() is idempotent on duplicate token, so re-applying the
        // same input is a cheap upsert — no special-case branch needed.
        $this->store->put($token, $original);

        return $token;
    }

    public function detokenise(string $token): ?string
    {
        return $this->store->get($token);
    }

    public function detokeniseString(string $text): string
    {
        $map = $this->store->dump();
        if ($map === []) {
            return $text;
        }

        return strtr($text, $map);
    }

    /**
     * @return array<string, string>
     */
    public function dumpMap(): array
    {
        return $this->store->dump();
    }

    /**
     * Restore a previously-dumped map. Used to recover state across
     * process boundaries — and, since v0.2, also the path that the
     * `pii-redactor:rehydrate` Artisan command will use to seed the
     * DatabaseTokenStore from a JSON dump.
     *
     * Reverse-index reconstruction is now the store's responsibility:
     * the token format `[tok:<detector>:<hex>]` is deterministic from
     * `(salt, detector, original)`, so {@see apply()} computes it
     * directly without consulting any cached lookup. The store only
     * has to remember `token → original` for detokenisation, which is
     * exactly what {@see TokenStore::load()} does.
     *
     * @param  array<string, string>  $map
     */
    public function loadMap(array $map): void
    {
        $this->store->load($map);
    }

    /**
     * Direct access to the underlying store. Provided for tests and
     * operator scripts (rotation, dump, rehydrate) — not used in the
     * hot redaction path.
     */
    public function store(): TokenStore
    {
        return $this->store;
    }
}
