---
title: Country Packs
description: Jurisdiction-specific detector bundles.
---

# Country Packs

Country packs isolate national identifiers from international detectors. Email, IBAN, and credit card detection always run from the top-level detector list. Fiscal codes, VAT IDs, national phones, and address heuristics belong in packs.

::: grids
  ::: grid
    ::: card "ItalyPack" icon:flag
    Default pack with codice fiscale, partita IVA, Italian phone, and Italian address detectors.
    :::
  :::
  ::: grid
    ::: card "GermanyPack" icon:flag
    Opt-in pack for Steuer-ID, USt-IdNr, German phone, and German address detection.
    :::
  :::
  ::: grid
    ::: card "SpainPack" icon:flag
    Opt-in pack for DNI, NIE, CIF, Spanish phone, and Spanish address detection.
    :::
  :::
:::

```php
'packs' => [
    \Padosoft\PiiRedactor\Packs\Italy\ItalyPack::class,
    \Padosoft\PiiRedactor\Packs\Germany\GermanyPack::class,
    \Padosoft\PiiRedactor\Packs\Spain\SpainPack::class,
],
```

::: callout tip "Pack authoring rule" icon:package-plus
Implement `PackContract`, return detector instances from `detectors()`, and include valid, invalid, and wrong-format fixtures for every checksum detector.
:::
