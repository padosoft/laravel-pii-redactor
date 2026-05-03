<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Detectors;

use Padosoft\PiiRedactor\Detectors\SteuerIdDetector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Steuer-ID test fixtures — fully synthetic.
 *
 * The "valid" set was generated via the algorithm itself (ISO 7064
 * mod-11 pure) starting from arbitrary 10-digit cores that satisfy the
 * §139b AO Abs. 4 structural rule. The `65929970489` value is the
 * well-known synthetic example that appears in BMF documentation. No
 * real-world taxpayer identifiers are used.
 */
final class SteuerIdDetectorTest extends TestCase
{
    public function test_name_is_stable(): void
    {
        $this->assertSame('steuer_id', (new SteuerIdDetector)->name());
    }

    /**
     * @return list<array{0: string}>
     */
    public static function validSteuerIds(): array
    {
        return [
            ['65929970489'],
            ['01003246797'],
            ['01005384928'],
            ['01006493586'],
            ['01008235761'],
            ['01028904350'],
            ['01029854631'],
            ['01032467901'],
            ['01032547090'],
            ['01036902543'],
        ];
    }

    /**
     * @return list<array{0: string}>
     */
    public static function invalidChecksumSteuerIds(): array
    {
        // Same 10-digit prefix as the valid fixtures; last digit
        // shifted by +1 mod 10 so the format is right but the
        // checksum is wrong.
        return [
            ['65929970480'],
            ['01003246798'],
            ['01005384929'],
            ['01006493587'],
            ['01008235762'],
        ];
    }

    /**
     * @return list<array{0: string}>
     */
    public static function wrongFormatSteuerIds(): array
    {
        return [
            ['1234567890'],     // 10 digits — too short.
            ['123456789012'],   // 12 digits — too long.
            ['ABCDEFGHIJK'],    // alphabetic — not numeric.
            ['1234567890A'],    // 10 digits + letter — non-numeric tail.
            ['12345678901'],    // 11 digits but fails structural rule (every digit unique once).
        ];
    }

    #[DataProvider('validSteuerIds')]
    public function test_accepts_valid_steuer_ids(string $value): void
    {
        $detector = new SteuerIdDetector;
        $hits = $detector->detect("ID: {$value} archiviert.");

        $this->assertCount(1, $hits, "Expected '{$value}' to validate");
        $this->assertSame($value, $hits[0]->value);
        $this->assertSame('steuer_id', $hits[0]->detector);
    }

    #[DataProvider('invalidChecksumSteuerIds')]
    public function test_rejects_invalid_checksum(string $value): void
    {
        $detector = new SteuerIdDetector;
        $hits = $detector->detect("ID: {$value} fehlerhaft.");

        $this->assertSame([], $hits, "Expected '{$value}' to fail checksum");
    }

    #[DataProvider('wrongFormatSteuerIds')]
    public function test_rejects_wrong_format(string $value): void
    {
        $detector = new SteuerIdDetector;
        $hits = $detector->detect("ID: {$value}");

        $this->assertSame([], $hits, "Expected '{$value}' to fail format");
    }

    public function test_rejects_repdigit_sentinel(): void
    {
        $detector = new SteuerIdDetector;

        // 11111111111 — single digit appears 11 times, fails the
        // §139b AO structural rule (no digit may appear more than 3
        // times in the first 10).
        $this->assertSame([], $detector->detect('11111111111'));
        $this->assertSame([], $detector->detect('00000000000'));
    }

    public function test_rejects_sequential_pattern(): void
    {
        // 12345678901 — every digit unique once, fails the
        // structural rule (no digit appears 2 or 3 times).
        $detector = new SteuerIdDetector;

        $this->assertSame([], $detector->detect('Number: 12345678901.'));
    }

    public function test_finds_multiple_steuer_ids(): void
    {
        $detector = new SteuerIdDetector;
        $text = 'Akten: 65929970489 und 01003246797 archiviert.';
        $hits = $detector->detect($text);

        $this->assertCount(2, $hits);
        $this->assertSame('65929970489', $hits[0]->value);
        $this->assertSame('01003246797', $hits[1]->value);
    }

    public function test_does_not_match_inside_longer_numeric_string(): void
    {
        $detector = new SteuerIdDetector;

        // Word boundaries on either side block this — the embedded
        // 11-digit window inside `999659299704890` is not on a `\b`.
        $this->assertSame([], $detector->detect('Hash: 999659299704890.'));
    }

    public function test_returns_empty_array_on_empty_string(): void
    {
        $detector = new SteuerIdDetector;
        $this->assertSame([], $detector->detect(''));
    }

    public function test_returns_empty_array_when_no_match(): void
    {
        $detector = new SteuerIdDetector;
        $this->assertSame([], $detector->detect('Lorem ipsum dolor sit amet.'));
    }

    public function test_offset_and_length_are_correct(): void
    {
        $detector = new SteuerIdDetector;
        $text = 'Steuer-ID 65929970489 hier.';
        $hits = $detector->detect($text);

        $this->assertCount(1, $hits);
        $this->assertSame(strpos($text, '65929970489'), $hits[0]->offset);
        $this->assertSame(11, $hits[0]->length);
    }
}
