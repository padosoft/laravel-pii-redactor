# Changelog

All notable changes to `padosoft/laravel-pii-redactor` are documented here. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.2.0] - 2026-05-03

### Added

- **`AddressItalianDetector` (`address_it`)** — heuristic Italian street-address
  detector covering street-type prefixes (Via, Viale, V.le, Piazza, P.zza,
  Piazzetta, Corso, C.so, Largo, L.go, Strada, Vicolo, Vico, Calle, Salita,
  Lungomare, Località, Loc.) plus compound forms (`Via dei`, `Via della`,
  `Via d'…`). Optionally consumes a trailing civic-number block
  (`12`, `, 12`, `12/A`, `12bis`) and a CAP+city tail (`50100 Firenze`).
  Pure regex — no checksum, no gazetteer, no network call. Registered in the
  default `detectors` config alongside the six v0.1 detectors.
- **`PiiRedactionPerformed` event** — Dispatchable Laravel event fired by
  `RedactorEngine::redact()` after every redaction when
  `pii-redactor.audit_trail.enabled` is true. Payload carries
  `countsByDetector` (detector → match count), `total` (int), and
  `strategyName` (string) — NEVER the raw PII or the redacted output. GDPR-
  friendly by construction. The flat v0.1 `audit_trail_enabled` key is
  preserved as a backward-compat fallback.
- **`TokenStore` interface + drivers** — pluggable persistence layer for
  `TokeniseStrategy`'s reverse map, replacing the v0.1 in-memory-only
  approach:
  - `InMemoryTokenStore` — array-backed, default; preserves v0.1 behavior
    when callers do not inject a store.
  - `DatabaseTokenStore` — Eloquent-backed, Memory-safe (`chunkById(500)`
    dumps, chunked `upsert()` loads per CLAUDE.md R3). Tokens persist
    across queue worker restarts and process boundaries.
- **`PiiTokenMap` Eloquent model** + **migration**
  `2026_05_03_000001_create_pii_token_maps_table` — schema:
  `id` / `token` (unique) / `original` (text) / `detector` (indexed) /
  `created_at` (default current timestamp). Publish via
  `php artisan vendor:publish --tag=pii-redactor-migrations`.
- **`NerDriver` interface + `StubNerDriver`** — pluggable named-entity
  recognition scaffold. v0.2 ships only the no-op stub so the surface stays
  stable; v0.3 plugs HuggingFace + spaCy drivers behind the same contract.
  Driver detections enter `RedactorEngine::collectDetections()` alongside
  first-party detector output and run through the same overlap-resolution
  pipeline.
- **`RedactorEngine::withAuditTrail(bool)` and `withNerDriver(NerDriver)`** —
  immutable setters mirroring the existing `withStrategy()` / `withEnabled()`
  pattern.
- **`TokeniseStrategy::store(): TokenStore`** getter for inspection / cross-
  process map sharing.
- **Config keys** — `audit_trail.enabled` (structured), `ner.enabled` /
  `ner.driver` / `ner.drivers`, `token_store.driver` /
  `token_store.database.connection` / `token_store.database.table`.
  Existing v0.1 keys preserved 1:1.
- **20 new PHPUnit tests** for the address detector + 17 TokenStore tests +
  9 RedactorEngine v0.2 surface tests + 3 each for `PiiRedactionPerformed`
  and `StubNerDriver` and 3 TokeniseStrategy tests for backward compat.
  Suite total: **154 tests, 280 assertions** — up from v0.1's 88 tests.

### Changed

- **`TokeniseStrategy` constructor** — now accepts a third optional
  parameter `?TokenStore $store = null`. Backward-compatible: the v0.1
  two-arg form (`new TokeniseStrategy($salt, $idHexLength)`) continues to
  work and defaults the store to `InMemoryTokenStore`. Internal
  `$tokenToOriginal` / `$reverseIndex` arrays delegated to the store.
- **`RedactorEngine` constructor** — gains `bool $auditTrailEnabled = false`
  (third parameter) and `?NerDriver $nerDriver = null` (fourth parameter).
  Both default to safe v0.1 behavior when omitted; the SP wires both from
  config when the engine is resolved from the container.
- **`PiiRedactorServiceProvider`** — registers a `singleton(NerDriver::class)`
  binding (validates FQCN implements the interface, falls back to
  `StubNerDriver` on misconfiguration), a `singleton(TokenStore::class)`
  binding (driver dispatch from config), `loadMigrationsFrom()` for the new
  migration directory, and `publishesMigrations()` for the
  `pii-redactor-migrations` tag.
- **README** — feature count bumped from 6 to 7 detectors; new sections for
  `TokenStore`, audit-trail event, NER scaffold; architecture diagram
  reflects the new directory layout; configuration reference covers all
  v0.2 keys; roadmap entry for v0.2 marked shipped.

### Backward compatibility

v0.2 is a **drop-in upgrade** from v0.1 — no breaking changes. Existing
hosts:
- continue using `TokeniseStrategy(salt, idHexLength)` without changes;
- continue setting `PII_REDACTOR_AUDIT_TRAIL=true` (flat key falls back
  to the structured `audit_trail.enabled`);
- gain the `address_it` detector automatically (added to the default
  `detectors` config);
- can opt in to `DatabaseTokenStore` by running the migration and setting
  `PII_REDACTOR_TOKEN_STORE=database`.

## [0.1.0] - 2026-04-30

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

[Unreleased]: https://github.com/padosoft/laravel-pii-redactor/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/padosoft/laravel-pii-redactor/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/padosoft/laravel-pii-redactor/releases/tag/v0.1.0
