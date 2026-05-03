<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Detectors;

/**
 * Detects Spanish street addresses via a deterministic regex heuristic.
 *
 * Recognised forms:
 *
 *  - `Calle Mayor 12`
 *  - `Calle de la Princesa 8`
 *  - `Calle de los Reyes 5`
 *  - `Avenida del Paralelo 100`
 *  - `Avd. del Mar 10` / `Avda. de América 3`
 *  - `Plaza Mayor 1` / `Pza. del Sol 4`
 *  - `Paseo de Gracia 92` / `P.º de la Castellana 30`
 *  - `Carrer de Pelai 12` (Catalan)
 *  - `Travesía de Pozas 7`
 *  - `Glorieta de Bilbao 1`
 *  - `Ronda de Atocha 35`
 *  - `Calle Mayor 12, 28013 Madrid` (CP + city, only when a civic
 *    number has already been consumed)
 *  - `C/ Mayor 12` (slash-abbreviated `Calle`)
 *
 * Supported street-type prefixes (case-insensitive):
 *
 *  Calle, C/, Avenida, Avd., Avda., Plaza, Pza., Paseo, P.º,
 *  Carrer (Catalan), Travesía, Glorieta, Ronda
 *
 * The proper-noun anchor that follows the prefix MUST start with an
 * uppercase Latin letter (with optional Spanish accents Á É Í Ó Ú Ñ).
 * Connective particles `de`, `de la`, `de los`, `de las`, `del` are
 * accepted between the prefix and the proper noun.
 *
 * This is a heuristic — there is no postal-code checksum and no street
 * gazetteer lookup. The convention mirrors `AddressItalianDetector`:
 * over-redaction is the safe default for a PII layer.
 */
final class AddressSpanishDetector implements Detector
{
    private const PATTERN =
        '/\b'.
        // 1) Street-type prefix (case-insensitive). PCRE alternation
        //    matches the FIRST alternative that succeeds at a position
        //    (left-to-right preference, NOT longest-match), so longer /
        //    more-specific variants (`Avda.`, `Pza.`, `P.º`) MUST be
        //    listed BEFORE their shorter cousins (`Avenida`, `Plaza`,
        //    `Paseo`, `Calle`) — otherwise the engine would match the
        //    short prefix and leave the trailing literal as garbage,
        //    producing a partial detection. Order in this list IS
        //    semantic, not cosmetic.
        '(?i:'.
        'Avda\.|Avd\.|Avenida|'.
        'Pza\.|Plaza|'.
        'P\.º|Paseo|'.
        'Carrer|'.
        'Travesía|'.
        'Glorieta|'.
        'Ronda|'.
        'C\/|Calle'.
        ')'.
        // 2) Optional connective `de`, `de la`, `de los`, `de las`, `del`.
        '(?:\s+de(?:\s+(?:la|los|las))?|\s+del)?'.
        // 3) Mandatory whitespace + capitalized proper-noun word.
        '\s+[A-ZÁÉÍÓÚÑ][A-Za-záéíóúñ]+'.
        // 4) Optional additional name tokens (capitalized words, optional
        //    `de` / `del` connectives between them).
        '(?:[\s\-](?:[A-ZÁÉÍÓÚÑ][A-Za-záéíóúñ]+|de|la|los|las|del))*'.
        // 5) Optional civic-number block. Comma optional; optional letter
        //    suffix `12A` / `12B`.
        '(?:'.
        '\s*,?\s*\d+[A-Za-z]?'.
        // 6) Optional CP + city (5-digit Spanish postal code + city
        //    name). Only reachable after a civic number has been
        //    consumed.
        '(?:\s*,?\s*\d{5}\s+[A-ZÁÉÍÓÚÑ][A-Za-záéíóúñ]+(?:\s+[A-ZÁÉÍÓÚÑ][A-Za-záéíóúñ]+)*)?'.
        ')?'.
        '/u';

    public function name(): string
    {
        return 'address_es';
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
