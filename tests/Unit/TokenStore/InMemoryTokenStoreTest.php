<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\TokenStore;

use Padosoft\PiiRedactor\TokenStore\InMemoryTokenStore;
use PHPUnit\Framework\TestCase;

final class InMemoryTokenStoreTest extends TestCase
{
    public function test_new_store_dump_is_empty(): void
    {
        $store = new InMemoryTokenStore;

        $this->assertSame([], $store->dump());
    }

    public function test_put_then_get_returns_original(): void
    {
        $store = new InMemoryTokenStore;
        $store->put('[tok:email:abcdef0123456789]', 'mario.rossi@example.com');

        $this->assertSame('mario.rossi@example.com', $store->get('[tok:email:abcdef0123456789]'));
    }

    public function test_get_returns_null_for_unknown_token(): void
    {
        $store = new InMemoryTokenStore;

        $this->assertNull($store->get('[tok:email:deadbeef]'));
    }

    public function test_has_reflects_presence(): void
    {
        $store = new InMemoryTokenStore;
        $store->put('[tok:email:abc]', 'one@x.com');

        $this->assertTrue($store->has('[tok:email:abc]'));
        $this->assertFalse($store->has('[tok:email:missing]'));
    }

    public function test_put_is_idempotent_on_duplicate_token(): void
    {
        $store = new InMemoryTokenStore;
        $store->put('[tok:email:abc]', 'one@x.com');
        $store->put('[tok:email:abc]', 'one@x.com');

        $this->assertSame('one@x.com', $store->get('[tok:email:abc]'));
        $this->assertCount(1, $store->dump());
    }

    public function test_clear_empties_the_store(): void
    {
        $store = new InMemoryTokenStore;
        $store->put('[tok:email:abc]', 'one@x.com');
        $store->put('[tok:email:def]', 'two@x.com');
        $store->clear();

        $this->assertSame([], $store->dump());
        $this->assertFalse($store->has('[tok:email:abc]'));
    }

    public function test_load_populates_then_dump_returns_loaded_map(): void
    {
        $store = new InMemoryTokenStore;
        $payload = [
            '[tok:email:111]' => 'alpha@x.com',
            '[tok:phone_it:222]' => '+39 333 1234567',
        ];

        $store->load($payload);

        $this->assertSame($payload, $store->dump());
        $this->assertSame('alpha@x.com', $store->get('[tok:email:111]'));
    }
}
