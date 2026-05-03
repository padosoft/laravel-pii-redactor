<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Packs\Spain;

use Padosoft\PiiRedactor\Detectors\AddressSpanishDetector;
use Padosoft\PiiRedactor\Detectors\CifDetector;
use Padosoft\PiiRedactor\Detectors\Detector;
use Padosoft\PiiRedactor\Detectors\DniDetector;
use Padosoft\PiiRedactor\Detectors\NieDetector;
use Padosoft\PiiRedactor\Detectors\PhoneSpanishDetector;
use Padosoft\PiiRedactor\Packs\Spain\SpainPack;
use PHPUnit\Framework\TestCase;

final class SpainPackTest extends TestCase
{
    public function test_name_is_spain(): void
    {
        $this->assertSame('spain', (new SpainPack)->name());
    }

    public function test_country_code_is_uppercase_es(): void
    {
        $this->assertSame('ES', (new SpainPack)->countryCode());
    }

    public function test_description_mentions_pack_calling_cards(): void
    {
        $description = (new SpainPack)->description();

        $this->assertStringContainsString('Spanish', $description);
        $this->assertStringContainsString('DNI', $description);
        $this->assertStringContainsString('NIE', $description);
        $this->assertStringContainsString('CIF', $description);
    }

    public function test_detectors_lists_the_five_spanish_detectors_in_order(): void
    {
        $detectors = (new SpainPack)->detectors();

        $this->assertCount(5, $detectors);

        $names = array_map(static fn (Detector $d): string => $d->name(), $detectors);

        $this->assertSame(
            ['dni', 'nie', 'cif', 'phone_es', 'address_es'],
            $names,
        );
    }

    public function test_detector_instances_have_the_expected_concrete_classes(): void
    {
        $detectors = (new SpainPack)->detectors();

        $this->assertInstanceOf(DniDetector::class, $detectors[0]);
        $this->assertInstanceOf(NieDetector::class, $detectors[1]);
        $this->assertInstanceOf(CifDetector::class, $detectors[2]);
        $this->assertInstanceOf(PhoneSpanishDetector::class, $detectors[3]);
        $this->assertInstanceOf(AddressSpanishDetector::class, $detectors[4]);
    }

    public function test_pack_is_stateless_two_instances_yield_the_same_detector_names(): void
    {
        $first = array_map(
            static fn (Detector $d): string => $d->name(),
            (new SpainPack)->detectors(),
        );

        $second = array_map(
            static fn (Detector $d): string => $d->name(),
            (new SpainPack)->detectors(),
        );

        $this->assertSame($first, $second);
    }

    public function test_detectors_returns_fresh_instances_per_call(): void
    {
        // The contract requires a fresh list on every call (no mutation
        // shared across calls). Verify the array entries themselves are
        // not the same object identities between two calls.
        $first = (new SpainPack)->detectors();
        $second = (new SpainPack)->detectors();

        $this->assertNotSame($first[0], $second[0]);
        $this->assertNotSame($first[4], $second[4]);
    }
}
