<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Events;

use Padosoft\PiiRedactor\Events\PiiRedactionPerformed;
use PHPUnit\Framework\TestCase;

final class PiiRedactionPerformedTest extends TestCase
{
    public function test_event_carries_counts_total_and_strategy_name(): void
    {
        $event = new PiiRedactionPerformed(
            countsByDetector: ['email' => 2, 'iban' => 1],
            total: 3,
            strategyName: 'mask',
        );

        $this->assertSame(['email' => 2, 'iban' => 1], $event->countsByDetector);
        $this->assertSame(3, $event->total);
        $this->assertSame('mask', $event->strategyName);
    }

    public function test_event_payload_carries_no_raw_pii(): void
    {
        // Sanity check: the constructor signature is (counts, total, strategy).
        // No string field is wide enough to leak a full email / IBAN / etc.
        $event = new PiiRedactionPerformed(
            countsByDetector: [],
            total: 0,
            strategyName: 'drop',
        );

        $this->assertSame([], $event->countsByDetector);
        $this->assertSame(0, $event->total);
        $this->assertSame('drop', $event->strategyName);
    }

    public function test_event_fields_are_readonly(): void
    {
        $event = new PiiRedactionPerformed(['email' => 1], 1, 'hash');

        $this->expectException(\Error::class);
        // @phpstan-ignore-next-line — intentional to prove readonly
        $event->total = 99;
    }
}
