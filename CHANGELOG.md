# Changelog

All notable changes to `padosoft/laravel-pii-redactor` are documented here. The
format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the
project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.2.0] - 2026-05-06

### Added — admin-ready headless API surface

- **`Padosoft\PiiRedactor\Admin\RedactorAdminInspector`** — secret-free runtime snapshot for admin panels. Reports enabled state, default strategy, audit setting, token-store driver/class, NER status, detector list, pack list, and custom-rule count without exposing salts, API keys, raw samples, token originals, or redacted output.
- **`Padosoft\PiiRedactor\Strategies\RedactionStrategyFactory`** — public factory for `mask`, `hash`, `tokenise`, and `drop` strategies. The service provider now uses this factory internally, so companion admin APIs can build preview strategies without copying private provider logic.
- **`Padosoft\PiiRedactor\Reports\DetectionReportFormatter`** — API-safe report formatter that masks samples by default as `[email]`, `[iban]`, etc., while preserving totals and per-detector counts.
- **`Padosoft\PiiRedactor\TokenStore\TokenResolutionService`** + **`DetokeniseResult`** — detokenise `[tok:<detector>:<hex>]` literals directly through the configured `TokenStore`, even when the current default strategy is not `tokenise`. The service fetches only tokens referenced in the input and never calls `TokenStore::dump()`.
- **`Padosoft\PiiRedactor\CustomRules\CustomRulePackInspector`** — validates configured YAML rule packs for UI diagnostics without mutating the engine or registering detectors.
- **`docs/admin-panel-architecture-plan.md`** — implementation contract for a separate Laravel 13.x package (`padosoft/laravel-pii-redactor-admin`) using Vite, React, TypeScript, and Tailwind CSS.

### Changed

- `README.md` documents the new admin-readiness surface, links the architecture plan, and extends the architecture tree with the new APIs.

### Backward compatibility

v1.2 is a **drop-in upgrade** from v1.1. The core package remains headless and does not register admin routes, controllers, React assets, migrations, or UI screens. Existing facade calls, detector packs, strategies, token stores, NER drivers, YAML rules, config keys, and migration behavior remain unchanged.

### Test surface

**631 PHPUnit tests** with focused gates for admin snapshots, strategy factory behavior, safe report formatting, token resolution, and custom-rule diagnostics. PHPStan and Pint are clean.

## [1.1.0] - 2026-05-03

### Added — first community-style country packs

- **`Padosoft\PiiRedactor\Packs\Germany\GermanyPack`** — German PII pack:
  - `SteuerIdDetector` (`steuer_id`) — 11-digit Steuerliche Identifikationsnummer with mod-11 ISO 7064 (Pure) checksum and the §139b AO structural rule (one digit appears 2-3 times, at least one absent). Spec: §139b Abgabenordnung; Bundeszentralamt für Steuern.
  - `UStIdNrDetector` (`ust_idnr`) — `DE` + 9-digit Umsatzsteuer-Identifikationsnummer with BMF Method 30 mod-11 checksum. Spec: §27a UStG.
  - `PhoneGermanDetector` (`phone_de`) — German mobile (017x/015x/016x) + landline with optional `+49` / `0049` prefix and `(0)` parenthesised trunk.
  - `AddressGermanDetector` (`address_de`) — heuristic German street addresses covering Straße / Str. / Allee / Platz / Weg / Gasse / Ring / Damm / Ufer / Brücke / Hof plus prefix forms `Am`, `An der`, `Auf der`, `Im`, `In der`, `Zur`. Optional civic + 5-digit Postleitzahl + city.
  - 10 valid synthetic-but-checksum-correct fixtures + 5 invalid-checksum + 5 wrong-format per checksum detector. Pure heuristics for phone/address: 10 happy-path + 5 negative.
- **`Padosoft\PiiRedactor\Packs\Spain\SpainPack`** — Spanish PII pack:
  - `DniDetector` (`dni`) — 8-digit + 1-letter Documento Nacional de Identidad with the 23-letter checksum table (`TRWAGMYFPDXBNJZSQVHLCKE`). Spec: Real Decreto 1553/2005.
  - `NieDetector` (`nie`) — Número de Identidad de Extranjero with prefix-substituted DNI algorithm (X→0, Y→1, Z→2).
  - `CifDetector` (`cif`) — Código de Identificación Fiscal corporate ID with mixed digit/letter control depending on the leading letter group.
  - `PhoneSpanishDetector` (`phone_es`) — Spanish mobile (6/7-prefix) + landline (8/9-prefix), with optional `+34` / `0034`.
  - `AddressSpanishDetector` (`address_es`) — heuristic Spanish street addresses covering Calle / C/ / Avenida / Avd. / Avda. / Plaza / Pza. / Paseo / P.º / Carrer / Travesía / Glorieta / Ronda with optional `de la / de los / del` connectives.
  - Same fixture standards as GermanyPack.
