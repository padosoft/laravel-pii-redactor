<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Detectors;

/**
 * Detects German phone numbers — landline + mobile (D-mobile + E-mobile).
 *
 * Accepted forms (with optional country prefix and grouping characters):
 *  - `+49 30 12345678` / `+49 (0) 30 12345678` / `030 12345678` (Berlin landline)
 *  - `+49 89 1234567`  / `089 1234567` (Munich landline)
 *  - `+49 151 12345678` / `+4915112345678` / `0151 12345678` (D-mobile, T-Mobile)
 *  - `+49 160 1234567` / `0160 1234567` (D-mobile, original O2)
 *  - `+49 170 12345678` / `0170 12345678` (E-mobile, T-Mobile)
 *  - `0049 30 12345678` (international 00 prefix)
 *
 * The pattern is bounded by a non-digit lookbehind / lookahead so it
 * cannot start in the middle of a longer numeric string. After the
 * regex match a digit-count guard rejects sequences that fall under
 * the 7-digit minimum (after stripping `+`, spaces, dots, hyphens,
 * brackets) — this keeps short codes and 3-digit emergency dispatch
 * numbers (110, 112) out of the result set even when they appear next
 * to the country prefix.
 */
final class PhoneGermanDetector implements Detector
{
    /**
     * Pattern, in plain English:
     *
     *   - Optional country prefix: `+49` or `0049`, with optional
     *     separator.
     *   - Optional `(0)` carrier-trunk indicator (German formal
     *     notation).
     *   - Mobile: leading `1` + (5|6|7) + 1 digit area code + 6..9
     *     payload digits.
     *   - Landline: optional leading `0` + 2..5 digit area code +
     *     4..10 payload digits.
     *
     * The non-digit / non-plus boundaries (`(?<![0-9+])` /
     * `(?!\d)`) prevent matches inside longer numeric tokens.
     */
    private const PATTERN =
        '/(?<![0-9+])'.
        '(?:'.
        '(?:\+49|0049)[\s.\-]?(?:\(0\)[\s.\-]?)?'.  // country prefix + optional `(0)`.
        '(?:'.
        '1[5-7]\d[\s.\-]?\d{6,9}'.                  // mobile after intl prefix (no leading 0).
        '|'.
        '\d{2,5}[\s.\-]?\d{4,10}'.                  // landline after intl prefix (no leading 0).
        ')'.
        '|'.
        '0(?:'.
        '1[5-7]\d[\s.\-]?\d{6,9}'.                  // mobile, national format `0151 ...`.
        '|'.
        '\d{1,4}[\s.\-]?\d{4,10}'.                  // landline, national format `030 ...`.
        ')'.
        ')'.
        '(?!\d)/';

    public function name(): string
    {
        return 'phone_de';
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

            // Strip every non-digit (including `+`) and bound the
            // digit count: at least 7 (rejects short codes / 3-digit
            // emergency dispatch numbers) and at most 14 (rejects
            // identifier-style strings whose tail just happens to
            // satisfy the area-code + local-number alternation —
            // German phone numbers are at most 11 digits nationally
            // and 13 digits with the `+49` country prefix).
            $digits = preg_replace('/\D/', '', $value) ?? '';
            $digitCount = strlen($digits);
            if ($digitCount < 7 || $digitCount > 14) {
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
