<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Detectors;

/**
 * Detects Spanish CIF (Código de Identificación Fiscal).
 *
 * Source: Orden EHA/451/2008 of the Agencia Tributaria, which formalised
 * the structure inherited from RD 2402/1980. CIF identifies juridical
 * persons (companies, foundations, public bodies). It was officially
 * superseded by NIF for new corporate entities from 2008 onwards but
 * remains in active circulation for entities registered under the prior
 * regime.
 *
 * Format: 1 leading letter + 7 digits + 1 control character. The leading
 * letter encodes the entity class (A = Sociedad anónima, B = Sociedad
 * limitada, ..., S = Órgano de la Administración, ...); valid leading
 * letters per AEAT spec are A B C D E F G H J K L M N P Q R S U V W.
 * Letters I, Ñ, O, T, X, Y, Z are not used as CIF prefixes.
 *
 * Control-character algorithm:
 *
 *  1. Sum the 3 digits in EVEN positions (1-indexed positions 2, 4, 6
 *     = 0-indexed 1, 3, 5).
 *  2. For each digit in ODD positions (1-indexed 1, 3, 5, 7 = 0-indexed
 *     0, 2, 4, 6): double it; if the result is greater than 9, subtract
 *     9 (equivalent to summing its digits). Sum all four results.
 *  3. Total = even_sum + odd_sum. Take its last digit D.
 *  4. Control digit C = (10 − D) mod 10.
 *  5. If the leading letter is in {K, P, Q, S, N, W} the control MUST
 *     be the letter at index C in 'JABCDEFGHI' (J = 0, A = 1, ..., I = 9).
 *  6. Otherwise the control MUST be the digit C itself.
 *
 * Leading letters K, P, Q, S, N, W use a letter control because the
 * underlying entity types (associations, public-law bodies, foreign
 * agencies) historically required a letter to disambiguate from
 * personal NIFs.
 */
final class CifDetector implements Detector
{
    private const PATTERN = '/\b[A-HJ-NP-SUVWa-hj-np-suvw]\d{7}[0-9A-Za-z]\b/';

    /**
     * Letters whose control character MUST be a letter from JABCDEFGHI.
     */
    private const LETTER_CONTROL_LEADERS = ['K', 'P', 'Q', 'S', 'N', 'W'];

    private const LETTER_CONTROL_TABLE = 'JABCDEFGHI';

    public function name(): string
    {
        return 'cif';
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

    private function isChecksumValid(string $cif): bool
    {
        if (strlen($cif) !== 9) {
            return false;
        }

        $upper = strtoupper($cif);
        $leading = $upper[0];
        $sevenDigits = substr($upper, 1, 7);
        $control = $upper[8];

        if (! ctype_digit($sevenDigits)) {
            return false;
        }

        $evenSum = (int) $sevenDigits[1] + (int) $sevenDigits[3] + (int) $sevenDigits[5];

        $oddSum = 0;
        foreach ([0, 2, 4, 6] as $i) {
            $doubled = ((int) $sevenDigits[$i]) * 2;
            $oddSum += ($doubled > 9) ? ($doubled - 9) : $doubled;
        }

        $total = $evenSum + $oddSum;
        $lastDigit = $total % 10;
        $expectedC = (10 - $lastDigit) % 10;

        if (in_array($leading, self::LETTER_CONTROL_LEADERS, true)) {
            $expectedControl = self::LETTER_CONTROL_TABLE[$expectedC];

            return $control === $expectedControl;
        }

        // Digit-control group: the control must be the digit itself.
        if (! ctype_digit($control)) {
            return false;
        }

        return ((int) $control) === $expectedC;
    }
}
