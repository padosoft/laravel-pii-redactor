<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Strategies;

use Padosoft\PiiRedactor\Exceptions\StrategyException;
use Padosoft\PiiRedactor\Strategies\TokeniseStrategy;
use Padosoft\PiiRedactor\TokenStore\InMemoryTokenStore;
use Padosoft\PiiRedactor\TokenStore\TokenStore;
use PHPUnit\Framework\TestCase;

final class TokeniseStrategyTest extends TestCase
{
    public function test_emits_namespaced_token_and_round_trips(): void
    {
        $strategy = new TokeniseStrategy(salt: 'pepper-2026');
        $this->assertSame('tokenise', $strategy->name());

        $token = $strategy->apply('mario.rossi@example.com', 'email');

        // Default id width is 16 hex chars (= 64-bit namespace).
        $this->assertMatchesRegularExpression('/^\[tok:email:[0-9a-f]{16}\]$/', $token);
        $this->assertSame('mario.rossi@example.com', $strategy->detokenise($token));
    }

    public function test_token_is_pure_function_of_inputs_regardless_of_encounter_order(): void
    {
        // Two strategies see the same value-of-interest but process other
        // values in opposite orders before it. The token MUST be identical:
        // apply() must depend only on (salt, detector, original), not on
        // any in-instance running counter.
        $a = new TokeniseStrategy(salt: 'shared-salt');
        $a->apply('alpha@x.com', 'email');
        $a->apply('beta@x.com', 'email');
        $tokenFromA = $a->apply('mario.rossi@example.com', 'email');

        $b = new TokeniseStrategy(salt: 'shared-salt');
        $tokenFromB = $b->apply('mario.rossi@example.com', 'email');

        $this->assertSame($tokenFromA, $tokenFromB);
    }

    public function test_id_hex_length_is_configurable(): void
    {
        $strategy = new TokeniseStrategy(salt: 's', idHexLength: 32);
        $token = $strategy->apply('one@x.com', 'email');

        $this->assertMatchesRegularExpression('/^\[tok:email:[0-9a-f]{32}\]$/', $token);
    }

    public function test_rejects_id_hex_length_below_8_or_above_64(): void
    {
        $this->expectException(StrategyException::class);
        new TokeniseStrategy(salt: 's', idHexLength: 7);
    }

    public function test_same_input_produces_same_token(): void
    {
        $strategy = new TokeniseStrategy(salt: 's');

        $a = $strategy->apply('mario.rossi@example.com', 'email');
        $b = $strategy->apply('mario.rossi@example.com', 'email');

        $this->assertSame($a, $b);
    }

    public function test_different_inputs_produce_different_tokens(): void
    {
        $strategy = new TokeniseStrategy(salt: 's');

        $a = $strategy->apply('one@x.com', 'email');
        $b = $strategy->apply('two@x.com', 'email');

        $this->assertNotSame($a, $b);
    }

    public function test_detokenise_string_replaces_all_tokens(): void
    {
        $strategy = new TokeniseStrategy(salt: 's');
        $tokenA = $strategy->apply('one@x.com', 'email');
        $tokenB = $strategy->apply('+39 333 1234567', 'phone_it');

        $redacted = "Email: {$tokenA}, Tel: {$tokenB}.";
        $restored = $strategy->detokeniseString($redacted);

        $this->assertSame('Email: one@x.com, Tel: +39 333 1234567.', $restored);
    }

    public function test_dump_and_load_map_round_trips(): void
    {
        $a = new TokeniseStrategy(salt: 's');
        $token = $a->apply('one@x.com', 'email');
        $map = $a->dumpMap();

        $b = new TokeniseStrategy(salt: 's');
        $b->loadMap($map);

        $this->assertSame('one@x.com', $b->detokenise($token));
    }

    public function test_apply_after_load_map_reuses_original_token(): void
    {
        $a = new TokeniseStrategy(salt: 's');
        $original = $a->apply('one@x.com', 'email');
        $map = $a->dumpMap();

        // Load the map into a fresh strategy; apply() must return the
        // SAME token as the original session, not mint a new one.
        $b = new TokeniseStrategy(salt: 's');
        $b->loadMap($map);

        $reissued = $b->apply('one@x.com', 'email');
        $this->assertSame($original, $reissued);
    }

    public function test_rejects_empty_salt(): void
    {
        $this->expectException(StrategyException::class);
        new TokeniseStrategy(salt: '');
    }

    public function test_default_constructor_uses_in_memory_store(): void
    {
        $strategy = new TokeniseStrategy(salt: 's');

        $this->assertInstanceOf(TokenStore::class, $strategy->store());
        $this->assertInstanceOf(InMemoryTokenStore::class, $strategy->store());
    }

