<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Detectors;

/**
 * Detects Spanish NIE (Número de Identidad de Extranjero).
 *
 * Source: Real Decreto 1553/2005 (companion to DNI). The NIE is the
 * fiscal identifier assigned to non-Spanish residents and aliens. Its
 * format is one leading letter (X / Y / Z) + 7 digits + 1 control
 * letter. The control letter is computed via the same 23-character
 * table used by DNI, after the leading letter is mapped to a digit:
 *
 *   X → 0,  Y → 1,  Z → 2
 *
 * The mapped digit is prepended to the 7 numeric digits to form an
 * 8-digit number; that number modulo 23 indexes into the table:
 *
 *   T R W A G M Y F P D X B N J Z S Q V H L C K E
 *   0 1 2 3 4 5 6 7 8 9 ...
 *
 * X originally covered all NIEs; the Spanish administration ran out of
 * X-prefixed numbers in 2008 and started issuing Y, then Z. All three
 * remain in active use.
 *
 * The pattern is bounded by `\b` to avoid matching inside longer
 * alphanumeric strings. Lowercase input is accepted.
 */
final class NieDetector implements Detector
{
    private const PATTERN = '/\b[XYZxyz]\d{7}[A-Za-z]\b/';

    private const LETTER_TABLE = 'TRWAGMYFPDXBNJZSQVHLCKE';

    private const PREFIX_TO_DIGIT = [
        'X' => '0',
        'Y' => '1',
        'Z' => '2',
    ];

    public function name(): string
    {
        return 'nie';
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

    private function isChecksumValid(string $nie): bool
    {
        if (strlen($nie) !== 9) {
            return false;
        }

        $upper = strtoupper($nie);
        $prefix = $upper[0];
        if (! isset(self::PREFIX_TO_DIGIT[$prefix])) {
            return false;
        }

        $sevenDigits = substr($upper, 1, 7);
        if (! ctype_digit($sevenDigits)) {
            return false;
        }

        $eightDigits = self::PREFIX_TO_DIGIT[$prefix].$sevenDigits;
        $letter = $upper[8];
        $expected = self::LETTER_TABLE[((int) $eightDigits) % 23];

        return $letter === $expected;
    }
}
