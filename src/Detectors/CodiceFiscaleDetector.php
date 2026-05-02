<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Detectors;

/**
 * Detects Italian codice fiscale (16-character alphanumeric tax code).
 *
 * Canonical format: 6 alpha (surname/name) + 2 digit (year) + 1 alpha
 * (month) + 2 digit (day+gender) + 1 alpha (city) + 3 digit (city) +
 * 1 alpha (CIN).
 *
 * Omocodia: when two natural persons would otherwise share the same
 * canonical CF, the Agenzia delle Entrate substitutes one or more of
 * the 7 digit positions (1-based 7, 8, 10, 11, 13, 14, 15) with the
 * matching omocodia letter using the official table
 * 0→L 1→M 2→N 3→P 4→Q 5→R 6→S 7→T 8→U 9→V. The pre-filter accepts
 * either a digit or one of those letters at each omocodic position so
 * substituted codes survive into the checksum step (the checksum
 * itself, against the 1976 odd/even table, validates both shapes
 * uniformly).
 *
 * Validation: structural regex + checksum on the 16th character (CIN)
 * using the official odd/even table from Decreto Ministeriale 23
 * dicembre 1976.
 *
 * Provisional 11-digit fiscal codes (assigned to companies sharing the
 * P.IVA space) are intentionally NOT matched by this detector — the
 * PartitaIvaDetector covers that range.
 */
final class CodiceFiscaleDetector implements Detector
{
    /**
     * Word-boundary anchored. Pre-filters before checksum.
     *
     * Each omocodic position uses the character class `[\dLMNPQRSTUV]`
     * (the 10 valid digits plus the 10 omocodia substitution letters).
     */
    private const PATTERN = '/\b[A-Z]{6}[\dLMNPQRSTUV]{2}[A-EHLMPRST][\dLMNPQRSTUV]{2}[A-Z][\dLMNPQRSTUV]{3}[A-Z]\b/i';

    /**
     * Odd-position character weights for CIN computation.
     */
    private const ODD_TABLE = [
        '0' => 1, '1' => 0, '2' => 5, '3' => 7, '4' => 9, '5' => 13, '6' => 15,
        '7' => 17, '8' => 19, '9' => 21,
        'A' => 1, 'B' => 0, 'C' => 5, 'D' => 7, 'E' => 9, 'F' => 13, 'G' => 15,
        'H' => 17, 'I' => 19, 'J' => 21, 'K' => 2, 'L' => 4, 'M' => 18, 'N' => 20,
        'O' => 11, 'P' => 3, 'Q' => 6, 'R' => 8, 'S' => 12, 'T' => 14, 'U' => 16,
        'V' => 10, 'W' => 22, 'X' => 25, 'Y' => 24, 'Z' => 23,
    ];

    /**
     * 0-based PHP indices that may carry a digit OR an omocodia
     * substitution letter (L M N P Q R S T U V). Used to normalise the
     * code before checksum: substituted letters are converted back to
     * their corresponding digit so the standard 1976 odd/even table is
     * applied uniformly.
     */
    private const OMOCODIA_POSITIONS = [6, 7, 9, 10, 12, 13, 14];

    /**
     * Omocodia letter -> equivalent digit.
     */
    private const OMOCODIA_TO_DIGIT = [
        'L' => '0', 'M' => '1', 'N' => '2', 'P' => '3', 'Q' => '4',
        'R' => '5', 'S' => '6', 'T' => '7', 'U' => '8', 'V' => '9',
    ];

    public function name(): string
    {
        return 'codice_fiscale';
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
            $value = strtoupper((string) $match[0]);
            if (! $this->isChecksumValid($value)) {
                continue;
            }
            $detections[] = new Detection(
                detector: $this->name(),
                value: (string) $match[0],
                offset: (int) $match[1],
                length: strlen((string) $match[0]),
            );
        }

        return $detections;
    }

    private function isChecksumValid(string $cf): bool
    {
        if (strlen($cf) !== 16) {
            return false;
        }

        $normalised = $this->normaliseOmocodia($cf);

        $sum = 0;
        for ($i = 0; $i < 15; $i++) {
            $char = $normalised[$i];
            // Position 1 in spec = index 0 in PHP. Odd positions in spec = even
            // PHP indices.
            if ($i % 2 === 0) {
                if (! isset(self::ODD_TABLE[$char])) {
                    return false;
                }
                $sum += self::ODD_TABLE[$char];

                continue;
            }
            // Even positions: digits use their numeric value, letters use
            // their A=0..Z=25 offset.
            $sum += $this->evenValue($char);
        }

        $expectedCin = chr(ord('A') + ($sum % 26));

        return $normalised[15] === $expectedCin;
    }

    /**
     * Replace omocodia substitution letters at the 7 omocodic positions
     * with their corresponding digit so the 1976 checksum table can be
     * applied uniformly. Non-omocodic positions are left untouched.
     */
    private function normaliseOmocodia(string $cf): string
    {
        $out = $cf;
        foreach (self::OMOCODIA_POSITIONS as $pos) {
            $ch = $out[$pos];
            if (isset(self::OMOCODIA_TO_DIGIT[$ch])) {
                $out[$pos] = self::OMOCODIA_TO_DIGIT[$ch];
            }
        }

        return $out;
    }

    private function evenValue(string $char): int
    {
        if (ctype_digit($char)) {
            return (int) $char;
        }
        if ($char >= 'A' && $char <= 'Z') {
            return ord($char) - ord('A');
        }

        return -100; // poison value; will fail checksum.
    }
}
