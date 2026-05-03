<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Detectors;

/**
 * Detects German street addresses via a deterministic regex heuristic.
 *
 * Recognised forms:
 *  - `Berliner Straße 12` / `Hauptstraße 5` (street-name + Straße suffix)
 *  - `Hauptstr. 5` / `Friedrich-Ebert-Str. 12` (abbreviated `Str.`)
 *  - `Friedrich-Ebert-Allee 32` (compound proper-noun + Allee)
 *  - `Marktplatz 1` (single compound noun ending in `platz`)
 *  - `Goetheweg 7` (suffix `weg`, glued to name)
 *  - `Am Ring 7` / `An der Alster 12` / `Auf der Höhe 5` (prefix forms —
 *    German has streets where the type word LEADS the name)
 *  - `Unter den Linden 5` (specific named-prefix form, manual entry)
 *
 * Optional civic-number and PLZ + city blocks are appended after the
 * core street segment, mirroring the pattern shape used by the
 * Italian detector.
 *
 * Supported street-type SUFFIXES (case-insensitive at the suffix only):
 *   Straße / straße / strasse / Str. / str.
 *   Allee / allee
 *   Platz / platz
 *   Weg / weg
 *   Gasse / gasse
 *   Ring / ring
 *   Damm / damm
 *   Ufer / ufer
 *   Brücke / brücke
 *   Hof / hof
 *
 * Supported street-type PREFIXES:
 *   Am / An der / An den / Auf der / Auf dem / Im / In der / In den /
 *   Zur / Zum / Unter den / Vor dem / Hinter der
 *
 * The detector is heuristic — there is no postal-code checksum and no
 * gazetteer lookup. False positives are possible on prose where two
 * capitalised words happen to coincide with a Straße/Allee suffix; the
 * cost-benefit of a PII layer tilts toward over-redaction, which is
 * the safe default.
 */
final class AddressGermanDetector implements Detector
{
    /**
     * Three alternatives, anchored on a `\b` word boundary:
     *
     *  Form A (compound, glued): a single capitalised word that
     *  ends with one of the suffix tokens — `Hauptstraße`,
     *  `Marktplatz`, `Goetheweg`, `Sandgasse`, `Stadtring`,
     *  `Kurfürstendamm`, `Mainufer`, `Lindenhof`. The suffix is
     *  glued to the proper noun with no separator. This is the
     *  most common German shape.
     *
     *  Form B (compound, hyphenated): two or more capitalised
     *  segments joined by hyphens, the last of which ends with a
     *  suffix or IS a standalone suffix word — `Friedrich-Ebert-Allee`,
     *  `Friedrich-Ebert-Str.`, `Hans-Sachs-Platz`.
     *
     *  Form C (separated): `<Adjective-form> <Suffix-word>` where
     *  the second token is a standalone street-type word — for
     *  example `Berliner Straße`, `Hauptstr. 5`. Only the two-token
     *  shape matches; longer prose runs are deliberately not
     *  greedy here.
     *
     *  Form D (prefix-particle): a fixed leading particle (`Am`,
     *  `An der`, `Auf dem`, etc.) followed by a single capitalised
     *  proper noun — `Am Ring`, `An der Alster`, `Unter den Linden`.
     *
     * All four forms admit an optional civic-number block (`12`,
     * `12a`, `12-14`) and an optional PLZ + city tail
     * (`12345 Berlin`).
     */
    private const PATTERN =
        '/(?:'.
        // ----- Form B: hyphenated compound ending in suffix-word. The
        //              standalone suffix-words include the abbreviated
        //              `Str.` form. Most distinctive — comes FIRST so the
        //              regex engine prefers the long compound match.
        '\b[A-ZÄÖÜ][a-zäöüß]+(?:\-[A-ZÄÖÜ][a-zäöüß]+)*'.
        '\-(?i:Straße|strasse|Str\.|Allee|Platz|Weg|Gasse|Ring|Damm|Ufer|Brücke|Hof)'.
        // Optional civic-number block.
        '(?:[\s,]+\d+[a-zA-Z]?(?:\s*-\s*\d+[a-zA-Z]?)?)?'.
        // Optional PLZ + city tail.
        '(?:[\s,]+\d{5}\s+[A-ZÄÖÜ][a-zäöüß]+(?:[\-\s][A-ZÄÖÜ][a-zäöüß]+)*)?'.
        '|'.
        // ----- Form C: separated `<Adjective> <SuffixWord>` (exactly two
        //              tokens). The standalone suffix-word includes the
        //              abbreviated form `Str.`.
        '\b[A-ZÄÖÜ][a-zäöüß]+\s+(?i:Straße|strasse|Str\.|Allee|Platz)'.
        // Optional civic-number block.
        '(?:[\s,]+\d+[a-zA-Z]?(?:\s*-\s*\d+[a-zA-Z]?)?)?'.
        // Optional PLZ + city tail.
        '(?:[\s,]+\d{5}\s+[A-ZÄÖÜ][a-zäöüß]+(?:[\-\s][A-ZÄÖÜ][a-zäöüß]+)*)?'.
        '|'.
        // ----- Form A: single capitalised compound word ending in suffix
        //              (Hauptstraße, Hauptstr., Marktplatz, Goetheweg,
        //              Sandgasse, Stadtring, Kurfürstendamm, Mainufer,
        //              Lindenhof). Civic number is REQUIRED here — without
        //              it the detector would over-match common nouns like
        //              `Bauplatz` or `Lebensweg`. The abbreviated `str\.`
        //              form is included so `Hauptstr. 5` matches as a
        //              single token.
        '\b[A-ZÄÖÜ][a-zäöüß]+(?i:straße|strasse|str\.|allee|platz|weg|gasse|ring|damm|ufer|brücke|hof)'.
        // Mandatory civic-number block.
        '[\s,]+\d+[a-zA-Z]?(?:\s*-\s*\d+[a-zA-Z]?)?'.
        // Optional PLZ + city tail.
        '(?:[\s,]+\d{5}\s+[A-ZÄÖÜ][a-zäöüß]+(?:[\-\s][A-ZÄÖÜ][a-zäöüß]+)*)?'.
        '|'.
        // ----- Form D: prefix-particle + single capitalised proper noun.
        //              Civic number is optional here because the
        //              prefix particle itself is the address signal.
        '\b(?:Am|An\s+der|An\s+den|Auf\s+der|Auf\s+dem|Im|In\s+der|In\s+den|Zur|Zum|Unter\s+den|Vor\s+dem|Hinter\s+der)'.
        '\s+[A-ZÄÖÜ][a-zäöüß]+'.
        // Optional civic-number block.
        '(?:[\s,]+\d+[a-zA-Z]?(?:\s*-\s*\d+[a-zA-Z]?)?)?'.
        // Optional PLZ + city tail.
        '(?:[\s,]+\d{5}\s+[A-ZÄÖÜ][a-zäöüß]+(?:[\-\s][A-ZÄÖÜ][a-zäöüß]+)*)?'.
        ')/u';

    public function name(): string
    {
        return 'address_de';
    }

    public function detect(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $matches = [];
        if (preg_match_all(self::PATTERN, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $detections = [];
        foreach ($matches[0] as $match) {
            $value = (string) $match[0];
            $offset = (int) $match[1];

            $detections[] = new Detection(
                detector: $this->name(),
                value: $value,
                offset: $offset,
                length: strlen($value),
            );
        }

        return $detections;
    }
}
