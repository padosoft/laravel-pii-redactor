<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Detectors;

use Padosoft\PiiRedactor\Detectors\AddressGermanDetector;
use Padosoft\PiiRedactor\Detectors\Detection;
use PHPUnit\Framework\TestCase;

final class AddressGermanDetectorTest extends TestCase
{
    public function test_name_is_stable(): void
    {
        $this->assertSame('address_de', (new AddressGermanDetector)->name());
    }

    public function test_detects_basic_strasse_with_civic_number(): void
    {
        $detector = new AddressGermanDetector;
        $text = 'Wohne Berliner Straße 12 seit Jahren.';
        $hits = $detector->detect($text);

        $this->assertCount(1, $hits, 'expected exactly one address detection — extra hits indicate detector regression');
        $this->assertSame('Berliner Straße 12', $hits[0]->value);
        $this->assertSame(strpos($text, 'Berliner Straße 12'), $hits[0]->offset);
    }

    public function test_detects_compound_hauptstrasse_glued(): void
    {
        $detector = new AddressGermanDetector;
        $hits = $detector->detect('Treffpunkt Hauptstraße 5 morgen früh.');

        $this->assertCount(1, $hits, 'expected exactly one address detection — extra hits indicate detector regression');
        $this->assertSame('Hauptstraße 5', $hits[0]->value);
    }

    public function test_detects_abbreviated_str_period(): void
    {
        $detector = new AddressGermanDetector;
        $hits = $detector->detect('Lager Hauptstr. 5 abholen.');

        $this->assertCount(1, $hits, 'expected exactly one address detection — extra hits indicate detector regression');
        $this->assertSame('Hauptstr. 5', $hits[0]->value);
    }

    public function test_detects_hyphenated_compound_with_str_period(): void
    {
        $detector = new AddressGermanDetector;
        $hits = $detector->detect('Büro Friedrich-Ebert-Str. 12 erreichen.');

        $this->assertCount(1, $hits, 'expected exactly one address detection — extra hits indicate detector regression');
        $this->assertSame('Friedrich-Ebert-Str. 12', $hits[0]->value);
    }

    public function test_detects_hyphenated_compound_with_allee(): void
    {
        $detector = new AddressGermanDetector;
        $hits = $detector->detect('Eingang Friedrich-Ebert-Allee 32 öffnet.');

        $this->assertCount(1, $hits, 'expected exactly one address detection — extra hits indicate detector regression');
        $this->assertSame('Friedrich-Ebert-Allee 32', $hits[0]->value);
    }

    public function test_detects_marktplatz_glued(): void
    {
        $detector = new AddressGermanDetector;
        $hits = $detector->detect('Stand Marktplatz 1 freuen.');

        $this->assertCount(1, $hits, 'expected exactly one address detection — extra hits indicate detector regression');
        $this->assertSame('Marktplatz 1', $hits[0]->value);
    }

    public function test_detects_weg_suffix(): void
    {
        $detector = new AddressGermanDetector;
        $hits = $detector->detect('Pension Goetheweg 7 buchen.');

        $this->assertCount(1, $hits, 'expected exactly one address detection — extra hits indicate detector regression');
        $this->assertSame('Goetheweg 7', $hits[0]->value);
    }

    public function test_detects_gasse_suffix(): void
    {
        $detector = new AddressGermanDetector;
        $hits = $detector->detect('Restaurant Sandgasse 4 reservieren.');

        $this->assertCount(1, $hits, 'expected exactly one address detection — extra hits indicate detector regression');
        $this->assertSame('Sandgasse 4', $hits[0]->value);
    }

    public function test_detects_ring_suffix(): void
    {
        $detector = new AddressGermanDetector;
        $hits = $detector->detect('Adresse Stadtring 22 zuschicken.');

        $this->assertCount(1, $hits, 'expected exactly one address detection — extra hits indicate detector regression');
        $this->assertSame('Stadtring 22', $hits[0]->value);
    }

    public function test_detects_damm_suffix(): void
    {
        $detector = new AddressGermanDetector;
        $hits = $detector->detect('Treffen Kurfürstendamm 195 vereinbaren.');

        $this->assertCount(1, $hits, 'expected exactly one address detection — extra hits indicate detector regression');
        $this->assertSame('Kurfürstendamm 195', $hits[0]->value);
    }

