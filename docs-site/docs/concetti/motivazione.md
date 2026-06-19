---
title: Motivazione
description: Why laravel-pii-redactor exists.
---

# Motivazione

PII redaction often fails because teams treat European identifiers as plain strings. A regex can find an Italian-looking codice fiscale, but checksum validation decides whether the match deserves to be redacted.

`laravel-pii-redactor` exists to make the deterministic layer boring and testable:

- run locally by default;
- validate checksums where the identifier has one;
- keep national rules in opt-in packs;
- expose audit counts without leaking raw PII;
- leave NER as an optional second layer.

::: callout info "Design center" icon:target
The package is built for Laravel applications that need predictable redaction before logs, tickets, prompts, exports, or support tooling leave a trusted boundary.
:::
