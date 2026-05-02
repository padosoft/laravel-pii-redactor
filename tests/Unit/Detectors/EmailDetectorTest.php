<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Detectors;

use Padosoft\PiiRedactor\Detectors\EmailDetector;
use PHPUnit\Framework\TestCase;

final class EmailDetectorTest extends TestCase
{
    public function test_name_is_stable(): void
    {
        $this->assertSame('email', (new EmailDetector)->name());
    }

    public function test_detects_a_simple_email(): void
    {
        $detector = new EmailDetector;
        $hits = $detector->detect('Scrivi a mario.rossi@example.com per dettagli.');

        $this->assertCount(1, $hits);
        $this->assertSame('mario.rossi@example.com', $hits[0]->value);
        $this->assertSame('email', $hits[0]->detector);
    }

    public function test_detects_addresses_with_plus_and_underscore(): void
    {
        $detector = new EmailDetector;
        $hits = $detector->detect('Tag: a+filter@x.io and another_user@sub.domain.co.uk');

        $this->assertCount(2, $hits);
        $this->assertSame('a+filter@x.io', $hits[0]->value);
        $this->assertSame('another_user@sub.domain.co.uk', $hits[1]->value);
    }

    public function test_rejects_obvious_non_email(): void
    {
        $detector = new EmailDetector;
        $this->assertSame([], $detector->detect('user@'));
        $this->assertSame([], $detector->detect('@domain.com'));
        $this->assertSame([], $detector->detect('plain text without ats'));
    }
}
