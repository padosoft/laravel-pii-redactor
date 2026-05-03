<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Packs\Italy;

use Padosoft\PiiRedactor\Detectors\AddressItalianDetector;
use Padosoft\PiiRedactor\Detectors\CodiceFiscaleDetector;
use Padosoft\PiiRedactor\Detectors\PartitaIvaDetector;
use Padosoft\PiiRedactor\Detectors\PhoneItalianDetector;
use Padosoft\PiiRedactor\Packs\PackContract;

/**
 * Italian PII pack — reference implementation of the v1.0 PackContract.
 *
 * Aggregates the four jurisdiction-specific detectors that ship with
 * the package since v0.1:
 *
 * - `CodiceFiscaleDetector` (`codice_fiscale`) — 16-char personal fiscal
 *   code with full CIN checksum (Decreto Ministeriale 23/12/1976).
 * - `PartitaIvaDetector` (`p_iva`) — 11-digit VAT number with Luhn-style
 *   checksum and zero-payload sentinel rejection.
 * - `PhoneItalianDetector` (`phone_it`) — Italian mobile + landline with
 *   optional `+39` / `0039` prefix.
 * - `AddressItalianDetector` (`address_it`) — heuristic Italian street
 *   address (Via / Viale / Piazza / Corso + compound forms).
 *
 * Multi-country detectors (`EmailDetector`, `IbanDetector`,
 * `CreditCardDetector`) are NOT included — they live in the top-level
 * `Padosoft\PiiRedactor\Detectors\` namespace and operate independently
 * of any pack.
 *
 * Backward compatibility: importing the four detector classes directly
 * (without going through the pack) continues to work. The pack is an
 * aggregator, not a relocation; the existing classes stay where they
 * are. The default `config('pii-redactor.packs')` value enables this
 * pack; existing v0.x deployments continue to see the same detectors
 * registered automatically.
 */
final class ItalyPack implements PackContract
{
    public function name(): string
    {
        return 'italy';
    }

    public function countryCode(): string
    {
        return 'IT';
    }

    public function description(): string
    {
        return 'Italian PII pack — codice fiscale (CIN checksum), '
            .'partita IVA (Luhn-IT), Italian phone numbers, Italian street '
            .'addresses. Multi-country detectors (Email, IBAN, CreditCard) '
            .'are registered separately by the framework.';
    }

    public function detectors(): array
    {
        return [
            new CodiceFiscaleDetector,
            new PartitaIvaDetector,
            new PhoneItalianDetector,
            new AddressItalianDetector,
        ];
    }
}
