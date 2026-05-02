<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit;

use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\TestCase;
use Padosoft\PiiRedactor\Detectors\EmailDetector;
use Padosoft\PiiRedactor\Exceptions\StrategyException;
use Padosoft\PiiRedactor\PiiRedactorServiceProvider;
use Padosoft\PiiRedactor\RedactorEngine;
use Padosoft\PiiRedactor\Strategies\HashStrategy;
use Padosoft\PiiRedactor\Strategies\MaskStrategy;
use Padosoft\PiiRedactor\Strategies\RedactionStrategy;
use Padosoft\PiiRedactor\Strategies\TokeniseStrategy;

/**
 * Wires the service provider into Testbench and asserts that the public
 * surface (engine, facade accessor, default strategy) resolves to the
 * shapes the README documents.
 */
final class ServiceProviderTest extends TestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PiiRedactorServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('pii-redactor.strategy', 'mask');
        $app['config']->set('pii-redactor.salt', 'test-salt-do-not-use-in-prod');
    }

    public function test_provider_is_a_laravel_service_provider_subclass(): void
    {
        $reflection = new \ReflectionClass(PiiRedactorServiceProvider::class);

        $this->assertTrue(
            $reflection->isSubclassOf(ServiceProvider::class),
            'PiiRedactorServiceProvider must extend Illuminate\Support\ServiceProvider for Laravel package auto-discovery to wire it.',
        );
    }

    public function test_engine_is_resolved_with_configured_detectors(): void
    {
        /** @var RedactorEngine $engine */
        $engine = $this->app->make(RedactorEngine::class);

        $this->assertInstanceOf(RedactorEngine::class, $engine);
        $this->assertNotEmpty($engine->detectors());
        $this->assertArrayHasKey('email', $engine->detectors());
        $this->assertInstanceOf(EmailDetector::class, $engine->detectors()['email']);
    }

    public function test_facade_alias_resolves_to_the_engine_singleton(): void
    {
        /** @var RedactorEngine $a */
        $a = $this->app->make('pii-redactor');
        /** @var RedactorEngine $b */
        $b = $this->app->make(RedactorEngine::class);

        $this->assertSame($a, $b);
    }

    public function test_default_strategy_is_mask(): void
    {
        /** @var RedactionStrategy $strategy */
        $strategy = $this->app->make(RedactionStrategy::class);

        $this->assertInstanceOf(MaskStrategy::class, $strategy);
    }

    public function test_hash_strategy_is_built_when_configured(): void
    {
        $this->app['config']->set('pii-redactor.strategy', 'hash');
        $this->app->forgetInstance(RedactionStrategy::class);

        /** @var RedactionStrategy $strategy */
        $strategy = $this->app->make(RedactionStrategy::class);

        $this->assertInstanceOf(HashStrategy::class, $strategy);
    }

    public function test_tokenise_strategy_is_built_when_configured(): void
    {
        $this->app['config']->set('pii-redactor.strategy', 'tokenise');
        $this->app->forgetInstance(RedactionStrategy::class);

        /** @var RedactionStrategy $strategy */
        $strategy = $this->app->make(RedactionStrategy::class);

        $this->assertInstanceOf(TokeniseStrategy::class, $strategy);
    }

    public function test_unknown_strategy_throws(): void
    {
        $this->app['config']->set('pii-redactor.strategy', 'made-up');
        $this->app->forgetInstance(RedactionStrategy::class);

        $this->expectException(StrategyException::class);
        $this->app->make(RedactionStrategy::class);
    }

    public function test_hash_strategy_requires_salt(): void
    {
        $this->app['config']->set('pii-redactor.strategy', 'hash');
        $this->app['config']->set('pii-redactor.salt', '');
        $this->app->forgetInstance(RedactionStrategy::class);

        $this->expectException(StrategyException::class);
        $this->app->make(RedactionStrategy::class);
    }

    public function test_disabled_engine_bypasses_redaction(): void
    {
        $this->app['config']->set('pii-redactor.enabled', false);
        $this->app->forgetInstance(RedactorEngine::class);

        /** @var RedactorEngine $engine */
        $engine = $this->app->make(RedactorEngine::class);

        $this->assertFalse($engine->isEnabled());
        $input = 'Email: mario.rossi@example.com';
        $this->assertSame($input, $engine->redact($input));
    }
}
