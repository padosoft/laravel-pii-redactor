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

    /**
     * Per-instance cache of tokens already minted in this process. Lets
     * `apply()` short-circuit without round-tripping the underlying store
     * on repeated occurrences of the same `(detector, original)` pair —
     * preserves v0.1's in-memory reverse-index performance regardless of
     * which {@see TokenStore} driver is wired (memory / database / future
     * cache).
     *
     * @var array<string, true>
     */
    private array $mintedThisProcess = [];

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

        // Fast path: this process already minted this exact token, so
        // skip the store write entirely. Critical for hot redaction loops
        // backed by DatabaseTokenStore — without this short-circuit every
        // repeated occurrence would issue a redundant `updateOrCreate`.
        if (isset($this->mintedThisProcess[$token])) {
            return $token;
        }

        $this->store->put($token, $original);
        $this->mintedThisProcess[$token] = true;

        return $token;
    }

    public function detokenise(string $token): ?string
    {
        return $this->store->get($token);
    }

    public function detokeniseString(string $text): string
    {
        if ($text === '') {
            return $text;
        }

        // Scan the input for `[tok:<detector>:<hex>]` literals and only
        // fetch THOSE tokens from the store. Critical for the database
        // driver — loading every persisted token to detokenise a single
        // payload would scale latency + memory with the global table
        // size instead of the input size.
        if (preg_match_all('/\[tok:[A-Za-z0-9_]+:[0-9a-f]+\]/', $text, $matches) === false) {
            return $text;
        }

        $tokens = array_unique($matches[0]);
        if ($tokens === []) {
            return $text;
        }

        $map = [];
        foreach ($tokens as $token) {
            $original = $this->store->get($token);
            if ($original !== null) {
                $map[$token] = $original;
            }
        }

        if ($map === []) {
            return $text;
        }

        return strtr($text, $map);
    }

    /**
     * Materialise the full token → original map. Backed by
     * {@see TokenStore::dump()}; for the DatabaseTokenStore this loads
     * every persisted row and is intended for snapshot / backup
     * workflows. Use {@see detokeniseString()} for runtime detokenisation
     * — it scans the input and fetches only the referenced tokens.
     *
     * @return array<string, string>
     */
    public function dumpMap(): array
    {
        return $this->store->dump();
    }

    /**
     * Restore a previously-dumped map, replacing any existing entries.
     *
     * Both {@see InMemoryTokenStore::load()} and
     * {@see DatabaseTokenStore::load()} drop the prior contents before
     * inserting the new map, so callers see the post-load state to be
     * exactly the supplied entries.
     *
     * Reverse-index reconstruction is the store's responsibility — the
     * token format `[tok:<detector>:<hex>]` is deterministic from
     * `(salt, detector, original)` so {@see apply()} computes it
     * directly without consulting any cached lookup. The store only has
     * to remember `token → original` for detokenisation.
     *
     * After loadMap(), the per-instance `mintedThisProcess` cache is
     * cleared so the freshly-loaded tokens get the same lazy verify-or-
     * write treatment as new mappings.
     *
     * @param  array<string, string>  $map
     */
    public function loadMap(array $map): void
    {
        $this->store->load($map);
        $this->mintedThisProcess = [];
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
