<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Detectors;

use Padosoft\PiiRedactor\Detectors\IbanDetector;
use PHPUnit\Framework\TestCase;

final class IbanDetectorTest extends TestCase
{
    public function test_name_is_stable(): void
    {
        $this->assertSame('iban', (new IbanDetector)->name());
    }

    public function test_detects_an_italian_iban(): void
    {
        $detector = new IbanDetector;
        $hits = $detector->detect('Bonifico su IT60X0542811101000000123456 entro 30gg.');

        $this->assertCount(1, $hits);
        $this->assertSame('IT60X0542811101000000123456', $hits[0]->value);
        $this->assertSame('iban', $hits[0]->detector);
    }

    public function test_detects_a_german_iban(): void
    {
        $detector = new IbanDetector;
        $hits = $detector->detect('Wire to DE89370400440532013000 today.');

        $this->assertCount(1, $hits);
        $this->assertSame('DE89370400440532013000', $hits[0]->value);
    }

    public function test_rejects_invalid_mod97_checksum(): void
    {
        // Payload identical to a valid IT IBAN but with the last digit changed.
        $detector = new IbanDetector;
        $hits = $detector->detect('Bonifico su IT60X0542811101000000123457.');

        $this->assertSame([], $hits);
    }

    public function test_rejects_unknown_country(): void
    {
        $detector = new IbanDetector;
        // ZZ is not assigned in the IBAN registry.
        $hits = $detector->detect('Codice ZZ60X0542811101000000123456.');

        $this->assertSame([], $hits);
    }

    public function test_rejects_wrong_length_for_country(): void
    {
        $detector = new IbanDetector;

        $hits = $detector->detect('IT60X054281110100000012345');  // 26 chars (IT requires 27).

        $this->assertSame([], $hits);
    }
}