- **Cross-pack architecture isolation tests** — `tests/Architecture/GermanyPackIsolationTest.php` + `SpainPackIsolationTest.php` enforce R37 + the v1.0 zero-cross-pack-imports invariant.

### Changed

- `README.md` — three packs now documented (Italy default + Germany / Spain opt-in). Architecture diagram extended; tagline + design rationale refreshed; Roadmap moves DE + ES from "v1.x candidates" to "shipped"; v1.2+ candidates kept as future invitation.
- `config/pii-redactor.php` `packs` block — commented-out FQCNs for `GermanyPack` + `SpainPack` shown alongside the default `ItalyPack` entry to make opt-in trivial.

### Backward compatibility

v1.1 is a **drop-in upgrade** from v1.0 — no breaking changes. The default `packs` config still ships only `[ItalyPack::class]`; hosts opt-in to GermanyPack / SpainPack by uncommenting the relevant lines in their published config (or by adding the FQCN explicitly).

### Test surface

**~440 tests** (up from v1.0's 368). 4 detector test classes + 1 pack test per country (DE + ES) + 2 cross-pack architecture isolation tests.

### Implementation notes

The v1.1 work was scaffolded by **4 parallel Claude sub-agents** under strict file allowlists (Agent A: GermanyPack; Agent B: SpainPack; Agent C: README + config + CHANGELOG; Agent D: cross-pack architecture tests). Pattern proven across the v0.x → v1.x train: same template will drive v1.2+ community packs.

## [1.0.0] - 2026-05-03

### Added — EU country pack architecture

- **`Padosoft\PiiRedactor\Packs\PackContract`** — interface defining
  `name()` / `countryCode()` / `description()` / `detectors(): list<Detector>`.
  The foundation for community-contributed country packs (Germany, Spain,
  France, Netherlands, Portugal, Iceland, etc.).
- **`Padosoft\PiiRedactor\Packs\Italy\ItalyPack`** — reference implementation
  aggregating the four existing IT detectors (`CodiceFiscaleDetector`,
  `PartitaIvaDetector`, `PhoneItalianDetector`, `AddressItalianDetector`).
  Backward-compatible: existing detector classes stay in `src/Detectors/`;
  the pack aggregates without relocating.
- **`Padosoft\PiiRedactor\Packs\DetectorPackRegistry`** — service that walks
  `config('pii-redactor.packs')`, container-resolves each FQCN, validates
  `PackContract` + `Detector` contracts, returns the concatenated detector
  list. Throws `PackException` on invalid entries.
- **`Padosoft\PiiRedactor\Exceptions\PackException`** — extends
  `PiiRedactorException`. Surfaces missing FQCN / wrong contract.
- **`config('pii-redactor.packs')`** — new config key. Default
  `[\Padosoft\PiiRedactor\Packs\Italy\ItalyPack::class]` preserves v0.x
  behavior. Hosts disable Italy by removing the entry; hosts enable
  community packs (v1.1+) by adding their FQCN.
- **`PiiRedactorServiceProvider`** — extended `RedactorEngine` resolver
  with a pack-detector loop AFTER the existing flat-list loop. Both
  surfaces coexist (v0.x flat list + v1.0 packs).

### Added — Community documentation

- **`CONTRIBUTING-PACKS.md`** (343 lines) — community guide for country-pack
  PRs. Covers: when to use a pack vs YAML rules, pack structure on disk,
  `PackContract` walkthrough, checksum implementation discipline (cite
  spec source + 10 valid + 5 invalid + 5 wrong-format fixtures), R37
  standalone-agnostic invariant, test parity, PR checklist, 7-day review
  SLA, Apache-2.0 licensing.
- **`MIGRATION.md`** (167 lines) — v0.x → v1.0 zero-friction upgrade guide.
  Covers what's new architecturally, what hosts MAY do (additive `packs`
  config), what they MUST NOT do, and the formal v1.0 PHP/Laravel
  compatibility lock.
- **`SECURITY.md`** (replaced 16-line minimal version with 83-line
  community-grade) — supported-versions table, security@padosoft.com
  reporting, 90-day default disclosure with 2/5/7-day SLAs, six in-scope
  vulnerability classes, three out-of-scope, hall-of-fame section.

### Added — Custom rules SP auto-register loop (closes v0.3 deferred TODO)

- **`config('pii-redactor.custom_rules.auto_register')`** — when `true`,
  the SP boot walks `custom_rules.packs[]` and registers each YAML pack
  via `Pii::extend('<name>', new CustomRuleDetector('<name>', $set))`.
  Validation errors throw `CustomRuleException` at boot.
- v0.3 hosts using manual `Pii::extend()` bootstrap continue working
  unchanged. v1.0 hosts can switch to declarative config.

### Added — Robustness suite v2

- **`tests/Unit/Robustness/PackOverlapTest.php`** (3 tests) — pins
  pack-overlap behavior: free-floating detector vs pack same-name
  collision; empty pack list still registers multi-country detectors;
  two packs same `name()` collapse via engine `register()`.
