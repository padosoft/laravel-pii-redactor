<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Detectors;

/**
 * Detects German Steuerliche Identifikationsnummer (Steuer-ID).
 *
 * Spec: §139b Abgabenordnung (AO) — Bundeszentralamt für Steuern.
 * Format: 11 digits with mod-11 ISO 7064 (Pure) checksum.
 * Plus structural rule: in the first 10 digits, exactly one digit must
 * appear two or three times AND at least one digit must NOT appear at
 * all (no digit may appear more than three times). This is what catches
 * obviously-invalid sequential strings like `12345678901` even when the
 * checksum byte happens to align.
 *
 * Algorithm (ISO 7064, MOD 11,10 / "Pure"):
 *
 *   product = 10
 *   for each digit d (left-to-right, i = 0..9):
 *       sum = (d + product) mod 10
 *       if sum == 0: sum = 10
 *       product = (sum * 2) mod 11
 *   check = (11 - product) mod 10
 *   the 11th digit must equal `check`
 *
 * Word boundaries on either side prevent matches inside longer numeric
 * strings (IBANs, credit-card numbers, fiscal-PIN style identifiers).
 *
 * @see https://www.bzst.de/DE/Privatpersonen/SteuerlicheIdentifikationsnummer/steuerlicheidentifikationsnummer_node.html
 * @see https://en.wikipedia.org/wiki/ISO/IEC_7064
 */
final class SteuerIdDetector implements Detector
{
    private const PATTERN = '/\b\d{11}\b/';

    public function name(): string
    {
        return 'steuer_id';
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
            if (! $this->isStructurallyValid($value)) {
                continue;
            }
            if (! $this->isChecksumValid($value)) {
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
     * §139b AO Abs. 4 structural rule on the first 10 digits:
     *
     *   - No digit may appear more than three times.
     *   - Exactly one digit appears two or three times.
     *   - At least one digit (of 0..9) does not appear at all
     *     (i.e. the 10-digit body uses at most 9 distinct digits).
     *
     * Sequential strings like 1234567890 fail because every digit is
     * unique (no digit appears twice). The all-zeroes / repdigit
     * sentinels fail because a single digit appears more than three
     * times.
     */
    private function isStructurallyValid(string $value): bool
    {
        if (strlen($value) !== 11 || ! ctype_digit($value)) {
            return false;
        }

        $body = substr($value, 0, 10);
        $counts = array_count_values(str_split($body));

        $twoOrThree = 0;
        foreach ($counts as $count) {
            if ($count > 3) {
                return false;
            }
            if ($count === 2 || $count === 3) {
                $twoOrThree++;
            }
        }

        if ($twoOrThree !== 1) {
            return false;
        }

        return count($counts) <= 9;
    }

    private function isChecksumValid(string $value): bool
    {
        $product = 10;
        for ($i = 0; $i < 10; $i++) {
            $digit = (int) $value[$i];
            $sum = ($digit + $product) % 10;
            if ($sum === 0) {
                $sum = 10;
            }
            $product = ($sum * 2) % 11;
        }

        $check = (11 - $product) % 10;

        return $check === (int) $value[10];
    }
}
