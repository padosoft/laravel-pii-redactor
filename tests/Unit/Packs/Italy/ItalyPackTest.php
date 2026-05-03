<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Packs\Italy;

use Padosoft\PiiRedactor\Detectors\AddressItalianDetector;
use Padosoft\PiiRedactor\Detectors\CodiceFiscaleDetector;
use Padosoft\PiiRedactor\Detectors\Detector;
use Padosoft\PiiRedactor\Detectors\PartitaIvaDetector;
use Padosoft\PiiRedactor\Detectors\PhoneItalianDetector;
use Padosoft\PiiRedactor\Packs\Italy\ItalyPack;
use PHPUnit\Framework\TestCase;

final class ItalyPackTest extends TestCase
{
    public function test_name_is_italy(): void
    {
        $this->assertSame('italy', (new ItalyPack)->name());
    }

    public function test_country_code_is_uppercase_it(): void
    {
        $this->assertSame('IT', (new ItalyPack)->countryCode());
    }

    public function test_description_mentions_pack_calling_cards(): void
    {
        $description = (new ItalyPack)->description();

        $this->assertStringContainsString('Italian', $description);
        $this->assertStringContainsString('codice fiscale', $description);
        $this->assertStringContainsString('partita IVA', $description);
    }

    public function test_detectors_lists_the_four_italian_detectors_in_order(): void
    {
        $detectors = (new ItalyPack)->detectors();

        $this->assertCount(4, $detectors);

        $names = array_map(static fn (Detector $d): string => $d->name(), $detectors);

        $this->assertSame(
            ['codice_fiscale', 'p_iva', 'phone_it', 'address_it'],
            $names,
        );
    }

    public function test_detector_instances_have_the_expected_concrete_classes(): void
    {
        $detectors = (new ItalyPack)->detectors();

        $this->assertInstanceOf(CodiceFiscaleDetector::class, $detectors[0]);
        $this->assertInstanceOf(PartitaIvaDetector::class, $detectors[1]);
        $this->assertInstanceOf(PhoneItalianDetector::class, $detectors[2]);
        $this->assertInstanceOf(AddressItalianDetector::class, $detectors[3]);
    }

    public function test_pack_is_stateless_two_instances_yield_the_same_detector_names(): void
    {
        $first = array_map(
            static fn (Detector $d): string => $d->name(),
            (new ItalyPack)->detectors(),
        );

        $second = array_map(
            static fn (Detector $d): string => $d->name(),
            (new ItalyPack)->detectors(),
        );

        $this->assertSame($first, $second);
    }
}
