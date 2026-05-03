<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Detectors;

/**
 * Detects Spanish phone numbers — mobile + landline.
 *
 * Source: Plan Nacional de Numeración Telefónica (Comisión Nacional de
 * los Mercados y la Competencia). Spanish national subscriber numbers
 * are 9 digits long and are partitioned by leading digit:
 *
 *  - 6xx — mobile (originally Movistar / pre-portability)
 *  - 7xx — mobile (post-2008 expansion + portability)
 *  - 8xx — landline (newer geographic blocks; 800 = freephone)
 *  - 9xx — landline (legacy geographic + 900 freephone, 901/902 paid info)
 *
 * Accepted forms (with optional ITU country code +34 / 0034 and grouping
 * separators):
 *
 *  - `+34 612 345 678`           (mobile, with country prefix + spaces)
 *  - `+34 91 123 4567`           (Madrid landline 91-block)
 *  - `+34 612345678`             (no internal grouping)
 *  - `+34612345678`              (no separators at all)
 *  - `91 123 4567`               (no country prefix)
 *  - `912 345 678`               (alternative landline grouping)
 *  - `0034 612 345 678`          (international dial-string prefix)
 *
 * The pattern is bounded by a non-digit / non-plus lookbehind and a
 * non-digit lookahead so it cannot start in the middle of a longer
 * numeric string (matching the same `[0-9+]`-aware boundary discipline
 * used by `PhoneItalianDetector`).
 */
final class PhoneSpanishDetector implements Detector
{
    /**
     * Body alternation covers the three real-world groupings:
     *
     *  - 3-3-3: `612 345 678` (mobile + most landline blocks)
     *  - 2-3-4: `91 123 4567` (Madrid / Barcelona historic 2-digit
     *    area-code grouping)
     *  - contiguous: `612345678` (no internal separators)
     *
     * In every variant the 9 body digits start with 6 / 7 (mobile) or
     * 8 / 9 (landline). The body is then post-validated to have either
     * 9 digits (no country prefix) or 11 digits (with +34 / 0034).
     */
    private const PATTERN =
        '/(?<![0-9+])'.                                              // non-digit / non-plus lookbehind
        '(?:(?:\+|00)34[\s.\-]?)?'.                                  // optional country prefix
        '(?:'.
        '[6789]\d{2}[\s.\-]?\d{3}[\s.\-]?\d{3}'.                     // 3-3-3 grouping (also covers contiguous 9 digits)
        '|'.
        '[89]\d[\s.\-]\d{3}[\s.\-]\d{4}'.                            // 2-3-4 grouping (e.g. `91 123 4567`)
        ')'.
        '(?!\d)/';                                                   // non-digit lookahead

    public function name(): string
    {
        return 'phone_es';
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
            // After stripping separators we must have one of:
            //  - 9 digits  (no country prefix)
            //  - 11 digits (with `+34` — the `+` is not a digit)
            //  - 13 digits (with `0034` — the leading `00` adds 2)
            // Anything else means we're biting into a longer numeric
            // run or a sub-fragment of one.
            $digits = preg_replace('/\D/', '', $value) ?? '';
            $len = strlen($digits);
            if ($len !== 9 && $len !== 11 && $len !== 13) {
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
