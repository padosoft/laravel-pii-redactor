<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Detectors;

use Padosoft\PiiRedactor\Detectors\Detection;
use Padosoft\PiiRedactor\Detectors\NieDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NieDetectorTest extends TestCase
{
    public function test_name_is_stable(): void
    {
        $this->assertSame('nie', (new NieDetector)->name());
    }

    /**
     * 10 valid synthetic NIEs spanning all three leading letters
     * (X / Y / Z). Checksum computed via the prefix-substituted DNI
     * algorithm (X→0, Y→1, Z→2; then mod 23 against the 23-letter
     * table).
     *
     * @return list<array{0: string}>
     */
    public static function validNies(): array
    {
        return [
            ['X1234567L'],
            ['Y0123456Y'],
            ['Z9876543A'],
            ['X7777777M'],
            ['Y1111111H'],
            ['Z2222222J'],
            ['X0000001R'],
            ['Y5555555B'],
            ['Z3141592G'],
            ['X8088088T'],
        ];
    }

    #[DataProvider('validNies')]
    public function test_detects_each_valid_nie(string $nie): void
    {
        $detector = new NieDetector;
        $hits = $detector->detect("NIE: {$nie}, asignado.");

        $this->assertCount(1, $hits, "Expected '{$nie}' to validate");
        $this->assertSame($nie, $hits[0]->value);
        $this->assertSame('nie', $hits[0]->detector);
    }

    /**
     * 5 invalid-checksum NIEs (correct shape, wrong control letter).
     *
     * @return list<array{0: string}>
     */
    public static function invalidChecksumNies(): array
    {
        return [
            ['X1234567A'],   // valid is L
            ['Y0123456A'],   // valid is Y
            ['Z9876543B'],   // valid is A
            ['X7777777A'],   // valid is M
            ['Y1111111A'],   // valid is H
        ];
    }

    #[DataProvider('invalidChecksumNies')]
    public function test_rejects_invalid_checksum(string $nie): void
    {
        $detector = new NieDetector;
        $hits = $detector->detect("NIE: {$nie}, dudoso.");

        $this->assertSame([], $hits, "Expected '{$nie}' to fail checksum");
    }

    /**
     * 5 wrong-format strings — bad leading letter / wrong digit count /
     * non-digit body.
     *
     * @return list<array{0: string}>
     */
    public static function wrongFormatNies(): array
    {
        return [
            ['Q1234567A'],    // Q is not X/Y/Z
            ['12345678A'],    // missing leading letter (this is DNI shape)
            ['X12345A'],      // 5 digits
            ['X12345678A'],   // 8 digits
            ['XABCDEFGA'],    // letters in body
        ];
    }

    #[DataProvider('wrongFormatNies')]
    public function test_rejects_wrong_format(string $nie): void
    {
        $detector = new NieDetector;
        $hits = $detector->detect("Token: {$nie}.");

        $this->assertSame([], $hits, "Expected '{$nie}' not to match as NIE");
    }

    public function test_detects_lowercase_input(): void
    {
        $detector = new NieDetector;
        $hits = $detector->detect('nie=x1234567l ok');

        $this->assertCount(1, $hits);
        $this->assertSame('x1234567l', $hits[0]->value);
    }

    public function test_finds_multiple_nies_in_one_text(): void
    {
        $detector = new NieDetector;
        $text = 'Alta: X1234567L y Y0123456Y aprobadas.';
        $hits = $detector->detect($text);

        $this->assertCount(2, $hits);
        $this->assertSame('X1234567L', $hits[0]->value);
        $this->assertSame('Y0123456Y', $hits[1]->value);

        $this->assertSame(strpos($text, 'X1234567L'), $hits[0]->offset);
        $this->assertSame(strpos($text, 'Y0123456Y'), $hits[1]->offset);
    }

    public function test_returns_detection_objects_with_correct_detector_name(): void
    {
        $detector = new NieDetector;
        $hits = $detector->detect('X1234567L');

        $this->assertCount(1, $hits);
        $this->assertInstanceOf(Detection::class, $hits[0]);
        $this->assertSame('nie', $hits[0]->detector);
        $this->assertSame(0, $hits[0]->offset);
        $this->assertSame(9, $hits[0]->length);
    }

    public function test_returns_empty_array_on_empty_string(): void
    {
        $this->assertSame([], (new NieDetector)->detect(''));
    }
}
