# Changelog

All notable changes to `padosoft/laravel-pii-redactor` are documented here. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Core `RedactorEngine` orchestrating detector list + replacement strategy
  with deterministic left-to-right overlap resolution.
- `Detector` interface + 6 first-party detectors:
  - `CodiceFiscaleDetector` — 16-char Italian fiscal code with full CIN
    checksum (Decreto Ministeriale 23/12/1976 odd/even table).
  - `PartitaIvaDetector` — 11-digit Italian VAT with Luhn-style P.IVA
    checksum and zero-payload sentinel rejection.
  - `IbanDetector` — ISO 13616 IBAN for ~75 registered countries with
    mod-97 verification (chunked over 9-digit windows for PHP_INT_MAX
    safety).
  - `EmailDetector` — pragmatic RFC-5321-shaped match.
  - `PhoneItalianDetector` — Italian mobile + landline with optional
    `+39` / `0039` prefix.
  - `CreditCardDetector` — 13–19 digit PAN with Luhn validation.
- `RedactionStrategy` interface + 4 first-party strategies:
  - `MaskStrategy` — fixed mask token (default `[REDACTED]`).
  - `HashStrategy` — deterministic salted SHA-256 hash, namespaced per
    detector to prevent cross-type joins.
  - `TokeniseStrategy` — reversible pseudonymisation with `detokenise()`,
    `detokeniseString()`, `dumpMap()`, and `loadMap()` for cross-process
    recovery.
  - `DropStrategy` — emits empty replacement.
- `DetectionReport` with `total()`, `countsByDetector()`,
  `samplesByDetector()`, `toArray()`, `isEmpty()`.
- `Pii` facade with `redact()`, `scan()`, `extend()`, `register()`,
  `withStrategy()`.
- `pii:scan` Artisan command that emits a JSON detection report from a
  file path or stdin.
- `PiiRedactorServiceProvider` — config publish + DI bindings + command
  registration.
- Architecture test (`tests/Architecture/StandaloneAgnosticTest.php`)
  enforcing the standalone-agnostic invariant — `src/` MUST contain no
  reference to AskMyDocs / sister-package symbols.
- CI matrix PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13 with Pint + PHPStan
  level 6 + PHPUnit Unit + Architecture suites.
- Padosoft AI vibe-coding pack (`.claude/`) — skills (R36 review loop,
  R10–R37 rules), agents (`pre-push-self-review`,
  `playwright-enterprise-tester`), rules (Laravel + Playwright +
  generic), commands (`/create-job`, `/domain-scaffold`,
  `/domain-service`).

### Notes

- v0.1 is regex + checksum only. The v0.1 default never makes a network
  call. NER + LLM-based detectors land behind opt-in flags in v0.2.
- `padosoft/laravel-pii-redactor` is **standalone-agnostic** — it does
  NOT require any sister Padosoft package and does NOT reference
  AskMyDocs symbols. Enforced on every CI run.

[Unreleased]: https://github.com/padosoft/laravel-pii-redactor/compare/HEAD...main
