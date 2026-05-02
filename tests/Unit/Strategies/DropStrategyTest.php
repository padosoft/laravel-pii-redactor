<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Strategies;

use Padosoft\PiiRedactor\Strategies\DropStrategy;
use PHPUnit\Framework\TestCase;

final class DropStrategyTest extends TestCase
{
    public function test_returns_empty_string_for_every_input(): void
    {
        $strategy = new DropStrategy;

        $this->assertSame('drop', $strategy->name());
        $this->assertSame('', $strategy->apply('mario.rossi@example.com', 'email'));
        $this->assertSame('', $strategy->apply('IT60X0542811101000000123456', 'iban'));
        $this->assertSame('', $strategy->apply('', 'whatever'));
    }
}
