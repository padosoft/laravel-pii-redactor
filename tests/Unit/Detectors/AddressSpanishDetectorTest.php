<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Detectors;

use Padosoft\PiiRedactor\Detectors\AddressSpanishDetector;
use Padosoft\PiiRedactor\Detectors\Detection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AddressSpanishDetectorTest extends TestCase
{
    public function test_name_is_stable(): void
    {
        $this->assertSame('address_es', (new AddressSpanishDetector)->name());
    }

    public function test_detects_a_basic_calle_with_civic_number(): void
    {
        $detector = new AddressSpanishDetector;
        $text = 'Vivo en Calle Mayor 12 desde 2010.';
        $hits = $detector->detect($text);

        $this->assertCount(1, $hits);
        $this->assertSame('Calle Mayor 12', $hits[0]->value);
        $this->assertSame(strpos($text, 'Calle Mayor 12'), $hits[0]->offset);
        $this->assertSame(strlen('Calle Mayor 12'), $hits[0]->length);
    }

    /**
     * Each supported street-type prefix recognised in isolation.
     *
     * @return list<array{0: string, 1: string}>
     */
    public static function streetTypePrefixes(): array
    {
        return [
            ['Calle', 'Calle Mayor 12'],
            ['C/', 'C/ Mayor 12'],
            ['Avenida', 'Avenida Diagonal 40'],
            ['Avd.', 'Avd. Diagonal 40'],
            ['Avda.', 'Avda. Diagonal 40'],
            ['Plaza', 'Plaza Mayor 1'],
            ['Pza.', 'Pza. Mayor 1'],
            ['Paseo', 'Paseo Marítimo 5'],
            ['P.º', 'P.º Marítimo 5'],
            ['Carrer', 'Carrer Pelai 12'],
            ['Travesía', 'Travesía Pozas 7'],
            ['Glorieta', 'Glorieta Bilbao 1'],
            ['Ronda', 'Ronda Atocha 35'],
        ];
    }

    #[DataProvider('streetTypePrefixes')]
    public function test_detects_each_supported_street_type_prefix(string $prefix, string $expected): void
    {
        $detector = new AddressSpanishDetector;
        $text = "La cita es en {$expected}.";
        $hits = $detector->detect($text);

        $this->assertCount(1, $hits, "Expected to detect prefix '{$prefix}'");
        $this->assertSame($expected, $hits[0]->value);
    }

    public function test_detects_compound_form_calle_de_la(): void
    {
        $detector = new AddressSpanishDetector;
        $hits = $detector->detect('Sede en Calle de la Princesa 8 oficina 3.');

        $this->assertCount(1, $hits);
        $this->assertSame('Calle de la Princesa 8', $hits[0]->value);
    }

    public function test_detects_compound_form_calle_de_los(): void
    {
        $detector = new AddressSpanishDetector;
        $hits = $detector->detect('Reunión en Calle de los Reyes 5.');

        $this->assertCount(1, $hits);
        $this->assertSame('Calle de los Reyes 5', $hits[0]->value);
    }

    public function test_detects_compound_form_avenida_del(): void
    {
        $detector = new AddressSpanishDetector;
        $hits = $detector->detect('Showroom en Avenida del Paralelo 100.');

        $this->assertCount(1, $hits);
        $this->assertSame('Avenida del Paralelo 100', $hits[0]->value);
    }

    public function test_detects_paseo_with_de_la(): void
    {
        $detector = new AddressSpanishDetector;
        $hits = $detector->detect('Cita en P.º de la Castellana 30 a las diez.');

        $this->assertCount(1, $hits);
        $this->assertSame('P.º de la Castellana 30', $hits[0]->value);
    }

    public function test_detects_civic_number_with_letter_suffix(): void
    {
        $detector = new AddressSpanishDetector;
        $hits = $detector->detect('Recogida en Calle Mayor 12A escalera B.');

        $this->assertCount(1, $hits);
        $this->assertSame('Calle Mayor 12A', $hits[0]->value);
    }

    public function test_detects_address_with_postal_code_and_city(): void
    {
        $detector = new AddressSpanishDetector;
        $hits = $detector->detect('Enviar a Calle Mayor 12, 28013 Madrid antes del lunes.');

        $this->assertCount(1, $hits);
        $this->assertSame('Calle Mayor 12, 28013 Madrid', $hits[0]->value);
    }

    public function test_detects_address_without_civic_number(): void
    {
        $detector = new AddressSpanishDetector;
        $hits = $detector->detect('Nos vemos en Calle Mayor a las diez.');

        $this->assertCount(1, $hits);
        $this->assertSame('Calle Mayor', $hits[0]->value);
    }

    public function test_detects_accented_proper_noun(): void
    {
        $detector = new AddressSpanishDetector;
        $hits = $detector->detect('Sede en Avda. de América 3 - planta 2.');

        $this->assertCount(1, $hits);
        $this->assertSame('Avda. de América 3', $hits[0]->value);
    }

    public function test_does_not_detect_lowercase_proper_noun(): void
    {
        $detector = new AddressSpanishDetector;
        // Proper-noun anchor: name must start with uppercase letter.
        $hits = $detector->detect('en calle mayor 12 hay tráfico.');

        $this->assertSame([], $hits);
    }

    public function test_does_not_detect_typo_with_wrong_letters(): void
    {
        $detector = new AddressSpanishDetector;
        // `Calles` is not in the prefix list; nor is `Vía`.
        $hits = $detector->detect('Vía Mayor 12 y luego Calles aledañas.');

        $this->assertSame([], $hits);
    }

    public function test_does_not_detect_embedded_substring(): void
    {
        $detector = new AddressSpanishDetector;
        // `incalle` contains `calle` but the word boundary anchor
        // prevents it from matching there.
        $hits = $detector->detect('incalle Mayor 12 something.');

        $this->assertSame([], $hits);
    }

    public function test_detects_multiple_addresses_in_one_string(): void
    {
        $detector = new AddressSpanishDetector;
        $text = 'Sede operativa: Calle Mayor 12. Sede legal: Avenida Diagonal 40.';
        $hits = $detector->detect($text);

        $this->assertCount(2, $hits);
        $this->assertSame('Calle Mayor 12', $hits[0]->value);
        $this->assertSame('Avenida Diagonal 40', $hits[1]->value);

        $this->assertSame(strpos($text, 'Calle Mayor 12'), $hits[0]->offset);
        $this->assertSame(strpos($text, 'Avenida Diagonal 40'), $hits[1]->offset);
    }

    public function test_returns_detection_objects_with_correct_detector_name(): void
    {
        $detector = new AddressSpanishDetector;
        $hits = $detector->detect('Calle Mayor 12.');

        $this->assertCount(1, $hits);
        $this->assertInstanceOf(Detection::class, $hits[0]);
        $this->assertSame('address_es', $hits[0]->detector);
    }

    public function test_returns_empty_array_on_empty_string(): void
    {
        $this->assertSame([], (new AddressSpanishDetector)->detect(''));
    }

    public function test_returns_empty_array_when_no_match(): void
    {
        $this->assertSame([], (new AddressSpanishDetector)->detect('Lorem ipsum dolor sit amet.'));
    }
}
