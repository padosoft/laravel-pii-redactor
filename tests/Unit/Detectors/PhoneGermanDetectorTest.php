<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Detectors;

use Padosoft\PiiRedactor\Detectors\PhoneGermanDetector;
use PHPUnit\Framework\TestCase;

final class PhoneGermanDetectorTest extends TestCase
{
    public function test_name_is_stable(): void
    {
        $this->assertSame('phone_de', (new PhoneGermanDetector)->name());
    }

    public function test_detects_berlin_landline_with_plus_49(): void
    {
        $detector = new PhoneGermanDetector;
        $hits = $detector->detect('Anruf an +49 30 12345678 zwischen 9 und 17 Uhr.');

        $this->assertCount(1, $hits);
        $this->assertSame('+49 30 12345678', $hits[0]->value);
    }

    public function test_detects_berlin_landline_with_carrier_trunk_zero(): void
    {
        $detector = new PhoneGermanDetector;
        $hits = $detector->detect('Anruf an +49 (0) 30 12345678 oder Mailbox.');

        $this->assertCount(1, $hits);
        $this->assertSame('+49 (0) 30 12345678', $hits[0]->value);
    }

    public function test_detects_berlin_landline_national_format(): void
    {
        $detector = new PhoneGermanDetector;
        $hits = $detector->detect('Büro 030 12345678 erreichbar.');

        $this->assertCount(1, $hits);
        $this->assertSame('030 12345678', $hits[0]->value);
    }

    public function test_detects_munich_landline_national_format(): void
    {
        $detector = new PhoneGermanDetector;
        $hits = $detector->detect('Standort 089 1234567 anrufen.');

        $this->assertCount(1, $hits);
        $this->assertSame('089 1234567', $hits[0]->value);
    }

    public function test_detects_mobile_with_country_prefix_and_space(): void
    {
        $detector = new PhoneGermanDetector;
        $hits = $detector->detect('Mobil +49 151 12345678 immer erreichbar.');

        $this->assertCount(1, $hits);
        $this->assertSame('+49 151 12345678', $hits[0]->value);
    }

    public function test_detects_mobile_with_country_prefix_and_no_separators(): void
    {
        $detector = new PhoneGermanDetector;
        $hits = $detector->detect('SMS an +4915112345678 ok.');

        $this->assertCount(1, $hits);
        $this->assertSame('+4915112345678', $hits[0]->value);
    }

    public function test_detects_mobile_in_national_format(): void
    {
        $detector = new PhoneGermanDetector;
        $hits = $detector->detect('Handy 0151 12345678 senden.');

        $this->assertCount(1, $hits);
        $this->assertSame('0151 12345678', $hits[0]->value);
    }

    public function test_detects_mobile_d2_prefix_0160(): void
    {
        $detector = new PhoneGermanDetector;
        $hits = $detector->detect('Erreichbar 0160 1234567 abends.');

        $this->assertCount(1, $hits);
        $this->assertSame('0160 1234567', $hits[0]->value);
    }

    public function test_detects_mobile_e_plus_prefix_0170(): void
    {
        $detector = new PhoneGermanDetector;
        $hits = $detector->detect('Festnetz 0170 12345678 sehen.');

        $this->assertCount(1, $hits);
        $this->assertSame('0170 12345678', $hits[0]->value);
    }

    public function test_detects_with_zero_zero_intl_prefix(): void
    {
        $detector = new PhoneGermanDetector;
        $hits = $detector->detect('Bitte 0049 30 12345678 wählen.');

        $this->assertCount(1, $hits);
        $this->assertSame('0049 30 12345678', $hits[0]->value);
    }

    public function test_detects_with_hyphen_separator(): void
    {
        $detector = new PhoneGermanDetector;
        $hits = $detector->detect('Empfang 030-12345678 abnehmen.');

        $this->assertCount(1, $hits);
        $this->assertSame('030-12345678', $hits[0]->value);
    }

    public function test_rejects_short_numeric_strings(): void
    {
        $detector = new PhoneGermanDetector;

        // After stripping separators, fewer than 7 digits remain — guard kicks in.
        $this->assertSame([], $detector->detect('Notruf 110 wählen.'));
        $this->assertSame([], $detector->detect('Notruf 112 schnell.'));
    }

    public function test_rejects_pure_text(): void
    {
        $detector = new PhoneGermanDetector;
        $this->assertSame([], $detector->detect('Rufen Sie uns gerne an.'));
    }

    public function test_does_not_match_in_the_middle_of_a_longer_numeric_string(): void
    {
        // The non-digit lookbehind / lookahead block this.
        $detector = new PhoneGermanDetector;

        $this->assertSame([], $detector->detect('Hash 999015112345678abc.'));
    }

    public function test_does_not_match_when_followed_by_more_digits(): void
    {
        $detector = new PhoneGermanDetector;

        // 11+ contiguous mobile digits — the lookahead drops the match.
        $this->assertSame([], $detector->detect('Number 015112345678901 invalid.'));
    }

    public function test_finds_multiple_phone_numbers(): void
    {
        $detector = new PhoneGermanDetector;
        $text = 'Rückruf: +49 30 12345678 oder mobil 0151 12345678.';
        $hits = $detector->detect($text);

        $this->assertCount(2, $hits);
        $this->assertSame('+49 30 12345678', $hits[0]->value);
        $this->assertSame('0151 12345678', $hits[1]->value);
    }

    public function test_returns_empty_array_on_empty_string(): void
    {
        $detector = new PhoneGermanDetector;
        $this->assertSame([], $detector->detect(''));
    }
}
