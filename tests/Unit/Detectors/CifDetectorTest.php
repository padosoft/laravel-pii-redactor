<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Detectors;

use Padosoft\PiiRedactor\Detectors\CifDetector;
use Padosoft\PiiRedactor\Detectors\Detection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CifDetectorTest extends TestCase
{
    public function test_name_is_stable(): void
    {
        $this->assertSame('cif', (new CifDetector)->name());
    }

    /**
     * 10 valid synthetic CIFs covering BOTH control-character branches:
     *
     *  - First six: leading letters K / P / Q / S / N / W → control is
     *    a letter from JABCDEFGHI (the letter-control branch).
     *  - Last four: leading letters A / B / C / D → control is a digit
     *    (the digit-control branch).
     *
     * Checksums computed via the AEAT spec algorithm.
     *
     * @return list<array{0: string}>
     */
    public static function validCifs(): array
    {
        return [
            ['K1234567D'],
            ['P7654321D'],
            ['Q2468013D'],
            ['S1357902D'],
            ['N5050505F'],
            ['W9090909D'],
            ['A12345674'],
            ['B76543214'],
            ['C40302010'],
            ['D51515153'],
        ];
    }

    #[DataProvider('validCifs')]
    public function test_detects_each_valid_cif(string $cif): void
    {
        $detector = new CifDetector;
        $hits = $detector->detect("CIF: {$cif}, registrado.");

        $this->assertCount(1, $hits, "Expected '{$cif}' to validate");
        $this->assertSame($cif, $hits[0]->value);
        $this->assertSame('cif', $hits[0]->detector);
    }

    /**
     * 5 invalid-checksum CIFs (correct shape, wrong control character).
     *
     * @return list<array{0: string}>
     */
    public static function invalidChecksumCifs(): array
    {
        return [
            ['K1234567A'],   // valid control is D
            ['P7654321A'],   // valid control is D
            ['Q2468013A'],   // valid control is D
            ['S1357902A'],   // valid control is D
            ['N5050505A'],   // valid control is F
        ];
    }

    #[DataProvider('invalidChecksumCifs')]
    public function test_rejects_invalid_checksum(string $cif): void
    {
        $detector = new CifDetector;
        $hits = $detector->detect("CIF: {$cif}, dudoso.");

        $this->assertSame([], $hits, "Expected '{$cif}' to fail checksum");
    }

    /**
     * 5 wrong-format strings — bad leading letter / wrong digit count /
     * letters in the body / leading digit instead of letter.
     *
     * @return list<array{0: string}>
     */
    public static function wrongFormatCifs(): array
    {
        return [
            ['T1234567A'],     // T is not a valid CIF leader
            ['1234567A0'],     // leads with digit
            ['A123456'],       // 6 digits only
            ['A12345678A'],    // 8 digits
            ['AB123456A'],     // two leading letters
        ];
    }

    #[DataProvider('wrongFormatCifs')]
    public function test_rejects_wrong_format(string $cif): void
    {
        $detector = new CifDetector;
        $hits = $detector->detect("Token: {$cif}.");

        $this->assertSame([], $hits, "Expected '{$cif}' not to match as CIF");
    }

    public function test_detects_lowercase_input(): void
    {
        $detector = new CifDetector;
        // Same payload as 'K1234567D' but lowercased on the leading
        // letter and the control character. The validator uppercases
        // before computing the checksum.
        $hits = $detector->detect('cif=k1234567d archived');

        $this->assertCount(1, $hits);
        $this->assertSame('k1234567d', $hits[0]->value);
    }

    public function test_finds_multiple_cifs_in_one_text(): void
    {
        $detector = new CifDetector;
        $text = 'Sociedad: A12345674 y proveedor: B76543214 archivados.';
        $hits = $detector->detect($text);

        $this->assertCount(2, $hits);
        $this->assertSame('A12345674', $hits[0]->value);
        $this->assertSame('B76543214', $hits[1]->value);
    }

    public function test_letter_control_group_rejects_digit_control(): void
    {
        // K-leading must take a LETTER control from JABCDEFGHI; using a
        // digit control on a K-leading CIF must always fail, even if
        // the digit happens to equal the C value.
        // K1234567 → expectedC = 3 (yields letter 'D'). A '3' digit
        // control on this leader must therefore be rejected.
        $detector = new CifDetector;
        $this->assertSame([], $detector->detect('CIF: K12345673.'));
    }

    public function test_digit_control_group_rejects_letter_control(): void
    {
        // A-leading must take a DIGIT control. The valid digit for
        // A1234567 is '4'. Using letter 'D' (which would be the
        // letter-control output for the same C value 3) on an
        // A-leading CIF must be rejected — it's the wrong group.
        $detector = new CifDetector;
        $this->assertSame([], $detector->detect('CIF: A1234567D.'));
    }

    public function test_returns_detection_objects_with_correct_detector_name(): void
    {
        $detector = new CifDetector;
        $hits = $detector->detect('K1234567D');

        $this->assertCount(1, $hits);
        $this->assertInstanceOf(Detection::class, $hits[0]);
        $this->assertSame('cif', $hits[0]->detector);
        $this->assertSame(0, $hits[0]->offset);
        $this->assertSame(9, $hits[0]->length);
    }

    public function test_returns_empty_array_on_empty_string(): void
    {
        $this->assertSame([], (new CifDetector)->detect(''));
    }
}
