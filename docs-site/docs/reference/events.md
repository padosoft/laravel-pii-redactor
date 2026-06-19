---
title: Events
description: Audit event reference.
---

# Events

`PiiRedactionPerformed` is dispatched after `redact()` produces at least one detection and audit trail is enabled.

| Field | Meaning |
| --- | --- |
| `countsByDetector` | Map of detector id to count |
| `total` | Total detection count |
| `strategy` | Strategy name used for replacement |

::: callout success "Privacy property" icon:shield
The event contains counts only. It does not carry raw PII, samples, or redacted output.
:::
