<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Detectors;

/**
 * Detects Italian Partita IVA (11-digit VAT number).
 *
 * Validation: 11 digits + Luhn-style checksum on the 11th digit using the
 * standard P.IVA algorithm (sum of odd-position digits + sum of doubled
 * even-position digits with 9-subtraction; result mod 10).
 *
 * The detector deliberately does NOT match across word boundaries, which
 * keeps it from biting into longer numeric strings (phone numbers, IBANs).
 */
final class PartitaIvaDetector implements Detector
{
    private const PATTERN = '/\b\d{11}\b/';

    public function name(): string
    {
        return 'p_iva';
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

    private function isChecksumValid(string $piva): bool
    {
        if (strlen($piva) !== 11 || ! ctype_digit($piva)) {
            return false;
        }

        // 00000000000 is an obvious zero-checksum sentinel — never a real VAT.
        if ($piva === '00000000000') {
            return false;
        }

        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $digit = (int) $piva[$i];
            if ($i % 2 === 0) {
                $sum += $digit;

                continue;
            }
            $doubled = $digit * 2;
            $sum += ($doubled > 9) ? ($doubled - 9) : $doubled;
        }

        $check = (10 - ($sum % 10)) % 10;

        return $check === (int) $piva[10];
    }
}
