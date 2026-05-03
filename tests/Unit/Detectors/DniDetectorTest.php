<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Detectors;

use Padosoft\PiiRedactor\Detectors\Detection;
use Padosoft\PiiRedactor\Detectors\DniDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DniDetectorTest extends TestCase
{
    public function test_name_is_stable(): void
    {
        $this->assertSame('dni', (new DniDetector)->name());
    }

    /**
     * 10 valid synthetic DNIs with checksums computed via the
     * 23-letter table from RD 1553/2005.
     *
     * @return list<array{0: string}>
     */
    public static function validDnis(): array
    {
        return [
            ['12345678Z'],
            ['87654321X'],
            ['11111111H'],
            ['22222222J'],
            ['99999999R'],
            ['10203040X'],
            ['50607080L'],
            ['13579246T'],
            ['24681357B'],
            ['76543210S'],
        ];
    }

    #[DataProvider('validDnis')]
    public function test_detects_each_valid_dni(string $dni): void
    {
        $detector = new DniDetector;
        $hits = $detector->detect("DNI: {$dni}, registrado.");

        $this->assertCount(1, $hits, "Expected '{$dni}' to validate");
        $this->assertSame($dni, $hits[0]->value);
        $this->assertSame('dni', $hits[0]->detector);
    }

    /**
     * 5 invalid-checksum DNIs (correct shape, wrong control letter).
     *
     * @return list<array{0: string}>
     */
    public static function invalidChecksumDnis(): array
    {
        return [
            ['12345678A'],   // valid is Z
            ['87654321A'],   // valid is X
            ['11111111A'],   // valid is H
            ['22222222A'],   // valid is J
            ['99999999A'],   // valid is R
        ];
    }

    #[DataProvider('invalidChecksumDnis')]
    public function test_rejects_invalid_checksum(string $dni): void
    {
        $detector = new DniDetector;
        $hits = $detector->detect("DNI: {$dni}, dudoso.");

        $this->assertSame([], $hits, "Expected '{$dni}' to fail checksum");
    }

    /**
     * 5 wrong-format strings that must not match the regex.
     *
     * @return list<array{0: string}>
     */
    public static function wrongFormatDnis(): array
    {
        return [
            ['1234567A'],     // 7 digits
            ['123456789A'],   // 9 digits
            ['ABCDEFGHI'],    // all letters
            ['12345678'],     // missing letter
            ['1234567 8A'],   // embedded space
        ];
    }

    #[DataProvider('wrongFormatDnis')]
    public function test_rejects_wrong_format(string $dni): void
    {
        $detector = new DniDetector;
        $hits = $detector->detect("Token: {$dni}.");

        $this->assertSame([], $hits, "Expected '{$dni}' not to match");
    }

    public function test_detects_lowercase_input(): void
    {
        $detector = new DniDetector;
        $hits = $detector->detect('dni=12345678z end');

        $this->assertCount(1, $hits);
        $this->assertSame('12345678z', $hits[0]->value);
    }

    public function test_finds_multiple_dnis_in_one_text(): void
    {
        $detector = new DniDetector;
        $text = 'A: 12345678Z y B: 87654321X.';
        $hits = $detector->detect($text);

        $this->assertCount(2, $hits);
        $this->assertSame('12345678Z', $hits[0]->value);
        $this->assertSame('87654321X', $hits[1]->value);

        $this->assertSame(strpos($text, '12345678Z'), $hits[0]->offset);
        $this->assertSame(strpos($text, '87654321X'), $hits[1]->offset);
    }

    public function test_returns_detection_objects_with_correct_detector_name(): void
    {
        $detector = new DniDetector;
        $hits = $detector->detect('12345678Z');

        $this->assertCount(1, $hits);
        $this->assertInstanceOf(Detection::class, $hits[0]);
        $this->assertSame('dni', $hits[0]->detector);
        $this->assertSame(0, $hits[0]->offset);
        $this->assertSame(9, $hits[0]->length);
    }

    public function test_returns_empty_array_on_empty_string(): void
    {
        $this->assertSame([], (new DniDetector)->detect(''));
    }

    public function test_does_not_match_inside_longer_alphanumeric_run(): void
    {
        $detector = new DniDetector;
        // The `\b` word boundary means a DNI glued to another alpha
        // token won't match. e.g. `X12345678Z` is NOT a DNI shape, and
        // `12345678ZA` also fails the boundary on the right.
        $this->assertSame([], $detector->detect('X12345678Zq'));
        $this->assertSame([], $detector->detect('12345678ZA'));
    }
}