- **`tests/Unit/Robustness/PerfBenchTest.php`** (5 tests, `#[Group('perf')]`) —
  pins performance budgets: empty `<1ms`, 1KB `<10ms`, 100KB `<100ms`,
  1MB `<2000ms`, peak memory delta `<64MB`.
- **`tests/Unit/Robustness/StreamingTest.php`** (3 tests) — 100
  incremental redact() calls memory ceiling `<16MB`; redact()/scan()
  count agreement; second-pass scan() on redacted output returns 0.
- **`tests/Unit/Robustness/ConcurrencyTest.php`** extended with 2 v2
  scenarios — 100 sequential `DatabaseTokenStore::put()` collapse on
  duplicate token (UNIQUE invariant); 100 distinct tokens yield 100
  rows.
- **`tests/Unit/Packs/`** — 14 new tests across `PackContractTest`,
  `Italy/ItalyPackTest`, `DetectorPackRegistryTest`.
- **`tests/Unit/CustomRules/AutoRegisterTest.php`** (8 tests) — covers
  the new SP auto-register loop end-to-end.

### Changed

- **`AddressItalianDetector`** — connective alternation extended with
  apostrophe-elided forms (`dell'`, `nell'`, `sull'`, `all'`, `coll'`)
  plus `dello`. `Via dell'Università 1` now detects (was a v0.3 known
  limitation pinned by the robustness suite). Treccani citation in the
  docblock.
- **`CustomRuleDetector::detect()`** — zero-length matches (`a*` against
  `"hello"`) are now filtered with a `length === 0` continue guard.
  Prevents pollution of the detection report with empty matches.
- **`composer.json`** — `minimum-stability: dev` → `minimum-stability:
  stable`. v1.0 stable lock; the package is no longer dev-stability.
  `prefer-stable: true` retained.
- **README.md** — major polish for community release. New
  **🇪🇺 EU country pack architecture** WOW section with ASCII tree +
  enable/disable examples. New **Build your own country pack — 3-step
  recipe** (Iceland `KennitalaDetector` example) + community
  contribution CTA. New **Performance** section with concrete benchmarks
  (1KB ~0.4ms / 100KB ~25ms / 1MB ~280ms / <8MB memory) + NER latency
  notes. New **Migration guide v0.x → v1.0** section. Tagline bumped from
  "Italian-first" to "EU-first". Roadmap updated with v1.1+ candidates
  (Germany, Spain, France, Netherlands, Portugal).

### Backward compatibility

v1.0 is a **drop-in upgrade** from v0.3 / v0.2 / v0.1 — **no breaking
changes**. Existing hosts:
- Continue importing `Padosoft\PiiRedactor\Detectors\CodiceFiscaleDetector`
  (and the other 3 IT detectors) directly. They stay where they are.
- Continue using the flat `pii-redactor.detectors` config list. Both
  surfaces coexist.
- Continue using `pii-redactor.audit_trail.enabled`,
  `pii-redactor.token_store.driver`, `pii-redactor.ner.*` config keys.
- Continue using `Pii::extend()` for runtime detector registration.

### Compatibility matrix (formal v1.0 lock)

| Pkg version | PHP supported | Laravel supported |
|---|---|---|
| **1.0.x** | **8.3 / 8.4 / 8.5** | **12.x / 13.x** |
| 0.3.x | 8.3 / 8.4 / 8.5 | 12.x / 13.x |
| 0.2.x | 8.3 / 8.4 / 8.5 | 12.x / 13.x |

Future minors (v1.1, v1.2) MAY ADD support for newer PHP / Laravel; they
will not drop support for the listed versions during the v1.x lifetime.

### Test surface

**368 tests, 981 assertions** — up from v0.3's 320 / 658.

### Implementation notes

The v1.0 work was scaffolded by **5 parallel Claude sub-agents** under
strict file allowlists:
- Agent A: PackContract + ItalyPack
- Agent B: DetectorPackRegistry + SP auto-register loops
- Agent C: README WOW polish (EU pack section + 3-step recipe + perf + migration)
- Agent D: CONTRIBUTING-PACKS.md + MIGRATION.md + SECURITY.md + composer
- Agent E: Robustness v2 + AddressItalianDetector fix + CustomRuleDetector filter

Strictly partitioned scopes prevented merge conflicts on the shared
ServiceProvider + config. Pattern proven scalable: v0.2 (3 agents) →
v0.3 (5 agents) → v1.0 (5 agents).

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

[Unreleased]: https://github.com/padosoft/laravel-pii-redactor/compare/v1.1.0...HEAD
[1.1.0]: https://github.com/padosoft/laravel-pii-redactor/compare/v1.0.0...v1.1.0
[1.0.0]: https://github.com/padosoft/laravel-pii-redactor/compare/v0.3.0...v1.0.0
[0.3.0]: https://github.com/padosoft/laravel-pii-redactor/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/padosoft/laravel-pii-redactor/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/padosoft/laravel-pii-redactor/releases/tag/v0.1.0
