<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Strategies;

use Padosoft\PiiRedactor\Exceptions\StrategyException;
use Padosoft\PiiRedactor\Strategies\TokeniseStrategy;
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
}
