<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Detectors;

use Padosoft\PiiRedactor\Detectors\PartitaIvaDetector;
use PHPUnit\Framework\TestCase;

final class PartitaIvaDetectorTest extends TestCase
{
    public function test_name_is_stable(): void
    {
        $this->assertSame('p_iva', (new PartitaIvaDetector)->name());
    }

    public function test_detects_a_valid_partita_iva(): void
    {
        $detector = new PartitaIvaDetector;
        $hits = $detector->detect('La P.IVA è 12345678903 dello studio.');

        $this->assertCount(1, $hits);
        $this->assertSame('12345678903', $hits[0]->value);
        $this->assertSame('p_iva', $hits[0]->detector);
    }

    public function test_rejects_invalid_checksum(): void
    {
        // Last digit forced to wrong value.
        $detector = new PartitaIvaDetector;
        $hits = $detector->detect('Codice 12345678901 errato.');

        $this->assertSame([], $hits);
    }

    public function test_rejects_zero_payload(): void
    {
        $detector = new PartitaIvaDetector;
        $hits = $detector->detect('Sentinel: 00000000000.');

        $this->assertSame([], $hits);
    }

    public function test_rejects_wrong_length(): void
    {
        $detector = new PartitaIvaDetector;

        $this->assertSame([], $detector->detect('1234567890'));   // 10 digits.
        $this->assertSame([], $detector->detect('123456789012')); // 12 digits.
    }

    public function test_finds_multiple_partite_iva(): void
    {
        $detector = new PartitaIvaDetector;
        $text = 'Fatture: 12345678903 e 01234567897, totale 2.';
        $hits = $detector->detect($text);

        $this->assertCount(2, $hits);
        $this->assertSame('12345678903', $hits[0]->value);
        $this->assertSame('01234567897', $hits[1]->value);
    }
}
