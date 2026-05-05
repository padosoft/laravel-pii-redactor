<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Strategies;

use Padosoft\PiiRedactor\Exceptions\StrategyException;
use Padosoft\PiiRedactor\Strategies\DropStrategy;
use Padosoft\PiiRedactor\Strategies\HashStrategy;
use Padosoft\PiiRedactor\Strategies\MaskStrategy;
use Padosoft\PiiRedactor\Strategies\RedactionStrategy;
use Padosoft\PiiRedactor\Strategies\RedactionStrategyFactory;
use Padosoft\PiiRedactor\Strategies\TokeniseStrategy;
use Padosoft\PiiRedactor\Tests\TestCase;
use Padosoft\PiiRedactor\TokenStore\TokenStore;

final class RedactionStrategyFactoryTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('pii-redactor.salt', 'test-salt');
    }

    public function test_names_lists_supported_strategies(): void
    {
        $factory = $this->app->make(RedactionStrategyFactory::class);

        $this->assertSame(['mask', 'hash', 'tokenise', 'drop'], $factory->names());
    }

    public function test_builds_all_supported_strategies(): void
    {
        $factory = $this->app->make(RedactionStrategyFactory::class);

        $this->assertInstanceOf(MaskStrategy::class, $factory->make('mask'));
        $this->assertInstanceOf(HashStrategy::class, $factory->make('hash'));
        $this->assertInstanceOf(TokeniseStrategy::class, $factory->make('tokenise'));
        $this->assertInstanceOf(DropStrategy::class, $factory->make('drop'));
    }

    public function test_make_without_argument_uses_configured_default(): void
    {
        $this->app['config']->set('pii-redactor.strategy', 'drop');
        $factory = $this->app->make(RedactionStrategyFactory::class);

        $this->assertInstanceOf(DropStrategy::class, $factory->make());
    }

    public function test_hash_and_tokenise_require_salt(): void
    {
        $this->app['config']->set('pii-redactor.salt', '');
        $factory = $this->app->make(RedactionStrategyFactory::class);

        $this->expectException(StrategyException::class);
        $factory->make('hash');
    }

    public function test_tokenise_strategy_uses_configured_token_store(): void
    {
        $store = new class implements TokenStore
        {
            public array $map = [];

            public function put(string $token, string $original): void
            {
                $this->map[$token] = $original;
            }

            public function get(string $token): ?string
            {
                return $this->map[$token] ?? null;
            }

            public function has(string $token): bool
            {
                return isset($this->map[$token]);
            }

            public function clear(): void
            {
                $this->map = [];
            }

            public function dump(): array
            {
                return $this->map;
            }

            public function load(array $map): void
            {
                $this->map = $map;
            }
        };

        $factory = new RedactionStrategyFactory($this->app['config'], $store);
        $strategy = $factory->make('tokenise');

        $this->assertInstanceOf(TokeniseStrategy::class, $strategy);
        $token = $strategy->apply('mario@example.com', 'email');
        $this->assertSame('mario@example.com', $store->get($token));
    }

    public function test_unknown_strategy_throws_strategy_exception(): void
    {
        $factory = $this->app->make(RedactionStrategyFactory::class);

        $this->expectException(StrategyException::class);
        $factory->make('unknown');
    }

    public function test_service_provider_binds_default_strategy_through_factory(): void
    {
        $this->app['config']->set('pii-redactor.strategy', 'drop');
        $this->app->forgetInstance(RedactionStrategy::class);

        $this->assertInstanceOf(DropStrategy::class, $this->app->make(RedactionStrategy::class));
    }
}
