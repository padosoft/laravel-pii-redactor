<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Detectors;

use Padosoft\PiiRedactor\Detectors\CreditCardDetector;
use PHPUnit\Framework\TestCase;

final class CreditCardDetectorTest extends TestCase
{
    public function test_name_is_stable(): void
    {
        $this->assertSame('credit_card', (new CreditCardDetector)->name());
    }

    public function test_detects_a_visa_test_pan(): void
    {
        // 4242424242424242 — the canonical Stripe Visa test PAN, Luhn-valid.
        $detector = new CreditCardDetector;
        $hits = $detector->detect('Carta 4242424242424242 scade 12/29.');

        $this->assertCount(1, $hits);
        $this->assertSame('4242424242424242', $hits[0]->value);
        $this->assertSame('credit_card', $hits[0]->detector);
    }

    public function test_detects_pan_with_spaces(): void
    {
        $detector = new CreditCardDetector;
        $hits = $detector->detect('Pago con 4242 4242 4242 4242 oggi.');

        $this->assertCount(1, $hits);
        $this->assertSame('4242 4242 4242 4242', $hits[0]->value);
    }

    public function test_rejects_invalid_luhn(): void
    {
        // Same shape, last digit broken.
        $detector = new CreditCardDetector;
        $hits = $detector->detect('Carta 4242424242424241 fasulla.');

        $this->assertSame([], $hits);
    }

    public function test_rejects_wrong_length(): void
    {
        $detector = new CreditCardDetector;

        $this->assertSame([], $detector->detect('1234567890'));      // too short.
        $this->assertSame([], $detector->detect('12345678901234567890')); // 20 digits.
    }
}
