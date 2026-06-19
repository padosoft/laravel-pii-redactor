---
title: Quickstart
description: Install and run the first redaction.
---

# Quickstart

::: steps
1. **Install the package**
   ```bash
   composer require padosoft/laravel-pii-redactor
   ```
2. **Publish configuration when you need to change defaults**
   ```bash
   php artisan vendor:publish --tag=pii-redactor-config
   ```
3. **Redact text through the facade**
   ```php
   use Padosoft\PiiRedactor\Facades\Pii;

   $text = 'IBAN IT60X0542811101000000123456, email mario@example.com.';
   $redacted = Pii::redact($text);
   ```
4. **Inspect detections before replacing content**
   ```php
   $report = Pii::scan('Telefono +39 333 1234567 e P.IVA 12345678903.');
   $counts = $report->countsByDetector();
   ```
:::

::: callout warning "Default scope" icon:triangle-alert
The default configuration loads the international detectors and `ItalyPack`. Add Germany or Spain explicitly when the host application processes those jurisdictions.
:::

## First useful configuration

```env
PII_REDACTOR_ENABLED=true
PII_REDACTOR_STRATEGY=mask
PII_REDACTOR_MASK_TOKEN=[REDACTED]
PII_REDACTOR_AUDIT_TRAIL=false
```

For reversible pseudonymisation, switch to `tokenise`, set `PII_REDACTOR_SALT`, choose a persistent token store, and run the migration if the database driver is selected.
