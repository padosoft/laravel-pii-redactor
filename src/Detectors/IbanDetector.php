<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Detectors;

/**
 * Detects International Bank Account Numbers (ISO 13616).
 *
 * Validation:
 *  1. Length matches the country-specific IBAN length table.
 *  2. mod-97 checksum on the rearranged numeric form equals 1.
 *
 * Spaces inside the literal match are tolerated and stripped before the
 * checksum (real-world IBANs are routinely typed with grouping spaces).
 * The reported Detection.value preserves the original spacing so that
 * MaskStrategy / DropStrategy reproduce the source exactly.
 */
final class IbanDetector implements Detector
{
    /**
     * Two patterns avoid the catastrophic backtrack/over-match that a single
     * "optional space + alnum" repetition produces (it would happily munch
     * across the following word). Compact form is digit-tight; spaced form
     * uses 4-char groups separated by single spaces with an optional
     * 1..4-char tail group.
     *
     * The compact lower bound is `{11,30}` so total compact length lands
     * between 15 (NO) and 34 (LC/MT). The spaced form's first repetition
     * count is `{1,7}` (NOT `{2,7}`) so 15-char IBANs typed as `NO93 8601
     * 1117 947` (one mandatory 4-char block + an optional 1..4-char tail)
     * are still detected.
     */
    private const PATTERN_COMPACT = '/\b[A-Z]{2}\d{2}[A-Z0-9]{11,30}\b/i';

    private const PATTERN_SPACED = '/\b[A-Z]{2}\d{2} [A-Z0-9]{4}(?: [A-Z0-9]{4}){1,7}(?: [A-Z0-9]{1,4})?\b/i';

    /**
     * Country -> total IBAN length (including the 2-char country prefix +
     * 2-digit checksum). Reflects the SWIFT IBAN registry.
     *
     * @var array<string, int>
     */
    private const LENGTHS = [
        'AD' => 24, 'AE' => 23, 'AL' => 28, 'AT' => 20, 'AZ' => 28, 'BA' => 20,
        'BE' => 16, 'BG' => 22, 'BH' => 22, 'BR' => 29, 'BY' => 28, 'CH' => 21,
        'CR' => 22, 'CY' => 28, 'CZ' => 24, 'DE' => 22, 'DK' => 18, 'DO' => 28,
        'EE' => 20, 'EG' => 29, 'ES' => 24, 'FI' => 18, 'FO' => 18, 'FR' => 27,
        'GB' => 22, 'GE' => 22, 'GI' => 23, 'GL' => 18, 'GR' => 27, 'GT' => 28,
        'HR' => 21, 'HU' => 28, 'IE' => 22, 'IL' => 23, 'IQ' => 23, 'IS' => 26,
        'IT' => 27, 'JO' => 30, 'KW' => 30, 'KZ' => 20, 'LB' => 28, 'LC' => 32,
        'LI' => 21, 'LT' => 20, 'LU' => 20, 'LV' => 21, 'MC' => 27, 'MD' => 24,
        'ME' => 22, 'MK' => 19, 'MR' => 27, 'MT' => 31, 'MU' => 30, 'NL' => 18,
        'NO' => 15, 'PK' => 24, 'PL' => 28, 'PS' => 29, 'PT' => 25, 'QA' => 29,
        'RO' => 24, 'RS' => 22, 'SA' => 24, 'SC' => 31, 'SE' => 24, 'SI' => 19,
        'SK' => 24, 'SM' => 27, 'TL' => 23, 'TN' => 24, 'TR' => 26, 'UA' => 29,
        'VA' => 22, 'VG' => 24, 'XK' => 20,
    ];

    public function name(): string
    {
        return 'iban';
    }

    public function detect(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $detections = [];
        $seen = [];

        foreach ([self::PATTERN_COMPACT, self::PATTERN_SPACED] as $pattern) {
            $matches = [];
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }
            foreach ($matches[0] as $match) {
                $value = (string) $match[0];
                $offset = (int) $match[1];
                if (! $this->isValid($value)) {
                    continue;
                }
                $dedupeKey = $offset.':'.strlen($value);
                if (isset($seen[$dedupeKey])) {
                    continue;
                }
                $seen[$dedupeKey] = true;
                $detections[] = new Detection(
                    detector: $this->name(),
                    value: $value,
                    offset: $offset,
                    length: strlen($value),
                );
            }
        }

        // Restore offset-asc order — the engine's overlap resolver depends on it.
        usort($detections, static fn ($a, $b) => $a->offset <=> $b->offset);

        return $detections;
    }

    private function isValid(string $iban): bool
    {
        $compact = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
        if (strlen($compact) < 4) {
            return false;
        }

        $country = substr($compact, 0, 2);
        if (! isset(self::LENGTHS[$country])) {
            return false;
        }
        if (strlen($compact) !== self::LENGTHS[$country]) {
            return false;
        }
        if (! preg_match('/^[A-Z0-9]+$/', $compact)) {
            return false;
        }

        // Move the first 4 chars to the end then convert letters A=10..Z=35.
        $rearranged = substr($compact, 4).substr($compact, 0, 4);
        $numeric = '';
        for ($i = 0, $n = strlen($rearranged); $i < $n; $i++) {
            $ch = $rearranged[$i];
            $numeric .= ctype_digit($ch) ? $ch : (string) (ord($ch) - 55);
        }

        return $this->mod97($numeric) === 1;
    }

    /**
     * mod-97 over a numeric string of arbitrary length.
     *
     * The chunk size is 7 digits (NOT 9): with `$remainder` already
     * occupying up to 2 digits, the concatenated value reaches at most
     * 9 digits (max ~999_999_999), which fits comfortably under
     * PHP_INT_MAX on 32-bit PHP (PHP_INT_MAX ≈ 2.1e9). A 9-digit chunk
     * appended to a 2-digit remainder would yield up to 11 digits and
     * overflow on 32-bit builds, so the previous "9-digit chunks for
     * platform safety" comment was incorrect.
     */
    private function mod97(string $numeric): int
    {
        $remainder = 0;
        for ($i = 0, $n = strlen($numeric); $i < $n; $i += 7) {
            $chunk = (string) $remainder.substr($numeric, $i, 7);
            $remainder = ((int) $chunk) % 97;
        }

        return $remainder;
    }
}
