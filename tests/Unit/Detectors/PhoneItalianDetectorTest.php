<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Detectors;

use Padosoft\PiiRedactor\Detectors\PhoneItalianDetector;
use PHPUnit\Framework\TestCase;

final class PhoneItalianDetectorTest extends TestCase
{
    public function test_name_is_stable(): void
    {
        $this->assertSame('phone_it', (new PhoneItalianDetector)->name());
    }

    public function test_detects_a_mobile_with_country_prefix(): void
    {
        $detector = new PhoneItalianDetector;
        $hits = $detector->detect('Chiama +39 333 1234567 quando puoi.');

        $this->assertCount(1, $hits);
        $this->assertSame('+39 333 1234567', $hits[0]->value);
    }

    public function test_detects_a_milan_landline(): void
    {
        $detector = new PhoneItalianDetector;
        $hits = $detector->detect('Ufficio 02 12345678 — orari 9-18.');

        $this->assertCount(1, $hits);
        $this->assertSame('02 12345678', $hits[0]->value);
    }

    public function test_detects_with_hyphen_separators(): void
    {
        $detector = new PhoneItalianDetector;
        $hits = $detector->detect('Numero: 06-1234567 ext 4.');

        $this->assertCount(1, $hits);
        $this->assertSame('06-1234567', $hits[0]->value);
    }

    public function test_rejects_short_numeric_strings(): void
    {
        $detector = new PhoneItalianDetector;

        $this->assertSame([], $detector->detect('Numero 123 troppo corto.'));
        $this->assertSame([], $detector->detect('Pin 06 fail.'));
    }

    public function test_does_not_match_in_the_middle_of_a_longer_numeric_string(): void
    {
        // Without a non-digit boundary on either side of the pattern, a
        // bare identifier like `id-99993331234567` would match its tail
        // `3331234567` as a phone number. The lookbehind `(?<![0-9+])`
        // and lookahead `(?!\d)` block that.
        $detector = new PhoneItalianDetector;

        $this->assertSame([], $detector->detect('Identificativo id-99993331234567 archiviato.'));
        $this->assertSame([], $detector->detect('Hash 833312345678abc.'));
    }

    public function test_does_not_match_when_followed_by_more_digits(): void
    {
        $detector = new PhoneItalianDetector;

        // 11+ contiguous mobile digits — the lookahead drops the match.
        $this->assertSame([], $detector->detect('Numero 33312345678901 invalid.'));
    }
}
