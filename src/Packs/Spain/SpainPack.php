<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Packs\Spain;

use Padosoft\PiiRedactor\Detectors\AddressSpanishDetector;
use Padosoft\PiiRedactor\Detectors\CifDetector;
use Padosoft\PiiRedactor\Detectors\DniDetector;
use Padosoft\PiiRedactor\Detectors\NieDetector;
use Padosoft\PiiRedactor\Detectors\PhoneSpanishDetector;
use Padosoft\PiiRedactor\Packs\PackContract;

/**
 * Spanish PII pack — community-style country pack shipped in v1.1.
 *
 * Aggregates the five jurisdiction-specific detectors that target
 * Spanish identifiers and contact details:
 *
 *  - `DniDetector` (`dni`) — Documento Nacional de Identidad,
 *    8 digits + 1 control letter, validated against the
 *    23-letter table from Real Decreto 1553/2005.
 *  - `NieDetector` (`nie`) — Número de Identidad de Extranjero,
 *    leading X/Y/Z + 7 digits + 1 control letter; checksum reuses
 *    the DNI table after the leading letter is mapped to a digit
 *    (X→0, Y→1, Z→2).
 *  - `CifDetector` (`cif`) — Código de Identificación Fiscal,
 *    1 leading letter + 7 digits + 1 control character (digit OR
 *    letter, depending on the leading letter), validated per the
 *    Agencia Tributaria spec (Orden EHA/451/2008).
 *  - `PhoneSpanishDetector` (`phone_es`) — Spanish mobile (6/7) +
 *    landline (8/9) numbers with optional `+34` / `0034` prefix.
 *  - `AddressSpanishDetector` (`address_es`) — heuristic Spanish
 *    street-address recognition (Calle / Avenida / Plaza / Paseo /
 *    Carrer / Travesía / Glorieta / Ronda + optional CP + city).
 *
 * Multi-country detectors (`EmailDetector`, `IbanDetector`,
 * `CreditCardDetector`) are NOT part of this pack — they live in the
 * top-level `Padosoft\PiiRedactor\Detectors\` namespace and are
 * registered by the framework regardless of which country packs are
 * enabled.
 *
 * The pack is enabled by listing its FQCN in
 * `config('pii-redactor.packs')`. The `DetectorPackRegistry` walks
 * that list at SP boot, instantiates each pack, calls `detectors()`,
 * and registers the resulting Detector instances with the engine.
 */
final class SpainPack implements PackContract
{
    public function name(): string
    {
        return 'spain';
    }

    public function countryCode(): string
    {
        return 'ES';
    }

    public function description(): string
    {
        return 'Spanish PII pack — DNI (23-letter checksum table), NIE '
            .'(prefix-substituted DNI algorithm), CIF (corporate ID with '
            .'mixed digit/letter control), Spanish phone numbers, Spanish '
            .'street addresses. Multi-country detectors (Email, IBAN, '
            .'CreditCard) are registered separately by the framework.';
    }

    public function detectors(): array
    {
        return [
            new DniDetector,
            new NieDetector,
            new CifDetector,
            new PhoneSpanishDetector,
            new AddressSpanishDetector,
        ];
    }
}
