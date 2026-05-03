<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Packs;

use Padosoft\PiiRedactor\Detectors\Detector;

/**
 * Country / region pack contract.
 *
 * A pack aggregates a set of related detectors that target a single
 * jurisdiction (Italy, Germany, Spain, France, etc.) — typically national
 * identifiers with structural validation (CIN / Luhn / mod-97 checksum
 * algorithms specific to that country) plus the country's phone-number
 * patterns and address heuristics.
 *
 * Multi-country detectors that operate by international spec (Email,
 * IBAN ISO 13616, CreditCard Luhn) STAY in the top-level
 * `Padosoft\PiiRedactor\Detectors\` namespace — they are not country-
 * specific.
 *
 * To enable a pack, list its FQCN in `config('pii-redactor.packs')`. The
 * `DetectorPackRegistry` (added in v1.0) walks the list at SP boot,
 * instantiates each pack, calls `detectors()`, and registers each
 * returned Detector with the engine. Hosts disable a pack by removing
 * it from the config list.
 *
 * Community-contributed packs:
 *
 * - `Padosoft\PiiRedactor\Packs\Germany\GermanyPack`   (v1.1+)
 * - `Padosoft\PiiRedactor\Packs\Spain\SpainPack`       (v1.1+)
 * - `Padosoft\PiiRedactor\Packs\France\FrancePack`     (v1.2+ candidate)
 * - `Padosoft\PiiRedactor\Packs\Netherlands\NetherlandsPack` (v1.2+ candidate)
 *
 * See `CONTRIBUTING-PACKS.md` for the contribution workflow + checksum
 * provenance requirements + test fixture standards.
 */
interface PackContract
{
    /**
     * Stable machine identifier for the pack. Lowercase, no whitespace.
     * Used in logs, audit trails, and config error messages.
     *
     * Example: `'italy'`, `'germany'`, `'spain'`.
     */
    public function name(): string;

    /**
     * ISO 3166-1 alpha-2 country code (uppercase) when the pack covers a
     * single country. Region packs (e.g. a future Nordic pack covering
     * Denmark + Sweden + Norway + Finland) MAY return the empty string;
     * such packs MUST document the convention in their docblock.
     *
     * Example: `'IT'`, `'DE'`, `'ES'`, `'FR'`, `'NL'`.
     */
    public function countryCode(): string;

    /**
     * Human-readable description shown in admin UIs / debug listings.
     * Should mention the identifiers covered + any unique calling cards
     * (e.g. checksum algorithm names) so operators can quickly grok what
     * the pack covers without opening the source.
     */
    public function description(): string;

    /**
     * The detectors this pack contributes to the engine. Order is
     * preserved — the engine's overlap resolver still applies left-most
     * + longer-on-tie rules, but pack ordering can affect tie-break
     * determinism when two detectors emit identical spans.
     *
     * Implementations MUST return a fresh list on every call (no
     * mutation across calls). Detector instances themselves can be
     * memoized internally if construction is expensive.
     *
     * @return list<Detector>
     */
    public function detectors(): array;
}
