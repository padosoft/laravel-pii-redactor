---
title: Overview
description: Documentation home for laravel-pii-redactor.
---

# laravel-pii-redactor

`laravel-pii-redactor` is an Apache-2.0 Laravel package by Padosoft for EU-first PII redaction. It combines deterministic regex detectors, checksum validation, opt-in country packs, reversible tokenisation, and optional NER drivers without using external services in the default path.

::: callout tip "Start here" icon:rocket
Use the quickstart when you need a working redaction call in a Laravel app. Use the architecture pages when you are deciding how to extend detectors or operate token stores.
:::

::: grids
  ::: grid
    ::: card "Deterministic by default" icon:shield-check
    Email, IBAN, credit card, and country-pack detectors run locally with predictable results.
    :::
  :::
  ::: grid
    ::: card "EU country packs" icon:map
    Italy ships as the default pack. Germany and Spain are available as opt-in built-in packs.
    :::
  :::
  ::: grid
    ::: card "Strategy layer" icon:shuffle
    Replace detected values with masks, hashes, reversible tokens, or empty strings without changing detectors.
    :::
  :::
:::

```php
use Padosoft\PiiRedactor\Facades\Pii;

$clean = Pii::redact('Codice fiscale RSSMRA85T10A562S, email mario@example.com.');
// Codice fiscale [REDACTED], email [REDACTED].
```

## Documentation map

| Area | Use it for |
| --- | --- |
| Get Started | Install, publish config, run the first scan |
| Guides | Strategies, packs, YAML rules, token stores, NER |
| Concetti & Teoria | Why deterministic PII redaction works and where it does not |
| Architettura | Engine flow, contracts, ADRs, and diagrams |
| Best Practices | Privacy, detector authoring, and test expectations |
| Operations | Deployment, troubleshooting, and known limits |
| Reference | Facade API, CLI, config keys, and events |
