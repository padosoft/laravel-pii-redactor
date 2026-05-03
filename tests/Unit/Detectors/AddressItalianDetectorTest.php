<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Detectors;

use Padosoft\PiiRedactor\Detectors\AddressItalianDetector;
use Padosoft\PiiRedactor\Detectors\Detection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AddressItalianDetectorTest extends TestCase
{
    public function test_name_is_stable(): void
    {
        $this->assertSame('address_it', (new AddressItalianDetector)->name());
    }

    public function test_detects_a_basic_via_with_civic_number(): void
    {
        $detector = new AddressItalianDetector;
        $text = 'Abito in Via Roma 12 da anni.';
        $hits = $detector->detect($text);

        $this->assertCount(1, $hits);
        $this->assertSame('Via Roma 12', $hits[0]->value);
        $this->assertSame(strpos($text, 'Via Roma 12'), $hits[0]->offset);
        $this->assertSame(strlen('Via Roma 12'), $hits[0]->length);
    }

    #[DataProvider('streetTypePrefixes')]
    public function test_detects_each_supported_street_type_prefix(string $prefix): void
    {
        $detector = new AddressItalianDetector;
        $text = "L'incontro è in {$prefix} Garibaldi 5.";
        $hits = $detector->detect($text);

        $this->assertCount(1, $hits, "Expected to detect prefix '{$prefix}'");
        $this->assertSame("{$prefix} Garibaldi 5", $hits[0]->value);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function streetTypePrefixes(): array
    {
        return [
            ['Via'],
            ['Viale'],
            ['Piazza'],
            ['Piazzetta'],
            ['Corso'],
            ['Largo'],
            ['Strada'],
            ['Vicolo'],
            ['Vico'],
            ['Calle'],
            ['Salita'],
            ['Lungomare'],
            ['Località'],
        ];
    }

    public function test_detects_compound_form_via_dei(): void
    {
        $detector = new AddressItalianDetector;
        $hits = $detector->detect('Sede legale: Via dei Mille 5, ufficio 3.');

        $this->assertCount(1, $hits);
        $this->assertSame('Via dei Mille 5', $hits[0]->value);
    }

    public function test_detects_compound_form_via_della(): void
    {
        $detector = new AddressItalianDetector;
        $hits = $detector->detect('Riunione in Via della Repubblica 22.');

        $this->assertCount(1, $hits);
        $this->assertSame('Via della Repubblica 22', $hits[0]->value);
    }

    public function test_detects_compound_form_via_apostrophe(): void
    {
        $detector = new AddressItalianDetector;
        $hits = $detector->detect("Studio in Via d'Annunzio 1.");

        $this->assertCount(1, $hits);
        $this->assertSame("Via d'Annunzio 1", $hits[0]->value);
    }

    public function test_detects_multi_word_proper_noun_with_connective(): void
    {
        $detector = new AddressItalianDetector;
        $hits = $detector->detect('Lezione in Piazza Cavalieri di Vittorio Veneto 1 stamattina.');

        $this->assertCount(1, $hits);
        $this->assertSame('Piazza Cavalieri di Vittorio Veneto 1', $hits[0]->value);
    }

    public function test_detects_civic_number_with_comma(): void
    {
        $detector = new AddressItalianDetector;
        $hits = $detector->detect('Indirizzo: Via Roma, 12.');

        $this->assertCount(1, $hits);
        $this->assertSame('Via Roma, 12', $hits[0]->value);
    }

    public function test_detects_civic_number_with_slash_letter(): void
    {
        $detector = new AddressItalianDetector;
        $hits = $detector->detect('Recapito Via Roma 12/A scala B.');

        $this->assertCount(1, $hits);
        $this->assertSame('Via Roma 12/A', $hits[0]->value);
    }

    public function test_detects_civic_number_with_bis_suffix(): void
    {
        $detector = new AddressItalianDetector;
        $hits = $detector->detect('Recapito Via Roma 12bis interno 4.');

        $this->assertCount(1, $hits);
        $this->assertSame('Via Roma 12bis', $hits[0]->value);
    }

    public function test_detects_cap_and_city_when_civic_present(): void
    {
        $detector = new AddressItalianDetector;
        $hits = $detector->detect('Spedire a Via Roma 12 - 50100 Firenze prima di lunedì.');

        $this->assertCount(1, $hits);
        $this->assertSame('Via Roma 12 - 50100 Firenze', $hits[0]->value);
    }

    public function test_detects_address_without_civic_number(): void
    {
        $detector = new AddressItalianDetector;
        $hits = $detector->detect('Ci vediamo in Via Roma alle dieci.');

        $this->assertCount(1, $hits);
        $this->assertSame('Via Roma', $hits[0]->value);
    }

    public function test_does_not_detect_lowercase_street_name(): void
    {
        $detector = new AddressItalianDetector;
        // Proper-noun anchor: name must start with uppercase letter.
        $hits = $detector->detect('In via roma c\'è traffico.');

        $this->assertSame([], $hits);
    }

    public function test_does_not_detect_typo_with_wrong_letters(): void
    {
        $detector = new AddressItalianDetector;
        // `Vai` != `Via` — no street-type prefix matches.
        $hits = $detector->detect('Vai Roma con calma.');

        $this->assertSame([], $hits);
    }

    public function test_does_not_detect_embedded_substring(): void
    {
        $detector = new AddressItalianDetector;
        // `lavia` contains `via` but not at a word boundary.
        $hits = $detector->detect('lavia roma 12 something.');

        $this->assertSame([], $hits);
    }

    public function test_detects_multiple_addresses_in_one_string(): void
    {
        $detector = new AddressItalianDetector;
        $text = 'Sede operativa: Via Roma 12. Sede legale: Corso Italia 5.';
        $hits = $detector->detect($text);

        $this->assertCount(2, $hits);
        $this->assertSame('Via Roma 12', $hits[0]->value);
        $this->assertSame('Corso Italia 5', $hits[1]->value);

        // Offsets must point to the correct substring positions.
        $this->assertSame(strpos($text, 'Via Roma 12'), $hits[0]->offset);
        $this->assertSame(strpos($text, 'Corso Italia 5'), $hits[1]->offset);
    }

    public function test_returns_detection_objects_with_correct_detector_name(): void
    {
        $detector = new AddressItalianDetector;
        $hits = $detector->detect('Via Roma 12.');

        $this->assertCount(1, $hits);
        $this->assertInstanceOf(Detection::class, $hits[0]);
        $this->assertSame('address_it', $hits[0]->detector);
    }

    public function test_returns_empty_array_on_empty_string(): void
    {
        $detector = new AddressItalianDetector;
        $this->assertSame([], $detector->detect(''));
    }

    public function test_returns_empty_array_when_no_match(): void
    {
        $detector = new AddressItalianDetector;
        $this->assertSame([], $detector->detect('Lorem ipsum dolor sit amet.'));
    }

    public function test_detects_abbreviated_prefixes(): void
    {
        $detector = new AddressItalianDetector;

        $hits = $detector->detect('Riunione in V.le Mazzini 10.');
        $this->assertCount(1, $hits);
        $this->assertSame('V.le Mazzini 10', $hits[0]->value);

        $hits = $detector->detect('Sede in P.zza Garibaldi 3.');
        $this->assertCount(1, $hits);
        $this->assertSame('P.zza Garibaldi 3', $hits[0]->value);

        $hits = $detector->detect('Negozio in C.so Italia 7.');
        $this->assertCount(1, $hits);
        $this->assertSame('C.so Italia 7', $hits[0]->value);

        $hits = $detector->detect('Punto in L.go Augusto 4.');
        $this->assertCount(1, $hits);
        $this->assertSame('L.go Augusto 4', $hits[0]->value);

        $hits = $detector->detect('Casa in Loc. Castelnuovo.');
        $this->assertCount(1, $hits);
        $this->assertSame('Loc. Castelnuovo', $hits[0]->value);
    }
}
