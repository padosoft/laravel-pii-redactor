<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Strategies;

use Padosoft\PiiRedactor\Exceptions\StrategyException;
use Padosoft\PiiRedactor\Strategies\HashStrategy;
use PHPUnit\Framework\TestCase;

final class HashStrategyTest extends TestCase
{
    public function test_emits_namespaced_hash_token(): void
    {
        $strategy = new HashStrategy(salt: 'pepper-2026', hexLength: 8);
        $this->assertSame('hash', $strategy->name());

        $out = $strategy->apply('mario.rossi@example.com', 'email');

        $this->assertMatchesRegularExpression('/^\[hash:[0-9a-f]{8}\]$/', $out);
    }

    public function test_default_hex_length_is_16(): void
    {
        // Constructor default must match the config default (hash_hex_length = 16)
        // so that constructing HashStrategy directly or via the service provider
        // produces the same output length.
        $strategy = new HashStrategy(salt: 'pepper-2026');
        $out = $strategy->apply('mario.rossi@example.com', 'email');

        $this->assertMatchesRegularExpression('/^\[hash:[0-9a-f]{16}\]$/', $out);
    }

    public function test_is_deterministic_for_same_input_and_salt(): void
    {
        $a = new HashStrategy(salt: 'salt-1');
        $b = new HashStrategy(salt: 'salt-1');

        $this->assertSame(
            $a->apply('mario.rossi@example.com', 'email'),
            $b->apply('mario.rossi@example.com', 'email'),
        );
    }

    public function test_is_per_detector_namespaced(): void
    {
        $strategy = new HashStrategy(salt: 'salt-1');

        // Same payload string under two different detector names produces
        // different hashes — prevents accidental cross-type joins.
        $this->assertNotSame(
            $strategy->apply('1234567890', 'p_iva'),
            $strategy->apply('1234567890', 'phone_it'),
        );
    }

    public function test_rejects_empty_salt(): void
    {
        $this->expectException(StrategyException::class);
        new HashStrategy(salt: '');
    }

    public function test_rejects_invalid_hex_length(): void
    {
        $this->expectException(StrategyException::class);
        new HashStrategy(salt: 'x', hexLength: 2);
    }
}