    public function test_detects_ufer_suffix(): void
    {
        $detector = new AddressGermanDetector;
        $hits = $detector->detect('Spaziergang Mainufer 10 nachmittags.');

        $this->assertCount(1, $hits, 'expected exactly one address detection — extra hits indicate detector regression');
        $this->assertSame('Mainufer 10', $hits[0]->value);
    }

    public function test_detects_hof_suffix(): void
    {
        $detector = new AddressGermanDetector;
        $hits = $detector->detect('Sitz Lindenhof 3 ansehen.');

        $this->assertCount(1, $hits, 'expected exactly one address detection — extra hits indicate detector regression');
        $this->assertSame('Lindenhof 3', $hits[0]->value);
    }

    public function test_detects_prefix_form_am(): void
    {
        $detector = new AddressGermanDetector;
        $hits = $detector->detect('Standort Am Ring 7 anlaufen.');

        $this->assertCount(1, $hits, 'expected exactly one address detection — extra hits indicate detector regression');
        $this->assertSame('Am Ring 7', $hits[0]->value);
    }

    public function test_detects_prefix_form_an_der(): void
    {
        $detector = new AddressGermanDetector;
        $hits = $detector->detect('Café An der Alster 12 öffnet.');

        $this->assertCount(1, $hits, 'expected exactly one address detection — extra hits indicate detector regression');
        $this->assertSame('An der Alster 12', $hits[0]->value);
    }

    public function test_detects_prefix_form_unter_den_linden(): void
    {
        $detector = new AddressGermanDetector;
        $hits = $detector->detect('Treffpunkt Unter den Linden 5 prüfen.');

        $this->assertCount(1, $hits, 'expected exactly one address detection — extra hits indicate detector regression');
        $this->assertSame('Unter den Linden 5', $hits[0]->value);
    }

    public function test_detects_civic_number_with_letter_suffix(): void
    {
        $detector = new AddressGermanDetector;
        $hits = $detector->detect('Adresse Hauptstr. 5a hier.');

        $this->assertCount(1, $hits, 'expected exactly one address detection — extra hits indicate detector regression');
        $this->assertSame('Hauptstr. 5a', $hits[0]->value);
    }

    public function test_detects_civic_number_range(): void
    {
        $detector = new AddressGermanDetector;
        $hits = $detector->detect('Bauplatz Hauptstr. 5-9 versteigern.');

        $this->assertCount(1, $hits, 'expected exactly one address detection — extra hits indicate detector regression');
        $this->assertSame('Hauptstr. 5-9', $hits[0]->value);
    }

    public function test_detects_plz_and_city_when_civic_present(): void
    {
        $detector = new AddressGermanDetector;
        $hits = $detector->detect('Versand an Berliner Straße 12, 10115 Berlin liefern.');

        $this->assertCount(1, $hits, 'expected exactly one address detection — extra hits indicate detector regression');
        $this->assertSame('Berliner Straße 12, 10115 Berlin', $hits[0]->value);
    }

    public function test_does_not_detect_lowercase_street_name(): void
    {
        $detector = new AddressGermanDetector;
        // Proper-noun anchor — name must start with uppercase.
        $hits = $detector->detect('die berliner straße ist lang.');

        $this->assertSame([], $hits);
    }

    public function test_does_not_detect_typo_with_wrong_letters(): void
    {
        $detector = new AddressGermanDetector;
        // No street-type word at all — pure prose.
        $hits = $detector->detect('Lorem ipsum dolor sit amet.');

        $this->assertSame([], $hits);
    }

    public function test_returns_detection_objects_with_correct_detector_name(): void
    {
        $detector = new AddressGermanDetector;
        $hits = $detector->detect('Hauptstraße 5.');

        $this->assertCount(1, $hits);
        $this->assertInstanceOf(Detection::class, $hits[0]);
        $this->assertSame('address_de', $hits[0]->detector);
    }

    public function test_returns_empty_array_on_empty_string(): void
    {
        $detector = new AddressGermanDetector;
        $this->assertSame([], $detector->detect(''));
    }

    public function test_detects_multiple_addresses_in_one_string(): void
    {
        $detector = new AddressGermanDetector;
        $text = 'Hauptsitz Hauptstr. 5. Filiale Goetheweg 7.';
        $hits = $detector->detect($text);

        $this->assertGreaterThanOrEqual(2, count($hits));
        $this->assertSame('Hauptstr. 5', $hits[0]->value);
        $this->assertSame('Goetheweg 7', $hits[1]->value);
    }
}
