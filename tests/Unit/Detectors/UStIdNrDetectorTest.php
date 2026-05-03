<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Detectors;

use Padosoft\PiiRedactor\Detectors\UStIdNrDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * USt-IdNr test fixtures — fully synthetic.
 *
 * Generated via the BMF Method 30 mod-11 algorithm itself starting
 * from arbitrary 8-digit cores. No real-world VAT identifiers are
 * used.
 */
final class UStIdNrDetectorTest extends TestCase
{
    public function test_name_is_stable(): void
    {
        $this->assertSame('ust_idnr', (new UStIdNrDetector)->name());
    }

    /**
     * @return list<array{0: string}>
     */
    public static function validUstIdNrs(): array
    {
        return [
            ['DE123456788'],
            ['DE812345673'],
            ['DE998877660'],
            ['DE123456995'],
            ['DE135792460'],
            ['DE246813573'],
            ['DE111222339'],
            ['DE888999009'],
            ['DE765432106'],
            ['DE234567894'],
        ];
    }

    /**
     * @return list<array{0: string}>
     */
    public static function invalidChecksumUstIdNrs(): array
    {
        return [
            ['DE123456789'],   // valid prefix `12345678`, wrong check (correct is 8).
            ['DE812345674'],   // wrong check (correct is 3).
            ['DE998877661'],   // wrong check (correct is 0).
            ['DE111222330'],   // wrong check (correct is 9).
            ['DE765432107'],   // wrong check (correct is 6).
        ];
    }

    /**
     * @return list<array{0: string}>
     */
    public static function wrongFormatUstIdNrs(): array
    {
        return [
            ['DE12345678'],    // 8 digits (one short).
            ['DE1234567890'],  // 10 digits (one long).
            ['IT12345678901'], // wrong country prefix.
            ['DE12345678A'],   // letter in place of last digit.
            ['DEABCDEFGHI'],   // all letters after prefix.
        ];
    }

    #[DataProvider('validUstIdNrs')]
    public function test_accepts_valid_ust_idnr(string $value): void
    {
        $detector = new UStIdNrDetector;
        $hits = $detector->detect("USt-IdNr {$value} ausgestellt.");

        $this->assertCount(1, $hits, "Expected '{$value}' to validate");
        $this->assertSame($value, $hits[0]->value);
        $this->assertSame('ust_idnr', $hits[0]->detector);
    }

    #[DataProvider('invalidChecksumUstIdNrs')]
    public function test_rejects_invalid_checksum(string $value): void
    {
        $detector = new UStIdNrDetector;
        $hits = $detector->detect("USt-IdNr {$value} fehlerhaft.");

        $this->assertSame([], $hits, "Expected '{$value}' to fail checksum");
    }

    #[DataProvider('wrongFormatUstIdNrs')]
    public function test_rejects_wrong_format(string $value): void
    {
        $detector = new UStIdNrDetector;
        $hits = $detector->detect("USt-IdNr {$value}");

        $this->assertSame([], $hits, "Expected '{$value}' to fail format");
    }

    public function test_accepts_lowercase_de_prefix(): void
    {
        // Pattern uses `/i` flag — `de` prefix should also match.
        $detector = new UStIdNrDetector;
        $hits = $detector->detect('Rechnung mit de123456788 prüfen.');

        $this->assertCount(1, $hits);
        $this->assertSame('de123456788', $hits[0]->value);
    }

    public function test_finds_multiple_ust_idnrs(): void
    {
        $detector = new UStIdNrDetector;
        $text = 'Lieferanten: DE123456788 und DE812345673 ausgewiesen.';
        $hits = $detector->detect($text);

        $this->assertCount(2, $hits);
        $this->assertSame('DE123456788', $hits[0]->value);
        $this->assertSame('DE812345673', $hits[1]->value);
    }

    public function test_does_not_match_inside_longer_alphanumeric_string(): void
    {
        $detector = new UStIdNrDetector;

        // Word boundaries on either side block this.
        $this->assertSame([], $detector->detect('XDE123456788Y'));
    }

    public function test_returns_empty_array_on_empty_string(): void
    {
        $detector = new UStIdNrDetector;
        $this->assertSame([], $detector->detect(''));
    }

    public function test_returns_empty_array_when_no_match(): void
    {
        $detector = new UStIdNrDetector;
        $this->assertSame([], $detector->detect('Lorem ipsum dolor sit amet.'));
    }

    public function test_offset_and_length_are_correct(): void
    {
        $detector = new UStIdNrDetector;
        $text = 'USt-IdNr DE123456788 prüfen.';
        $hits = $detector->detect($text);

        $this->assertCount(1, $hits);
        $this->assertSame(strpos($text, 'DE123456788'), $hits[0]->offset);
        $this->assertSame(11, $hits[0]->length);
    }
}
