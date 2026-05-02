<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Detectors;

/**
 * Detects Italian phone numbers — landline + mobile + emergency.
 *
 * Accepted forms (with optional country prefix and grouping characters):
 *  - `+39 333 1234567` / `0039 333 1234567` / `333 1234567`
 *  - `+39 02 12345678` (Milan landline)
 *  - `06-1234567` (Rome landline with hyphen separator)
 *
 * The pattern intentionally requires the leading prefix or a national
 * mobile/landline trunk so that bare 10-digit strings (P.IVA territory,
 * fiscal identifiers) do not accidentally match.
 */
final class PhoneItalianDetector implements Detector
{
    private const PATTERN =
        '/(?:(?:\+|00)39[\s.\-]?)?'.   // optional country prefix.
        '(?:'.
        '3\d{2}[\s.\-]?\d{6,7}'.   // mobile: 3xx xxxxxxx (3 + 6/7 digits).
        '|'.
        '0\d{1,3}[\s.\-]?\d{5,8}'. // landline: 0[area] [number].
        ')/';

    public function name(): string
    {
        return 'phone_it';
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
            // Reject if fewer than 9 digits remain after stripping separators
            // (avoids matching a bare "06" in an unrelated context).
            $digits = preg_replace('/\D/', '', $value) ?? '';
            if (strlen($digits) < 9) {
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
}
