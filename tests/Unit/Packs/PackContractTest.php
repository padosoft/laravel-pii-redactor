<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Packs;

use Padosoft\PiiRedactor\Detectors\Detector;
use Padosoft\PiiRedactor\Packs\Italy\ItalyPack;
use Padosoft\PiiRedactor\Packs\PackContract;
use PHPUnit\Framework\TestCase;

/**
 * Interface-level contract tests for PackContract.
 *
 * Uses ItalyPack as the concrete reference implementation. Future
 * community packs (GermanyPack, SpainPack, ...) can extend the same
 * shape by adding their own *PackTest counterpart; the contract test
 * here is the canonical baseline every pack must satisfy.
 */
final class PackContractTest extends TestCase
{
    public function test_italy_pack_implements_pack_contract(): void
    {
        $this->assertInstanceOf(PackContract::class, new ItalyPack);
    }

    public function test_name_is_lowercase_non_empty_string(): void
    {
        $name = (new ItalyPack)->name();

        $this->assertNotSame('', $name);
        $this->assertSame(strtolower($name), $name);
        $this->assertDoesNotMatchRegularExpression('/\s/', $name);
    }

    public function test_country_code_is_two_char_uppercase_iso_or_empty_for_region_packs(): void
    {
        $code = (new ItalyPack)->countryCode();

        // Single-country packs return ISO 3166-1 alpha-2; region packs return ''.
        if ($code === '') {
            $this->assertSame('', $code);

            return;
        }

        $this->assertSame(2, strlen($code));
        $this->assertSame(strtoupper($code), $code);
        $this->assertMatchesRegularExpression('/^[A-Z]{2}$/', $code);
    }

    public function test_description_is_non_empty_string(): void
    {
        $description = (new ItalyPack)->description();

        $this->assertNotSame('', $description);
    }

    public function test_detectors_returns_list_of_detector_instances(): void
    {
        $detectors = (new ItalyPack)->detectors();

        $this->assertNotEmpty($detectors);
        // list<T>: integer keys, sequential from 0.
        $this->assertSame(array_keys($detectors), range(0, count($detectors) - 1));

        foreach ($detectors as $detector) {
            $this->assertInstanceOf(Detector::class, $detector);
        }
    }

    public function test_detectors_returns_a_fresh_list_on_each_call(): void
    {
        $pack = new ItalyPack;

        $first = $pack->detectors();
        $originalCount = count($first);
        $this->assertGreaterThan(0, $originalCount);

        // Mutate the returned list aggressively: pop, unset, replace entries.
        // None of these mutations may leak into a subsequent call to detectors().
        array_pop($first);
        if ($first !== []) {
            unset($first[0]);
            $first[] = 'this is not a detector';
        }

        $second = $pack->detectors();

        $this->assertCount($originalCount, $second);
        $this->assertSame(array_keys($second), range(0, $originalCount - 1));
        foreach ($second as $detector) {
            $this->assertInstanceOf(Detector::class, $detector);
        }
    }
}
