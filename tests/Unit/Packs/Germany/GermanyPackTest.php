<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Packs\Germany;

use Padosoft\PiiRedactor\Detectors\AddressGermanDetector;
use Padosoft\PiiRedactor\Detectors\Detector;
use Padosoft\PiiRedactor\Detectors\PhoneGermanDetector;
use Padosoft\PiiRedactor\Detectors\SteuerIdDetector;
use Padosoft\PiiRedactor\Detectors\UStIdNrDetector;
use Padosoft\PiiRedactor\Packs\Germany\GermanyPack;
use PHPUnit\Framework\TestCase;

final class GermanyPackTest extends TestCase
{
    public function test_name_is_germany(): void
    {
        $this->assertSame('germany', (new GermanyPack)->name());
    }

    public function test_country_code_is_uppercase_de(): void
    {
        $this->assertSame('DE', (new GermanyPack)->countryCode());
    }

    public function test_description_mentions_pack_calling_cards(): void
    {
        $description = (new GermanyPack)->description();

        $this->assertStringContainsString('German', $description);
        $this->assertStringContainsString('Steuer-ID', $description);
        $this->assertStringContainsString('USt-IdNr', $description);
    }

    public function test_detectors_lists_the_four_german_detectors_in_order(): void
    {
        $detectors = (new GermanyPack)->detectors();

        $this->assertCount(4, $detectors);

        $names = array_map(static fn (Detector $d): string => $d->name(), $detectors);

        $this->assertSame(
            ['steuer_id', 'ust_idnr', 'phone_de', 'address_de'],
            $names,
        );
    }

    public function test_detector_instances_have_the_expected_concrete_classes(): void
    {
        $detectors = (new GermanyPack)->detectors();

        $this->assertInstanceOf(SteuerIdDetector::class, $detectors[0]);
        $this->assertInstanceOf(UStIdNrDetector::class, $detectors[1]);
        $this->assertInstanceOf(PhoneGermanDetector::class, $detectors[2]);
        $this->assertInstanceOf(AddressGermanDetector::class, $detectors[3]);
    }

    public function test_pack_is_stateless_two_instances_yield_the_same_detector_names(): void
    {
        $first = array_map(
            static fn (Detector $d): string => $d->name(),
            (new GermanyPack)->detectors(),
        );

        $second = array_map(
            static fn (Detector $d): string => $d->name(),
            (new GermanyPack)->detectors(),
        );

        $this->assertSame($first, $second);
    }

    public function test_detectors_returns_a_fresh_list_on_every_call(): void
    {
        $pack = new GermanyPack;

        $first = $pack->detectors();
        $second = $pack->detectors();

        // Same names, but distinct object instances — required by
        // PackContract::detectors() ("Implementations MUST return a
        // fresh list on every call").
        $this->assertNotSame($first[0], $second[0]);
        $this->assertNotSame($first[3], $second[3]);
    }
}
