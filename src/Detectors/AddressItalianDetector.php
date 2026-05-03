<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Detectors;

/**
 * Detects Italian street addresses via a deterministic regex heuristic.
 *
 * Recognised forms:
 *  - `Via Roma 12`
 *  - `Via Roma, 12` / `Via Roma 12/A` / `Via Roma 12bis` / `Via Roma 12 ter`
 *  - `Via dei Mille 5` / `Via della Repubblica 22` / `Via d'Annunzio 1`
 *  - `Piazza Cavalieri di Vittorio Veneto 1`
 *  - `Via Roma 12 - 50100 Firenze` (CAP + city, only when a civic number
 *    has already been consumed)
 *  - Bare `Via Roma` (no civic number) is still detected.
 *
 * Supported street-type prefixes (case-insensitive at the prefix only):
 *  Via, Viale, V.le, Piazza, P.zza, Piazzetta, Corso, C.so, Largo, L.go,
 *  Strada, Vicolo, Vico, Calle, Salita, Lungomare, Località, Loc.
 *
 * The name token immediately following the prefix MUST start with an
 * uppercase letter (proper-noun anchor) — so `via roma` does NOT match.
 * The prefix is anchored on a `\b` word boundary so embedded substrings
 * like `lavia roma 12` do NOT match.
 *
 * This is a heuristic — there is no postal-code checksum and no street
 * gazetteer lookup. False positives are possible on prose like
 * `Via Crucis Sabato` (where the second token is also capitalised). The
 * cost-benefit tilts toward over-redaction, which is the safe default
 * for a PII layer.
 */
final class AddressItalianDetector implements Detector
{
    /**
     * Compound prefixes ("Via dei", "Via della", ...) are matched as part
     * of the prefix step so the CAPITALIZED street-name anchor that
     * follows can still hold against `dei` / `della` / `del` / `di` /
     * `d'` / `degli` / `delle`, which start with lowercase.
     */
    private const PATTERN =
        '/\b'.
        // 1) Street-type prefix (case-insensitive via inline modifier
        //    around the alternation only — the proper-noun anchor that
        //    follows is intentionally case-sensitive).
        '(?i:'.
        'V\.le|Viale|Via|'.
        'P\.zza|Piazzetta|Piazza|'.
        'C\.so|Corso|'.
        'L\.go|Largo|'.
        'Strada|Vicolo|Vico|Calle|Salita|Lungomare|'.
        'Località|Loc\.'.
        ')'.
        // 2) Optional connective particles (lowercase) — `dei`, `della`,
        //    `del`, `di`, `d'`, `degli`, `delle`. Multiple allowed.
        '(?:\s+(?:dei|della|delle|degli|del|di|d\'))*'.
        // 3) Mandatory separator + capitalized street-name word. The
        //    separator is whitespace, OR the lookbehind `(?<=\')` so the
        //    `d'Annunzio` form (apostrophe directly glued to the name)
        //    matches without an intervening space.
        '(?:\s+|(?<=\'))[A-ZÀ-Ý][A-Za-zÀ-ÿ\']*'.
        // 4) Optional additional name tokens (extra capitalized words or
        //    lowercase connectives). We deliberately allow whitespace +
        //    (capitalized | connective) repeated.
        '(?:\s+(?:[A-ZÀ-Ý][A-Za-zÀ-ÿ\']*|dei|della|delle|degli|del|di|d\'))*'.
        // 5) Optional civic-number block. Comma optional; `n.` optional;
        //    civic suffix `/A`, `bis`, `ter` optional.
        '(?:'.
        '\s*,?\s*(?:n\.?\s*)?\d+(?:\s*\/\s*[A-Z]|\s*bis|\s*ter)?'.
        // 6) Optional CAP + city — only when a civic number has been
        //    consumed (the alternative branch below has no civic and no
        //    CAP, so this restriction holds structurally).
        '(?:\s*-?\s*\d{5}\s+[A-ZÀ-Ý][A-Za-zÀ-ÿ\']+(?:\s+[A-ZÀ-Ý][A-Za-zÀ-ÿ\']+)*)?'.
        ')?'.
        '/u';

    public function name(): string
    {
        return 'address_it';
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
            $offset = (int) $match[1];

            $detections[] = new Detection(
                detector: $this->name(),
                value: $value,
                offset: $offset,
                length: strlen($value),
            );
        }

        return $detections;
    }
}
