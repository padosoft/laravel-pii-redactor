<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Strategies;

use Padosoft\PiiRedactor\Strategies\MaskStrategy;
use PHPUnit\Framework\TestCase;

final class MaskStrategyTest extends TestCase
{
    public function test_default_mask_token(): void
    {
        $strategy = new MaskStrategy;
        $this->assertSame('mask', $strategy->name());
        $this->assertSame('[REDACTED]', $strategy->apply('mario.rossi@example.com', 'email'));
    }

    public function test_custom_mask_token(): void
    {
        $strategy = new MaskStrategy('***');
        $this->assertSame('***', $strategy->apply('IT60X0542811101000000123456', 'iban'));
    }
}