    public function test_explicit_in_memory_store_matches_v01_behavior(): void
    {
        $store = new InMemoryTokenStore;
        $strategy = new TokeniseStrategy(salt: 's', idHexLength: 16, store: $store);

        $token = $strategy->apply('one@x.com', 'email');

        $this->assertSame('one@x.com', $strategy->detokenise($token));
        $this->assertSame('one@x.com', $store->get($token));
    }

    public function test_constructor_with_shared_store_persists_token_cross_instance(): void
    {
        $shared = new InMemoryTokenStore;

        $a = new TokeniseStrategy(salt: 's', idHexLength: 16, store: $shared);
        $token = $a->apply('cross@x.com', 'email');

        // A fresh strategy that wraps the SAME store sees the prior write
        // without needing dumpMap()/loadMap() — that is the contract that
        // makes a DatabaseTokenStore meaningful in production.
        $b = new TokeniseStrategy(salt: 's', idHexLength: 16, store: $shared);
        $this->assertSame('cross@x.com', $b->detokenise($token));

        // And re-applying the same input from B yields the same token.
        $this->assertSame($token, $b->apply('cross@x.com', 'email'));
    }

    public function test_apply_short_circuits_on_repeated_input(): void
    {
        // Critical: with DatabaseTokenStore, every put() is a SQL write.
        // The hot redaction loop MUST NOT issue a redundant write when the
        // same `(detector, original)` pair has already been seen in this
        // process. We verify by spying put() call count.
        $spy = new SpyTokenStore;
        $strategy = new TokeniseStrategy(salt: 's', idHexLength: 16, store: $spy);

        $strategy->apply('mario.rossi@example.com', 'email');
        $strategy->apply('mario.rossi@example.com', 'email');
        $strategy->apply('mario.rossi@example.com', 'email');

        $this->assertSame(1, $spy->putCalls, 'apply() must call put() exactly once for repeated input');
    }

    public function test_detokenise_string_only_fetches_referenced_tokens(): void
    {
        // detokeniseString() must scan the input and look up only those
        // tokens — never load every persisted entry. Critical for the
        // database driver where global table size can be 6+ orders of
        // magnitude larger than the input payload.
        $spy = new SpyTokenStore;
        $strategy = new TokeniseStrategy(salt: 's', idHexLength: 16, store: $spy);

        // Mint 3 tokens but reference only 2 of them in the input below.
        $tokA = $strategy->apply('one@x.com', 'email');
        $tokB = $strategy->apply('+39 333 1234567', 'phone_it');
        $strategy->apply('IT60X0542811101000000123456', 'iban');

        $spy->getCalls = 0;
        $spy->dumpCalls = 0;

        $strategy->detokeniseString("Email: {$tokA}, Tel: {$tokB}.");

        $this->assertSame(0, $spy->dumpCalls, 'detokeniseString() must NOT call dump() — that loads the full table');
        $this->assertSame(2, $spy->getCalls, 'detokeniseString() must call get() once per token actually referenced (here: 2)');
    }

    public function test_load_map_resets_minted_cache_so_writes_resume(): void
    {
        // After loadMap() the per-instance minted cache must be cleared,
        // because the loaded entries came from somewhere else and the
        // store may have been wiped + rehydrated. A subsequent apply()
        // should treat its tokens as not-yet-seen-in-this-process and
        // verify them against the (potentially rotated) store.
        $spy = new SpyTokenStore;
        $strategy = new TokeniseStrategy(salt: 's', idHexLength: 16, store: $spy);

        $strategy->apply('one@x.com', 'email');
        $this->assertSame(1, $spy->putCalls);

        $strategy->loadMap([]);  // wipe + reload empty map
        $strategy->apply('one@x.com', 'email');

        $this->assertSame(2, $spy->putCalls, 'After loadMap(), apply() must re-issue put() for previously-cached tokens');
    }
}

/**
 * Test-only spy that counts every interaction without any I/O. Lives
 * here (file-private) instead of in the Stub namespace because it is
 * exercised exclusively by `TokeniseStrategyTest` cases.
 */
final class SpyTokenStore implements TokenStore
{
    /** @var array<string, string> */
    public array $map = [];

    public int $putCalls = 0;

    public int $getCalls = 0;

    public int $hasCalls = 0;

    public int $dumpCalls = 0;

    public int $loadCalls = 0;

    public int $clearCalls = 0;

    public function put(string $token, string $original): void
    {
        $this->putCalls++;
        $this->map[$token] = $original;
    }

    public function get(string $token): ?string
    {
        $this->getCalls++;

        return $this->map[$token] ?? null;
    }

    public function has(string $token): bool
    {
        $this->hasCalls++;

        return isset($this->map[$token]);
    }

    public function clear(): void
    {
        $this->clearCalls++;
        $this->map = [];
    }

    /**
     * @return array<string, string>
     */
    public function dump(): array
    {
        $this->dumpCalls++;

        return $this->map;
    }

    /**
     * @param  array<string, string>  $map
     */
    public function load(array $map): void
    {
        $this->loadCalls++;
        $this->map = $map;
    }
}
