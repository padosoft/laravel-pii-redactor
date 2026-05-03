<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Packs\Germany;

use Padosoft\PiiRedactor\Detectors\AddressGermanDetector;
use Padosoft\PiiRedactor\Detectors\PhoneGermanDetector;
use Padosoft\PiiRedactor\Detectors\SteuerIdDetector;
use Padosoft\PiiRedactor\Detectors\UStIdNrDetector;
use Padosoft\PiiRedactor\Packs\PackContract;

/**
 * Germany pack — covers German PII identifiers.
 *
 * Detectors:
 * - `steuer_id`  — 11-digit Steuerliche Identifikationsnummer with
 *   mod-11 ISO 7064 checksum (§139b AO).
 * - `ust_idnr`   — `DE` + 9-digit Umsatzsteuer-Identifikationsnummer
 *   with BMF Method 30 mod-11 checksum (§27a UStG).
 * - `phone_de`   — German mobile + landline phone numbers.
 * - `address_de` — heuristic German street addresses (Straße / Str. /
 *   Allee / Platz / Weg / Gasse / Ring / Damm / Ufer / Brücke / Hof
 *   plus prefix forms `Am`, `An der`, `Auf der`, `Im`, `In der`, `Zur`).
 *
 * Multi-country detectors (Email, IBAN, CreditCard) are NOT included —
 * they live in the top-level `Padosoft\PiiRedactor\Detectors\` namespace.
 *
 * Enable: add `\Padosoft\PiiRedactor\Packs\Germany\GermanyPack::class`
 * to `config('pii-redactor.packs')`. Disable: remove the entry.
 */
final class GermanyPack implements PackContract
{
    public function name(): string
    {
        return 'germany';
    }

    public function countryCode(): string
    {
        return 'DE';
    }

    public function description(): string
    {
        return 'German PII pack — Steuer-ID (mod-11 ISO 7064), USt-IdNr '
            .'(BMF Method 30 mod-11), German phone numbers, German street '
            .'addresses. Multi-country detectors (Email, IBAN, CreditCard) '
            .'are registered separately by the framework.';
    }

    public function detectors(): array
    {
        return [
            new SteuerIdDetector,
            new UStIdNrDetector,
            new PhoneGermanDetector,
            new AddressGermanDetector,
        ];
    }
}
