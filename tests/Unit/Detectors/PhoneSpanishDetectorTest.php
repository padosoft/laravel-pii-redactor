<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Detectors;

use Padosoft\PiiRedactor\Detectors\Detection;
use Padosoft\PiiRedactor\Detectors\PhoneSpanishDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PhoneSpanishDetectorTest extends TestCase
{
    public function test_name_is_stable(): void
    {
        $this->assertSame('phone_es', (new PhoneSpanishDetector)->name());
    }

    /**
     * 10 happy-path Spanish phone numbers covering mobile + landline
     * across all accepted formatting variants.
     *
     * @return list<array{0: string}>
     */
    public static function validPhones(): array
    {
        return [
            ['+34 612 345 678'],     // mobile, 3-3-3 with prefix + spaces
            ['+34 91 123 4567'],     // Madrid landline, 2-3-4 grouping
            ['+34 612345678'],       // contiguous body with prefix + space
            ['+34612345678'],        // contiguous body, no separators
            ['91 123 4567'],         // Madrid landline, no prefix
            ['912 345 678'],         // 3-3-3 landline, no prefix
            ['612 345 678'],         // 3-3-3 mobile, no prefix
            ['612-345-678'],         // hyphen separators
            ['612.345.678'],         // dot separators
            ['0034 612 345 678'],    // international dial-string prefix
        ];
    }

    #[DataProvider('validPhones')]
    public function test_detects_each_valid_phone(string $phone): void
    {
        $detector = new PhoneSpanishDetector;
        $hits = $detector->detect("Llamar al {$phone} cuando puedas.");

        $this->assertCount(1, $hits, "Expected '{$phone}' to match");
        $this->assertSame($phone, $hits[0]->value);
        $this->assertSame('phone_es', $hits[0]->detector);
    }

    /**
     * 5 negatives — short / long / wrong leading digit / mid-string runs.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function invalidPhones(): array
    {
        return [
            ['Pin 612 fail', 'too short'],
            ['Numero 12345678901 invalid', 'leading 1 not 6/7/8/9'],
            ['Hash 6123456789012abc', 'too many trailing digits'],
            ['Identificador id-66666123456789', 'embedded in longer numeric'],
            ['Codigo 512 345 678 nope', 'leading 5 not allowed'],
        ];
    }

    #[DataProvider('invalidPhones')]
    public function test_rejects_invalid_phones(string $text, string $reason): void
    {
        $detector = new PhoneSpanishDetector;
        $hits = $detector->detect($text);

        $this->assertSame([], $hits, "Expected no match ({$reason})");
    }

    public function test_finds_multiple_phones_in_one_text(): void
    {
        $detector = new PhoneSpanishDetector;
        $text = 'Móvil 612 345 678, fijo 912 345 678.';
        $hits = $detector->detect($text);

        $this->assertCount(2, $hits);
        $this->assertSame('612 345 678', $hits[0]->value);
        $this->assertSame('912 345 678', $hits[1]->value);
    }

    public function test_does_not_match_in_the_middle_of_a_longer_numeric_string(): void
    {
        // The lookbehind `(?<![0-9+])` and lookahead `(?!\d)` block
        // the regex from biting into longer numeric runs.
        $detector = new PhoneSpanishDetector;

        $this->assertSame([], $detector->detect('Identificador 999612345678 archivado.'));
        $this->assertSame([], $detector->detect('Hash 612345678901abc.'));
    }

    public function test_returns_detection_objects_with_correct_detector_name(): void
    {
        $detector = new PhoneSpanishDetector;
        $hits = $detector->detect('612 345 678');

        $this->assertCount(1, $hits);
        $this->assertInstanceOf(Detection::class, $hits[0]);
        $this->assertSame('phone_es', $hits[0]->detector);
        $this->assertSame(0, $hits[0]->offset);
        $this->assertSame(strlen('612 345 678'), $hits[0]->length);
    }

    public function test_returns_empty_array_on_empty_string(): void
    {
        $this->assertSame([], (new PhoneSpanishDetector)->detect(''));
    }
}
