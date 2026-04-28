# laravel-pii-redactor

**Italian-first PII redaction pipeline** for Laravel. GDPR + AI Act ready.

## Features

- **3-layer pipeline**:
  - Layer A — Regex deterministic (codice fiscale, IBAN IT/EU, partita IVA, telefono +39, email, carta credito, ZIP/CAP, JWT, OAuth tokens, AWS keys)
  - Layer B — NER ML via spaCy bridge (PERSON, ORG, LOC, DATE)
  - Layer C — LLM-based contextual (opt-in, async)
- Italian PII dictionary 50k+ names
- Custom rules YAML per tenant
- De-redaction reversible (encrypted)
- Performance: 1000+ char/ms

## Installation

```bash
composer require padosoft/laravel-pii-redactor
```

## Quick start

```php
use Padosoft\PiiRedactor\Facades\Pii;

$redacted = Pii::redact('Mio CF: RSSMRA80A01H501Z, email: mario@example.com');
// "Mio CF: <CF_001>, email: <EMAIL_001>"
```

## Documentation

See [docs/](./docs/) for full API reference.

## License

Apache-2.0 — see [LICENSE](./LICENSE).

## Status

🚧 Pre-release. v0.1.0 expected July 2026.
