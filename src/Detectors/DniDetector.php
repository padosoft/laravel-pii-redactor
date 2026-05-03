<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Detectors;

/**
 * Detects Spanish DNI (Documento Nacional de Identidad).
 *
 * Source: Real Decreto 1553/2005, de 23 de diciembre, by which the
 * Documento Nacional de Identidad and its electronic certificates are
 * regulated. The DNI consists of 8 digits + 1 control letter; the
 * control letter is computed by taking the 8-digit number modulo 23
 * and indexing into the official 23-character lookup table:
 *
 *   T R W A G M Y F P D X B N J Z S Q V H L C K E
 *   0 1 2 3 4 5 6 7 8 9 ...
 *
 * Letters I, Ñ, O, U are excluded from the table on purpose: they are
 * easily confused with 1 / N / 0 / V, respectively.
 *
 * The pattern is bounded by `\b` word boundaries to avoid matching
 * inside longer alphanumeric runs. Lowercase input is accepted; the
 * checksum compares against the uppercased form.
 */
final class DniDetector implements Detector
{
    private const PATTERN = '/\b\d{8}[A-Za-z]\b/';

    /**
     * Official 23-letter lookup table from RD 1553/2005.
     */
    private const LETTER_TABLE = 'TRWAGMYFPDXBNJZSQVHLCKE';

    public function name(): string
    {
        return 'dni';
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

    private function isChecksumValid(string $dni): bool
    {
        if (strlen($dni) !== 9) {
            return false;
        }

        $digits = substr($dni, 0, 8);
        if (! ctype_digit($digits)) {
            return false;
        }

        $letter = strtoupper(substr($dni, 8, 1));
        $expected = self::LETTER_TABLE[((int) $digits) % 23];

        return $letter === $expected;
    }
}
