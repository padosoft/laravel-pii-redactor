---
title: Worked Example
description: End-to-end Laravel support-ticket redaction example.
---

# Worked Example

Scenario: a support ticket arrives with an email, an IBAN, and Italian tax data. The application must store a safe text copy and keep counts for audit.

```php
use Padosoft\PiiRedactor\Facades\Pii;

$ticket = 'Mario Rossi, RSSMRA85T10A562S, IBAN IT60X0542811101000000123456, mario@example.com';

$report = Pii::scan($ticket);
$safeBody = Pii::redact($ticket);

logger()->info('ticket.redacted', [
    'pii_total' => $report->total(),
    'pii_counts' => $report->countsByDetector(),
]);
```

::: collapsible "Expected detector counts"
The example should produce one `codice_fiscale`, one `iban`, and one `email` detection when the default Italy pack and international detectors are enabled.
:::

::: callout danger "Never log raw samples" icon:ban
`DetectionReport::samplesByDetector()` is useful in local debugging, but production audit logs should persist counts and detector names only.
:::
