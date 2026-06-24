<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Strategies;

use Illuminate\Config\Repository;
use Padosoft\PiiRedactor\Strategies\RedactionStrategyFactory;
use Padosoft\PiiRedactor\Strategies\TokeniseStrategy;
use Padosoft\PiiRedactor\TokenStore\InMemoryTokenStore;
use PHPUnit\Framework\TestCase;

/**
 * v1.4 — when the factory is constructed without an explicit resolver (the
 * public two-arg signature, e.g. an admin preview), its fallback resolver must
 * report the CONFIGURED legacy tenant id so the minted token stays v1.3-bare.
 */
final class RedactionStrategyFactoryTenancyTest extends TestCase
{
    public function test_fallback_resolver_uses_configured_default_id_for_bare_tokens(): void
    {
        $config = new Repository([
            'pii-redactor' => [
                'strategy' => 'tokenise',
                'salt' => 'base-salt',
                'token_hex_length' => 16,
                'tenant' => ['default_id' => 'acme'],
            ],
        ]);

        // Two-arg construction (no resolver) with a customised default_id.
        $factory = new RedactionStrategyFactory($config, new InMemoryTokenStore);
        $strategy = $factory->make('tokenise');

        // Must equal the bare-salt token (no `salt:acme` namespacing), because
        // the single tenant IS the legacy tenant.
        $bare = new TokeniseStrategy('base-salt', 16, new InMemoryTokenStore);

        $this->assertSame(
            $bare->apply('mario.rossi@example.com', 'email'),
            $strategy->apply('mario.rossi@example.com', 'email'),
        );
    }
}
