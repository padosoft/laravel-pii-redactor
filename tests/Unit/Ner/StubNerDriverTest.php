<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Ner;

use Padosoft\PiiRedactor\Ner\NerDriver;
use Padosoft\PiiRedactor\Ner\StubNerDriver;
use PHPUnit\Framework\TestCase;

final class StubNerDriverTest extends TestCase
{
    public function test_name_returns_stub(): void
    {
        $driver = new StubNerDriver;

        $this->assertSame('stub', $driver->name());
    }

    public function test_detect_returns_empty_list(): void
    {
        $driver = new StubNerDriver;

        $this->assertSame([], $driver->detect('Mario Rossi lavora a Roma.'));
        $this->assertSame([], $driver->detect(''));
    }

    public function test_implements_ner_driver_interface(): void
    {
        $this->assertInstanceOf(NerDriver::class, new StubNerDriver);
    }
}
