<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Detectors;

/**
 * Detects credit-card-like PANs (13 to 19 digits, optionally separated by
 * spaces or hyphens) and validates them with the Luhn checksum.
 *
 * The detector deliberately keeps the issuer-detection logic out of scope —
 * any Luhn-valid string in the right length range is treated as PII. False
 * positives at this layer are preferable to leaking a real PAN.
 */
final class CreditCardDetector implements Detector
{
    private const PATTERN = '/\b(?:\d[ \-]?){12,18}\d\b/';

    public function name(): string
    {
        return 'credit_card';
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
            $compact = preg_replace('/[\s\-]/', '', $value) ?? '';
            $len = strlen($compact);
            if ($len < 13 || $len > 19) {
                continue;
            }
            if (! $this->luhn($compact)) {
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

    private function luhn(string $digits): bool
    {
        if (! ctype_digit($digits)) {
            return false;
        }

        $sum = 0;
        $alt = false;
        for ($i = strlen($digits) - 1; $i >= 0; $i--) {
            $n = (int) $digits[$i];
            if ($alt) {
                $n *= 2;
                if ($n > 9) {
                    $n -= 9;
                }
            }
            $sum += $n;
            $alt = ! $alt;
        }

        return $sum > 0 && $sum % 10 === 0;
    }
}
