# Changelog

All notable changes to `padosoft/laravel-pii-redactor` are documented here. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [0.3.0] - 2026-05-03

### Added

- **`HuggingFaceNerDriver`** — production NER driver against the
  HuggingFace Inference API via `Illuminate\Support\Facades\Http`.
  Default model `Davlan/bert-base-multilingual-cased-ner-hrl` covers
  Italian + EU. Aggregated entity-group response shape; entity-label
  → detector-name mapping (`PER` → `person`, `ORG` → `organisation`,
  `LOC` → `location`, `MISC` → `misc`). Fails open on every error
  path (HTTP non-OK, malformed body, missing fields, unmapped labels,
  zero-length spans) so a NER outage never blocks deterministic
  redaction. Configured via `PII_REDACTOR_HUGGINGFACE_API_KEY`,
  `PII_REDACTOR_HUGGINGFACE_MODEL`, `PII_REDACTOR_HUGGINGFACE_BASE_URL`,
  `PII_REDACTOR_HUGGINGFACE_TIMEOUT`. 15 unit tests via `Http::fake()`.
- **`SpaCyNerDriver`** — production NER driver against any spaCy server
  returning `spacy.tokens.Doc.to_json()` shape (`{entities: [{label,
  start_char, end_char, text}]}`). Configurable label-to-detector
  mapping (`PERSON` / `PER` → `person`, `ORG` → `organisation`,
  `GPE` / `LOC` → `location`, `NORP` → `group`, `FAC` → `facility`).
  Optional bearer auth via `PII_REDACTOR_SPACY_API_KEY`. Same fail-open
  contract as the HuggingFace driver. Configured via
  `PII_REDACTOR_SPACY_SERVER_URL` + `PII_REDACTOR_SPACY_TIMEOUT`. 16
  unit tests via `Http::fake()`.
- **`CacheTokenStore`** — third TokenStore driver alongside
  `InMemoryTokenStore` and `DatabaseTokenStore`. Backed by
  `Illuminate\Contracts\Cache\Repository` so deployments swap between
  Redis / Memcached / DynamoDB / array (test) without touching package
  code. Maintains an explicit `__index` cache key tracking every
  written token so `dump()` and `clear()` work without scanning the
  backend keyspace. Optional TTL via constructor. SHA-256 prefixed
  keys to keep cache key length bounded. Configured via
  `PII_REDACTOR_TOKEN_STORE=cache`,
  `PII_REDACTOR_TOKEN_STORE_CACHE_STORE` (cache store name; default
  `cache.default`), `PII_REDACTOR_TOKEN_STORE_CACHE_PREFIX`,
  `PII_REDACTOR_TOKEN_STORE_CACHE_TTL` (seconds; `0` = forever).
  12 unit tests including TTL expiry + cross-instance visibility.
- **Italian custom-rule YAML packs** — five new classes under
  `src/CustomRules/`:
  - `CustomRule` — immutable `(name, pattern, flags)` value object;
    `compiledPattern()` validates PCRE on first use.
  - `CustomRuleSet` — typed `list<CustomRule>` with `fromArray()`
    factory enforcing non-empty `name` + `pattern` per row.
  - `YamlCustomRuleLoader` — `Symfony\Component\Yaml\Yaml::parseFile`-
    backed loader. Surfaces missing-file / `ParseException` / non-list
    `rules` section as `CustomRuleException`. Empty YAML → empty set.
  - `CustomRuleDetector` — implements
    `Padosoft\PiiRedactor\Detectors\Detector`; wraps a `CustomRuleSet`
    and emits one `Detection` per pattern match. Pack name becomes the
    detector name (`Pii::extend('custom_it_albo', $detector)`).
  - `CustomRuleException` — extends `PiiRedactorException`.
  Sample fixture `tests/fixtures/custom-rules/it-albo.yaml` ships
  Italian iscrizione-albo pattern + tessera-ordine pattern. 18 unit
  tests across loader + detector + facade integration.
- **`config('pii-redactor.custom_rules')`** — `auto_register` switch
  + `packs` list shape so v1.0 can ship the auto-registration loop.
  v0.3 keeps registration manual via `Pii::extend()` (host-controlled).
- **Live test harness** — `tests/Live/` directory with
  `HuggingFaceNerDriverLiveTest` + `SpaCyNerDriverLiveTest`. Both
  guard on `PII_REDACTOR_LIVE=1` AND their respective credential env
  vars. `tests/Live/README.md` documents the opt-in convention,
  per-driver env-var matrix, CI exclusion policy, and the cost-
  discipline rules for adding new Live tests.
- **`composer.json`** — `symfony/yaml: ^7.0|^8.0` added to `require`
  (was previously absent; needed by `YamlCustomRuleLoader`).

### Changed

- **`PiiRedactorServiceProvider::buildTokenStore()`** — added the
  `'cache' => new CacheTokenStore(...)` arm and updated the error
  message to list `cache` alongside `memory` / `database` as a valid
  driver. Two new private helpers (`resolveCacheRepository`,
  `intOrNull`) wire the configured cache store + TTL.
- **README** — feature count bumped from "audit-trail event + persistent
  TokenStore + NER scaffold" to include real NER drivers + custom-rule
  YAML packs + cache TokenStore. Architecture diagram extended with
  the new `src/Ner/`, `src/CustomRules/`, `src/TokenStore/CacheTokenStore.php`,
  `src/Exceptions/CustomRuleException.php`, and `tests/Live/` layout.
  Roadmap entry for v0.3 marked shipped; v1.0 entry retained.

### Backward compatibility

v0.3 is a **drop-in upgrade** from v0.2 — no breaking changes. Existing
hosts:
- Continue using `InMemoryTokenStore` / `DatabaseTokenStore` without
  changes.
- Continue selecting NER drivers via the existing
  `pii-redactor.ner.drivers` map; `huggingface` and `spacy` are
  additional opt-in entries; `stub` remains the no-op default.
- Continue setting `PII_REDACTOR_TOKEN_STORE` to `memory` (default) or
  `database`; setting it to `cache` activates the new driver.
- Can register custom-rule packs at any time via
  `Pii::extend('custom_pack', new CustomRuleDetector(...))`.

### Test surface

158 → 219 PHPUnit tests (+61). 289 → 439 assertions (+150).
- 15 HuggingFace driver tests + 1 Live smoke test.
- 16 spaCy driver tests + 1 Live smoke test.
- 12 CacheTokenStore tests.
- 18 CustomRules tests (10 loader + 8 detector).
- All on PHP 8.3 / 8.4 / 8.5 × Laravel 12 / 13 matrix.

### Implementation notes

The v0.3 work was scaffolded by 4 parallel Claude sub-agents under
strict file allowlists (`HuggingFaceNerDriver` / `SpaCyNerDriver` +
Live harness / Custom-rule YAML loader / `CacheTokenStore` + SP
match-arm). Strictly partitioned scopes prevented merge conflicts on
the shared ServiceProvider + config. Pattern proven scalable across
v0.2 (3 agents) → v0.3 (4 agents). Same template will drive the v1.0
polish pass.

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

[Unreleased]: https://github.com/padosoft/laravel-pii-redactor/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/padosoft/laravel-pii-redactor/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/padosoft/laravel-pii-redactor/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/padosoft/laravel-pii-redactor/releases/tag/v0.1.0
