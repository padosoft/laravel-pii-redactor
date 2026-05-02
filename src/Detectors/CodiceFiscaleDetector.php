<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Detectors;

/**
 * Detects Italian codice fiscale (16-character alphanumeric tax code).
 *
 * Format: 6 alpha (surname/name) + 2 digit (year) + 1 alpha (month) +
 *         2 alphanumeric (day+gender) + 4 alphanumeric (city) + 1 alpha (CIN).
 *
 * Validation: structural regex + checksum on the 16th character (CIN) using
 * the official odd/even table from Decreto Ministeriale 23 dicembre 1976.
 *
 * Provisional 11-digit fiscal codes (assigned to companies sharing the
 * P.IVA space) are intentionally NOT matched by this detector — the
 * PartitaIvaDetector covers that range.
 */
final class CodiceFiscaleDetector implements Detector
{
    /**
     * Word-boundary anchored. Pre-filters before checksum.
     */
    private const PATTERN = '/\b[A-Z]{6}\d{2}[A-EHLMPRST]\d{2}[A-Z]\d{3}[A-Z]\b/i';

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

        $sum = 0;
        for ($i = 0; $i < 15; $i++) {
            $char = $cf[$i];
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

        return $cf[15] === $expectedCin;
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
