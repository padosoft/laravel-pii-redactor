<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\TokenStore;

use Padosoft\PiiRedactor\TokenStore\TokenResolutionService;
use Padosoft\PiiRedactor\TokenStore\TokenStore;
use PHPUnit\Framework\TestCase;

final class TokenResolutionServiceTest extends TestCase
{
    public function test_detokenises_string_without_current_tokenise_strategy(): void
    {
        $store = new SpyTokenStore([
            '[tok:email:abc123]' => 'mario@example.com',
            '[tok:phone_it:def456]' => '+39 333 1234567',
        ]);
        $service = new TokenResolutionService($store);

        $result = $service->detokeniseString('Email [tok:email:abc123], tel [tok:phone_it:def456].');

        $this->assertSame('Email mario@example.com, tel +39 333 1234567.', $result->output);
        $this->assertSame(2, $result->tokenCount);
        $this->assertSame(2, $result->resolvedCount);
        $this->assertSame([], $result->unresolvedTokens);
    }

    public function test_reports_unresolved_tokens(): void
    {
        $service = new TokenResolutionService(new SpyTokenStore([
            '[tok:email:abc123]' => 'mario@example.com',
        ]));

        $result = $service->detokeniseString('[tok:email:abc123] [tok:iban:deadbeef]');

        $this->assertSame('mario@example.com [tok:iban:deadbeef]', $result->output);
        $this->assertSame(2, $result->tokenCount);
        $this->assertSame(1, $result->resolvedCount);
        $this->assertSame(['[tok:iban:deadbeef]'], $result->unresolvedTokens);
    }

    public function test_ignores_non_tokenise_placeholders(): void
    {
        $service = new TokenResolutionService(new SpyTokenStore([]));

        $result = $service->detokeniseString('[hash:abc] [REDACTED] [tok:email:not-hex]');

        $this->assertSame('[hash:abc] [REDACTED] [tok:email:not-hex]', $result->output);
        $this->assertSame(0, $result->tokenCount);
    }

    public function test_does_not_call_token_store_dump(): void
    {
        $store = new SpyTokenStore(['[tok:email:abc123]' => 'mario@example.com']);
        $service = new TokenResolutionService($store);

        $service->detokeniseString('[tok:email:abc123]');

        $this->assertSame(0, $store->dumpCalls);
        $this->assertSame(1, $store->getCalls);
    }

    public function test_result_to_array_uses_api_friendly_keys(): void
    {
        $service = new TokenResolutionService(new SpyTokenStore([]));

        $this->assertSame([
            'output' => '[tok:email:abc123]',
            'token_count' => 1,
            'resolved_count' => 0,
            'unresolved_tokens' => ['[tok:email:abc123]'],
        ], $service->detokeniseString('[tok:email:abc123]')->toArray());
    }
}

final class SpyTokenStore implements TokenStore
{
    public int $getCalls = 0;

    public int $dumpCalls = 0;

    /**
     * @param  array<string, string>  $map
     */
    public function __construct(public array $map) {}

    public function put(string $token, string $original): void
    {
        $this->map[$token] = $original;
    }

    public function get(string $token): ?string
    {
        $this->getCalls++;

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
        $this->dumpCalls++;

        return $this->map;
    }

    public function load(array $map): void
    {
        $this->map = $map;
    }
}
