<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Detectors;

/**
 * Detects German Umsatzsteuer-Identifikationsnummer (USt-IdNr / German VAT).
 *
 * Spec: §27a UStG — Umsatzsteuergesetz. Format: country prefix `DE`
 * followed by 9 digits. Checksum: BMF "Method 30" mod-11 (the same
 * ISO 7064 mod-11 family used by the Steuer-ID, applied over 8 input
 * digits with the 9th digit as the check).
 *
 * Algorithm (Bundesministerium der Finanzen, Method 30):
 *
 *   product = 10
 *   for each of digits 1..8 (i = 0..7):
 *       sum = (d + product) mod 10
 *       if sum == 0: sum = 10
 *       product = (sum * 2) mod 11
 *   check = (11 - product) mod 10
 *   the 9th digit must equal `check`
 *
 * The `DE` prefix is matched case-insensitively because real-world
 * documents emit both `DE123456788` and `de123456788`. Word boundaries
 * on either side prevent matches inside longer alphanumeric tokens.
 *
 * @see https://www.bzst.de/DE/Unternehmen/Identifikationsnummern/Umsatzsteuer-Identifikationsnummer/umsatzsteuer-identifikationsnummer_node.html
 */
final class UStIdNrDetector implements Detector
{
    private const PATTERN = '/\bDE\d{9}\b/i';

    public function name(): string
    {
        return 'ust_idnr';
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
            $digits = substr($value, 2);
            if (! $this->isChecksumValid($digits)) {
                continue;
            }
            $detections[] = new Detection(
                detector: $this->name(),
                value: $value,
                offset: (int) $match[1],
                length: strlen($value),
            );
        }

        return $detections;
    }

    /**
     * Validate the 9-digit body (8 payload digits + 1 check digit).
     */
    private function isChecksumValid(string $digits): bool
    {
        if (strlen($digits) !== 9 || ! ctype_digit($digits)) {
            return false;
        }

        $product = 10;
        for ($i = 0; $i < 8; $i++) {
            $digit = (int) $digits[$i];
            $sum = ($digit + $product) % 10;
            if ($sum === 0) {
                $sum = 10;
            }
            $product = ($sum * 2) % 11;
        }

        $check = (11 - $product) % 10;

        return $check === (int) $digits[8];
    }
}
