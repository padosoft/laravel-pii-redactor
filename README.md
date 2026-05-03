# laravel-pii-redactor

[![Tests](https://github.com/padosoft/laravel-pii-redactor/actions/workflows/ci.yml/badge.svg)](https://github.com/padosoft/laravel-pii-redactor/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/padosoft/laravel-pii-redactor.svg?style=flat-square)](https://packagist.org/packages/padosoft/laravel-pii-redactor)
[![PHP Version](https://img.shields.io/packagist/php-v/padosoft/laravel-pii-redactor.svg?style=flat-square)](https://packagist.org/packages/padosoft/laravel-pii-redactor)
[![Laravel Version](https://img.shields.io/badge/Laravel-12.x%20%7C%2013.x-red?style=flat-square&logo=laravel)](https://laravel.com)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg?style=flat-square)](LICENSE)
[![Total Downloads](https://img.shields.io/packagist/dt/padosoft/laravel-pii-redactor.svg?style=flat-square)](https://packagist.org/packages/padosoft/laravel-pii-redactor)

> **EU-first PII redaction for Laravel — deterministic regex + checksum-validated detectors organised into opt-in country packs (Italy ships built-in; Germany / Spain / France / Netherlands / Portugal land in v1.1+ as community packs), plus always-on multi-country detectors (email, IBAN mod-97 for every ISO 13616 country, credit card with Luhn) and a pluggable strategy layer (mask / hash / tokenise / drop) with persistent reverse-map storage (memory / database / cache), opt-in HuggingFace + spaCy NER drivers, and YAML custom-rule packs for tenant-specific identifiers. Zero external services in the default path, zero mandatory LLM cost, GDPR + EU AI Act ready.**

`laravel-pii-redactor` is the seventh deliverable of the [Padosoft v4.0 cycle](https://github.com/lopadova/AskMyDocs) (W7). It is a community Apache-2.0 package, **standalone-agnostic** (zero references to AskMyDocs / sister packages), and ships with the Padosoft AI vibe-coding pack so you can extend it with Claude Code or GitHub Copilot in minutes — not days.

```php
use Padosoft\PiiRedactor\Facades\Pii;

$clean = Pii::redact('Codice fiscale RSSMRA85T10A562S, IBAN IT60X0542811101000000123456, mail: mario@example.com.');
// "Codice fiscale [REDACTED], IBAN [REDACTED], mail: [REDACTED]."

$report = Pii::scan('Telefono +39 333 1234567 e P.IVA 12345678903.');
// $report->countsByDetector() === ['phone_it' => 1, 'p_iva' => 1]
```

---

## Table of contents

- [Why this package](#why-this-package)
- [Design rationale](#design-rationale)
- [Features at a glance](#features-at-a-glance)
- [🇪🇺 EU country pack architecture](#-eu-country-pack-architecture)
- [Build your own country pack — 3-step recipe](#build-your-own-country-pack--3-step-recipe)
- [Comparison vs alternatives](#comparison-vs-alternatives)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Usage examples](#usage-examples)
- [Configuration reference](#configuration-reference)
- [Architecture](#architecture)
- [AI vibe-coding pack](#ai-vibe-coding-pack)
- [Testing — Default + Live](#testing--default--live)
- [Performance](#performance)
- [Roadmap](#roadmap)
- [Migration guide v0.x → v1.0](#migration-guide-v0x--v10)
- [Contributing](#contributing)
- [Security](#security)
- [License](#license)

---

## Why this package

PII redaction is one of those domains where the existing options force a bad trade-off:

- **Build it yourself** with a few hand-crafted regexes — fast to write, but the moment a real Italian fiscal code shows up (16 alphanumeric characters with a checksum derived from a Decreto Ministeriale lookup table) your "good enough" regex starts emitting false positives that break audits.
- **Reach for Microsoft Presidio / AWS Comprehend / Google DLP** — robust, but they assume a US-centric set of identifiers. None of them validate the Italian `codice fiscale` checksum out of the box, and routing every chat-log line through a hosted PII service is operationally expensive and a GDPR amplifier.
- **Bolt an LLM-based redactor onto the pipeline** — works, but pays per-token to do something that is, fundamentally, a regular language problem.

`laravel-pii-redactor` covers the **deterministic** layer. v1.0 ships:

- **3 always-on multi-country detectors** — `email` (RFC-5321 shape), `iban` (ISO 13616 country-length table + mod-97 for **every** registered country, ~75), `credit_card` (Luhn).
- **`ItalyPack` reference pack** — `codice_fiscale` (CIN checksum from the 1976 Decreto Ministeriale), `partita_iva` (Luhn-IT), `phone_it`, `address_it` (Italian street-address heuristic).
- **`PackContract` interface + `DetectorPackRegistry`** — opt-in jurisdiction bundles. Operate in Italy only? Keep `ItalyPack`. Operate across the EU? Add `GermanyPack` / `SpainPack` / `FrancePack` (v1.1+). Operate outside Italy? Drop `ItalyPack` from the config.
- **4 pluggable replacement strategies** — `mask`, `hash`, `tokenise`, `drop`.
- **3 token-store drivers** — `memory` (default), `database` (Eloquent + shipped migration), `cache` (Redis / Memcached / DynamoDB / array).
- **2 production NER drivers (opt-in)** — `HuggingFaceNerDriver`, `SpaCyNerDriver`. Network calls fail open.
- **YAML custom-rule packs** — register tenant-specific detectors from `*.yaml` files; SP auto-registers when `pii-redactor.custom_rules.auto_register = true`.
- **Typed `DetectionReport`** — audit every redaction without re-running the engine.

It is **deliberately small** and **deliberately offline by default**. You can extend it with custom detectors via `Pii::extend()` or your own country pack. The deterministic engine fits in ~200 lines of PHP, the v1.0 surface is locked under semver, and 300+ unit tests + a robustness suite describe every transition.

---

## Design rationale

Five non-negotiable choices that drove the API:

### 1. EU-first via opt-in country packs. World-second.

Every PII pipeline I have seen for Laravel either ignores European fiscal data or matches it with a bare regex that returns false positives on every retry CI run. **National identifiers need real code**: the Italian `codice fiscale` requires the official odd/even checksum table from the 1976 Decreto Ministeriale; the German Steuer-ID needs mod-11; the Spanish DNI needs a letter-checksum lookup; the French NIR needs mod-97. A regex alone won't do.

Hence **country packs**. v1.0 ships `ItalyPack` as the reference implementation (4 Italian detectors with the full CIN checksum + Luhn-IT). The `PackContract` interface + `DetectorPackRegistry` make it trivial for the community to contribute `GermanyPack`, `SpainPack`, `FrancePack`, `NetherlandsPack`, `PortugalPack` — each as a self-contained bundle of detectors with checksum-source citations and 10/5 valid/invalid fixtures. v1.1 lands the first community packs.

Multi-country detectors (`email`, `iban` with mod-97 for every ISO 13616 country, `credit_card` with Luhn) stay always-on regardless of which packs you load — they have no jurisdictional flavour.

### 2. Deterministic regex + checksum, no LLM in the hot path

Every first-party detector is a pure function of its input. No external HTTP call, no per-token cost, no rate limit. A 1 MB chat log redacts in ~280 ms and the output is identical on every machine. The optional NER layer (v0.3+) ships behind a config switch; the default path never touches a network.

### 3. Strategy is a runtime decision, not a compile-time one

The same detected match can be **masked** (`[REDACTED]` for human-facing logs), **hashed** (`[hash:abc123ef01234567]` for cross-record joins on pseudonymous data), **tokenised** (`[tok:email:abc123ef01234567]` with a reversible salt-derived map for forensic recovery), or **dropped** (empty string for forwarding to lossy systems). Switching strategy is a one-line override on `Pii::redact($text, new HashStrategy(...))` — no detector code changes.

### 4. Detector overlap is resolved deterministically

When two detectors emit overlapping byte ranges (e.g. an email-shaped string that also matches a phone heuristic), the engine keeps the **earlier** match (lower offset) and drops the latecomer. The behaviour is documented, tested, and predictable — callers can audit it via `Pii::scan()`.

### 5. Standalone-agnostic — zero AskMyDocs symbols

`laravel-pii-redactor` is a **community** package. It is not coupled to AskMyDocs, the sister patent-box tracker, the eval-harness, the Regolo driver, or any other Padosoft project. An architecture test (`tests/Architecture/StandaloneAgnosticTest.php`) walks `src/` with `RecursiveDirectoryIterator` on every CI run and asserts the forbidden-substring list (KnowledgeDocument, KbSearchService, AskMyDocs, PatentBoxTracker, LaravelFlow, EvalHarness, Regolo, ...) never appears.

---

## Features at a glance

- **🇪🇺 EU country pack architecture** — `PackContract` interface + `DetectorPackRegistry` boots country packs from `config('pii-redactor.packs')`. `ItalyPack` ships as the reference implementation; `GermanyPack`, `SpainPack`, `FrancePack`, `NetherlandsPack`, `PortugalPack` are community PRs welcome (see [CONTRIBUTING-PACKS.md](CONTRIBUTING-PACKS.md)).
- **3 always-on multi-country detectors** (no pack required):
  - `email` — pragmatic RFC-5321 shape match.
  - `iban` — ISO 13616 IBAN for every registered country (~75) + mod-97 verification.
  - `credit_card` — 13–19 digit PAN with Luhn validation.
- **`ItalyPack` (default — 4 detectors)**:
  - `codice_fiscale` — 16-char Italian fiscal code with full CIN checksum (Decreto Ministeriale 23/12/1976).
  - `p_iva` — 11-digit Italian VAT with Luhn-style checksum + zero-payload sentinel rejection.
  - `phone_it` — Italian mobile + landline (with optional `+39` / `0039` prefix).
  - `address_it` — Italian street address heuristic (Via / Viale / Piazza / Corso / Largo / Strada / Vicolo / Lungomare + compound forms `Via dei`, `Via della`, `Via d'…`); civic number + 5-digit CAP + city optional.
- **4 pluggable redaction strategies**: `MaskStrategy`, `HashStrategy` (deterministic, salt-derived, namespaced per detector), `TokeniseStrategy` (reversible pseudonymisation with `detokenise()` + `dumpMap()` / `loadMap()` for cross-process recovery), `DropStrategy`.
- **Persistent reverse-map storage (v0.2)** — `TokenStore` interface + `InMemoryTokenStore` (default, process-local) + `DatabaseTokenStore` (Eloquent-backed, shipped migration `pii_token_maps`). The same `[tok:...]` token detokenises across deploys / queue workers when the database driver is wired. Switch via `PII_REDACTOR_TOKEN_STORE=database` and run `php artisan vendor:publish --tag=pii-redactor-migrations && php artisan migrate`.
- **Audit-trail event (v0.2)** — opt-in `PiiRedactionPerformed` Laravel event fired after a `redact()` call that **produced at least one detection**, when `PII_REDACTOR_AUDIT_TRAIL=true` (or the structured `audit_trail.enabled` key is set). No-op redactions (engine disabled, empty input, zero detections) skip the dispatch — the event signals "redaction occurred", not "request processed". Event carries **counts only** (detector → match count, total, strategy name) — NEVER raw PII or redacted output. GDPR-friendly by construction.
- **NER drivers (v0.2 scaffold + v0.3 production)** — `NerDriver` interface + `StubNerDriver` (no-op default), `HuggingFaceNerDriver` (HuggingFace Inference API via `Http::`, opt-in via `PII_REDACTOR_HUGGINGFACE_API_KEY`), `SpaCyNerDriver` (generic spaCy HTTP server protocol returning `Doc.to_json()` shape, opt-in via `PII_REDACTOR_SPACY_SERVER_URL`). Both real drivers fail open on HTTP errors so a NER outage cannot block deterministic redaction. Driver detections merge into the same overlap-resolution pipeline as first-party detectors.
- **Cache-backed `TokenStore` (v0.3)** — third driver alongside `InMemoryTokenStore` and `DatabaseTokenStore`. Uses Laravel's `Illuminate\Contracts\Cache\Repository` so deployments swap between Redis / Memcached / DynamoDB / array (test) without touching package code. Maintains an explicit index entry so `dump()` / `clear()` work without scanning the backend keyspace. Optional TTL via `PII_REDACTOR_TOKEN_STORE_CACHE_TTL`. Switch with `PII_REDACTOR_TOKEN_STORE=cache`.
- **Custom-rule YAML packs (v0.3 + v1.0 auto-register)** — register tenant-specific detectors from `*.yaml` files. v1.0 adds an SP-level auto-register loop driven by `config('pii-redactor.custom_rules.packs')` so you can drop YAML packs into a config array and the SP wires them at boot. The host-controlled API still works for tenant-specific bootstrap logic:
   ```php
   $set = (new YamlCustomRuleLoader())->load(storage_path('app/pii-rules/it-albo.yaml'));
   Pii::extend('custom_it_albo', new CustomRuleDetector('custom_it_albo', $set));
   ```
   Each rule has a `name` + PCRE `pattern` + optional `flags` (default `u`). Invalid PCRE is rejected at first-match time with a clear `CustomRuleException`. Useful for Italian professional registry IDs (`ISCR-...`, `Tess-XX-...`), tenant-specific account codes, project tracker identifiers, etc.
- **Live test suite (v0.3)** — `tests/Live/` houses opt-in tests against real APIs (HuggingFace, spaCy server). Each test self-skips unless `PII_REDACTOR_LIVE=1` AND its driver-specific credentials are set. CI runs `Unit` + `Architecture` only — Live tests are operator-driven. See `tests/Live/README.md` for the convention.
- **Typed `DetectionReport`** — `total()`, `countsByDetector()`, `samplesByDetector(cap)`, `toArray()`. Stable JSON shape for downstream auditors.
- **`Pii::extend()` registry** for custom detectors (`custom_codice_iscrizione_albo`, project-specific account ids, etc.).
- **Artisan command** — `php artisan pii:scan path/to/file.txt --pretty` or `cat data | php artisan pii:scan --from=stdin` (samples masked by default; pass `--show-samples` for raw values during interactive forensics).
- **Standalone-agnostic** — zero coupling to AskMyDocs / sister packages, enforced by an architecture test.
- **PHP 8.3 / 8.4 / 8.5** × **Laravel 12 / 13** matrix. Pint + PHPStan level 6 + 400+ PHPUnit tests on every push.
- **Padosoft AI vibe-coding pack** (`.claude/`) — Claude Code skills (R36 review loop, R10–R37 rules) + agents (review pre-push) + commands (`/create-job`, `/domain-scaffold`).

---

## 🇪🇺 EU country pack architecture

**Why country packs exist.** Italian fiscal codes need PHP code with checksum logic. So do German Steuer-ID (mod-11), Spanish DNI (letter-checksum), French NIR (mod-97). Pure regex isn't enough. Each country needs its own bundle of detectors — but the package shouldn't ship all of EU's IDs by default if you only operate in Italy. **Hence packs**: opt-in jurisdiction bundles, registered via the `PackContract` interface and a config array.

```
Padosoft\PiiRedactor\
├── Detectors\                         (multi-country, always-on)
│   ├── EmailDetector (RFC-5321 shape)
│   ├── IbanDetector (ISO 13616 mod-97 — every EU country)
│   └── CreditCardDetector (Luhn)
└── Packs\
    ├── PackContract                   (interface)
    └── Italy\
        ├── ItalyPack                  (default — config('pii-redactor.packs'))
        │   └── detectors() returns:
        │       ├── CodiceFiscaleDetector (CIN checksum)
        │       ├── PartitaIvaDetector (Luhn-IT)
        │       ├── PhoneItalianDetector
        │       └── AddressItalianDetector
```

**Enable / disable example**:

```php
// config/pii-redactor.php
'packs' => [
    \Padosoft\PiiRedactor\Packs\Italy\ItalyPack::class,
    // \Padosoft\PiiRedactor\Packs\Spain\SpainPack::class,    // when v1.1 ships
    // \Padosoft\PiiRedactor\Packs\Germany\GermanyPack::class, // when v1.1 ships
],
```

To disable Italy on an English-only deployment:

```php
'packs' => [
    // ItalyPack removed — codice fiscale / P.IVA / Italian phone / Italian address detectors NOT registered
],
```

The multi-country detectors (Email, IBAN, CreditCard) keep working regardless — they are **never** part of a country pack because they have no jurisdictional flavour.

---

## Build your own country pack — 3-step recipe

The recipe below uses Iceland (small, real European country, no community pack ships yet) as a "blank slate" example. The real `kennitala` checksum is mod-11 over the first 9 digits.

### Step 1 — Create the detector(s)

```php
// src/Packs/Iceland/Detectors/KennitalaDetector.php
namespace Padosoft\PiiRedactor\Packs\Iceland\Detectors;

use Padosoft\PiiRedactor\Detectors\Detection;
use Padosoft\PiiRedactor\Detectors\Detector;

final class KennitalaDetector implements Detector
{
    public function name(): string
    {
        return 'kennitala';
    }

    public function detect(string $text): array
    {
        // 10 digits with mod-11 checksum on the first 9.
        if (preg_match_all('/\b(\d{6}-?\d{4})\b/u', $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $hits = [];
        foreach ($matches[1] as $m) {
            $value = preg_replace('/-/', '', (string) $m[0]);
            if (! $this->validChecksum($value)) {
                continue;
            }
            $hits[] = new Detection('kennitala', (string) $m[0], (int) $m[1], strlen((string) $m[0]));
        }
        return $hits;
    }

    private function validChecksum(string $kt): bool
    {
        // Weights: 3, 2, 7, 6, 5, 4, 3, 2 over the first 8 digits;
        // ninth digit is the check digit; mod-11 with 11 - r mapping.
        // ... real implementation here ...
        return true;
    }
}
```

### Step 2 — Wrap them in a pack

```php
// src/Packs/Iceland/IcelandPack.php
namespace Padosoft\PiiRedactor\Packs\Iceland;

use Padosoft\PiiRedactor\Packs\PackContract;
use Padosoft\PiiRedactor\Packs\Iceland\Detectors\KennitalaDetector;

final class IcelandPack implements PackContract
{
    public function name(): string        { return 'iceland'; }
    public function countryCode(): string { return 'IS'; }
    public function description(): string { return 'Icelandic kennitala (mod-11) + (future) phone / address detectors.'; }

    public function detectors(): array
    {
        return [
            new KennitalaDetector(),
        ];
    }
}
```

### Step 3 — Register it

```php
// config/pii-redactor.php
'packs' => [
    \Padosoft\PiiRedactor\Packs\Italy\ItalyPack::class,
    \Padosoft\PiiRedactor\Packs\Iceland\IcelandPack::class,  // your new pack
],
```

That's it. The ServiceProvider boots, the `DetectorPackRegistry` walks the config list, instantiates each pack, and feeds its `detectors()` into the engine. `Pii::redact()` and `Pii::scan()` now redact / report `kennitala` matches alongside the always-on detectors.

> **🚀 Contribute your country pack**
>
> Built a `GermanyPack` / `SpainPack` / `FrancePack` / etc. that meets the contribution standards (checksum source citation + 10 valid + 5 invalid test fixtures + R37 standalone-agnostic + pack-isolation architecture test)? **Open a PR** — see [CONTRIBUTING-PACKS.md](CONTRIBUTING-PACKS.md) for the workflow. Accepted packs ship in the package itself (not as separate composer requires) so consumers get the entire EU coverage with one dependency.

---

## Comparison vs alternatives

|                                       | laravel-pii-redactor | Microsoft Presidio | Spatie data-redaction approaches | AWS Comprehend PII | Google Cloud DLP |
|---------------------------------------|----------------------|--------------------|----------------------------------|--------------------|------------------|
| Native Laravel facade + ServiceProvider | YES                  | NO (Python)        | YES (different scope)            | NO (AWS SDK)       | NO (GCP SDK)     |
| Italian `codice fiscale` checksum     | YES (CIN table)      | partial regex      | NO                               | NO                 | NO               |
| Italian `partita IVA` checksum        | YES (Luhn-IT)        | NO                 | NO                               | NO                 | NO               |
| ISO 13616 IBAN mod-97 (every country) | YES                  | structural only    | NO                               | partial            | partial          |
| Reversible pseudonymisation (`detokenise`) | YES (deterministic)  | NO                 | partial (custom)                 | NO                 | partial (DLP de-id) |
| Operates entirely offline             | YES                  | YES (Python)       | YES                              | NO (AWS API)       | NO (GCP API)     |
| Per-detector hash namespacing         | YES                  | NO                 | NO                               | NO                 | partial          |
| GDPR data-minimisation friendly       | YES (no transit)     | YES                | YES                              | NO (US transit)    | NO (US transit)  |
| Per-tenant custom detectors           | `Pii::extend()`      | yaml + Python      | manual                           | custom entities    | custom infoTypes |
| Cost per 1M characters                | EUR 0                | self-hosted        | EUR 0                            | ~ EUR 1            | ~ EUR 1.50       |
| `composer require` install            | YES                  | NO                 | YES (different package)          | NO                 | NO               |

`laravel-pii-redactor` is **not** a Presidio replacement for fuzzy entity recognition — Presidio's NER layer (PERSON, ORG, LOC) is genuinely more capable, and you can plug it (or HuggingFace, or spaCy) into this package via the `NerDriver` interface (v0.3+). The deterministic regex + checksum + per-country pack core stays the strongest layer where the existing EU-aware options are weakest.

---

## Installation

```bash
composer require padosoft/laravel-pii-redactor
```

Laravel auto-discovery wires the `PiiRedactorServiceProvider` and the `Pii` facade alias. Publish the config to override defaults:

```bash
php artisan vendor:publish --tag=pii-redactor-config
```

Set the salt for the hash / tokenise strategies in your `.env`:

```dotenv
PII_REDACTOR_STRATEGY=mask
PII_REDACTOR_SALT=<32+ random characters; treat like APP_KEY>
```

---

## Quick start

```php
use Padosoft\PiiRedactor\Facades\Pii;

// Default mask strategy.
$clean = Pii::redact('Codice fiscale RSSMRA85T10A562S e P.IVA 12345678903.');
// "Codice fiscale [REDACTED] e P.IVA [REDACTED]."

// Audit a payload before redacting.
$report = Pii::scan('Email mario@example.com IBAN IT60X0542811101000000123456.');
$report->countsByDetector(); // ['email' => 1, 'iban' => 1]

// One-off strategy override (without changing config).
use Padosoft\PiiRedactor\Strategies\HashStrategy;
$hashed = Pii::redact('mario@example.com', new HashStrategy(salt: env('PII_REDACTOR_SALT')));
// "[hash:f72a1b09abc12345]"  (16 hex chars — 64-bit namespace)
```

---

## Usage examples

### Reversible pseudonymisation for forensic exports

```php
use Padosoft\PiiRedactor\Strategies\TokeniseStrategy;

$strategy = new TokeniseStrategy(salt: env('PII_REDACTOR_SALT'));

// Tokenise — same input always produces the same token under a fixed salt.
$redacted = Pii::redact($chatLog, $strategy);

// ... ship $redacted to a downstream system that does NOT need the originals ...

// Later, on the secure side, rehydrate when an auditor requests it.
$auditPayload = $strategy->detokeniseString($redacted);
```

### Custom detector via `Pii::extend()`

```php
use Padosoft\PiiRedactor\Detectors\Detection;
use Padosoft\PiiRedactor\Detectors\Detector;
use Padosoft\PiiRedactor\Facades\Pii;

class CodiceIscrizioneAlboDetector implements Detector
{
    public function name(): string { return 'custom_albo'; }

    public function detect(string $text): array
    {
        if (preg_match_all('/ISCR-\d{6,}/', $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }
        $hits = [];
        foreach ($matches[0] as $m) {
            $hits[] = new Detection('custom_albo', (string) $m[0], (int) $m[1], strlen((string) $m[0]));
        }
        return $hits;
    }
}

Pii::extend('custom_albo', new CodiceIscrizioneAlboDetector);
```

### CLI — scan a file in CI

```bash
# Samples are masked by default to keep raw PII out of CI logs.
php artisan pii:scan storage/exports/chat-log.txt --pretty

# Pass --show-samples for interactive forensics on a trusted terminal.
php artisan pii:scan storage/exports/chat-log.txt --pretty --show-samples
```

Default (masked-samples) output:

```json
{
    "total": 4,
    "counts": { "email": 2, "iban": 1, "p_iva": 1 },
    "samples": {
        "email": ["[email]", "[email]"],
        "iban": ["[iban]"],
        "p_iva": ["[p_iva]"]
    }
}
```

With `--show-samples` (raw values restored):

```json
{
    "total": 4,
    "counts": { "email": 2, "iban": 1, "p_iva": 1 },
    "samples": {
        "email": ["mario@example.com", "anna@example.com"],
        "iban": ["IT60X0542811101000000123456"],
        "p_iva": ["12345678903"]
    }
}
```

---

## Configuration reference

Every key in `config/pii-redactor.php` is documented inline. Highlights:

- `enabled` — master switch. When `false`, `Pii::redact()` returns input unchanged. Wired all the way down to the `RedactorEngine` constructor so `PII_REDACTOR_ENABLED=false` in `.env` short-circuits redaction without code changes.
- `strategy` — `mask | hash | tokenise | drop`. Default mask token is `[REDACTED]`.
- `salt` — required for `hash` and `tokenise`. Treat like `APP_KEY`.
- `mask_token` — override the default mask string.
- `hash_hex_length` — between 4 and 64; default **16** (= 64-bit namespace, well above the birthday bound for any realistic corpus). Drop to 8 only if you accept that downstream joins on `[hash:...]` may collapse unrelated records once the dataset crosses ~30k uniques.
- `token_hex_length` — between 8 and 64; default **16** for the `[tok:<detector>:<id>]` id portion. Same collision argument as `hash_hex_length`.
- `detectors` — whitelist of multi-country detector classes the ServiceProvider auto-registers (`EmailDetector`, `IbanDetector`, `CreditCardDetector` by default). Removing an entry disables the detector. Country-specific detectors are loaded via the `packs` array, not here. Custom detectors registered via `Pii::extend()` bypass this list. Misconfigured FQCNs (existing class that does not implement `Detector`) raise a `DetectorException` at boot rather than crashing later with a `TypeError`.
- `packs` — array of `PackContract` FQCNs the ServiceProvider boots into the `DetectorPackRegistry`. Default ships `[ItalyPack::class]`. Add `GermanyPack::class` / `SpainPack::class` / etc. when v1.1+ packs land, or your own pack (see [CONTRIBUTING-PACKS.md](CONTRIBUTING-PACKS.md)). Misconfigured FQCNs are caught at boot.
- `custom_rules.auto_register` — when `true` (v1.0+), the SP walks `custom_rules.packs` and auto-registers each YAML pack at boot. Defaults to `false` for v0.x parity.
- `custom_rules.packs` — array of YAML pack file paths (e.g. `storage_path('app/pii-rules/it-albo.yaml')`). Each file becomes a `CustomRuleDetector` registered under the alias declared in the YAML.
- `audit_trail_enabled` (v0.1 BC) and `audit_trail.enabled` (v0.2 structured) — when true, the engine fires `PiiRedactionPerformed` after every `redact()` call. Payload carries counts only (no raw PII or redacted output). The structured key is preferred; the flat key remains as a fallback so v0.1 hosts upgrade transparently.
- `ner.enabled` / `ner.driver` / `ner.drivers` — opt-in NER. Drivers: `stub` (no-op default), `huggingface` (HuggingFace Inference API via `Http::`, opt-in via `PII_REDACTOR_HUGGINGFACE_API_KEY`), `spacy` (generic spaCy HTTP server via `PII_REDACTOR_SPACY_SERVER_URL`).
- `token_store.driver` — `memory` (default) | `database` | `cache`. The database driver requires the shipped migration: `php artisan vendor:publish --tag=pii-redactor-migrations && php artisan migrate`. The cache driver runs over `Illuminate\Contracts\Cache\Repository` with optional TTL + maintained index (Redis / Memcached / DynamoDB / array). Switch with `PII_REDACTOR_TOKEN_STORE=database` or `=cache`.
- `token_store.database.connection` / `token_store.database.table` — isolate the `pii_token_maps` table on a dedicated DB connection (recommended for hosts that already partition PII from operational data).
- `token_store.cache.store` / `token_store.cache.prefix` / `token_store.cache.ttl` — pin the cache backend (`redis`, `memcached`, `array`, etc.), key prefix, and optional TTL for the `CacheTokenStore` driver.

---

## Architecture

```
src/
 ├── PiiRedactorServiceProvider.php        config publish + DI bindings + commands + migrations (v0.2)
 ├── RedactorEngine.php                    core orchestrator (detectors + strategy + overlap + NER + audit-trail)
 ├── Facades/Pii.php                       static-method surface for hosts
 ├── Console/PiiScanCommand.php            php artisan pii:scan
 ├── Detectors/                            multi-country, always-on
 │    ├── Detector.php                     interface
 │    ├── Detection.php                    immutable value object
 │    ├── EmailDetector.php
 │    ├── IbanDetector.php
 │    └── CreditCardDetector.php
 ├── Packs/                                v1.0 — opt-in country bundles
 │    ├── PackContract.php                 interface (name / countryCode / description / detectors)
 │    ├── DetectorPackRegistry.php         resolves config('pii-redactor.packs') into engine detectors
 │    └── Italy/
 │         ├── ItalyPack.php               default — registered in config('pii-redactor.packs')
 │         └── Detectors/
 │              ├── CodiceFiscaleDetector.php   (CIN checksum)
 │              ├── PartitaIvaDetector.php      (Luhn-IT)
 │              ├── PhoneItalianDetector.php
 │              └── AddressItalianDetector.php  (Italian street-address heuristic)
 ├── Strategies/
 │    ├── RedactionStrategy.php            interface
 │    ├── MaskStrategy.php
 │    ├── HashStrategy.php
 │    ├── TokeniseStrategy.php             reversible — accepts a TokenStore (v0.2)
 │    └── DropStrategy.php
 ├── TokenStore/                           v0.2 — persistent reverse-map storage
 │    ├── TokenStore.php                   interface (put/get/has/clear/dump/load)
 │    ├── InMemoryTokenStore.php           default — process-local, zero I/O
 │    ├── DatabaseTokenStore.php           Eloquent-backed (chunkById dump, chunked upsert load)
 │    └── Eloquent/
 │         └── PiiTokenMap.php             model for the pii_token_maps table
 ├── Events/
 │    └── PiiRedactionPerformed.php        v0.2 — Dispatchable, counts-only payload
 ├── Ner/                                  v0.2 — pluggable named-entity recognition
 │    ├── NerDriver.php                    interface (name, detect)
 │    └── StubNerDriver.php                no-op default; HuggingFace + spaCy in v0.3
 ├── Reports/
 │    └── DetectionReport.php              total() / countsByDetector() / samplesByDetector() / toArray()
 └── Exceptions/
      ├── PiiRedactorException.php         non-final base
      ├── DetectorException.php
      └── StrategyException.php

database/
 └── migrations/
      └── 2026_05_03_000001_create_pii_token_maps_table.php   v0.2 — DatabaseTokenStore schema

src/Ner/                                                       v0.3 — production NER drivers
 ├── HuggingFaceNerDriver.php                                  HF Inference API via Http::
 └── SpaCyNerDriver.php                                        spaCy server (Doc.to_json shape)

src/TokenStore/CacheTokenStore.php                             v0.3 — third store driver

src/CustomRules/                                               v0.3 — YAML custom-rule packs
 ├── CustomRule.php                                            VO: name + pattern + flags
 ├── CustomRuleSet.php                                         typed list with fromArray()
 ├── YamlCustomRuleLoader.php                                  symfony/yaml-backed loader
 └── CustomRuleDetector.php                                    Detector wrapping a CustomRuleSet

src/Exceptions/CustomRuleException.php                         v0.3 — bad YAML / invalid PCRE

tests/Live/                                                    v0.3 — opt-in real-API tests
 ├── README.md                                                 convention + per-driver env vars
 ├── HuggingFaceNerDriverLiveTest.php
 └── SpaCyNerDriverLiveTest.php
```

The engine itself is stateless with respect to the input. Calls to `redact()` / `scan()` are pure functions of `(text, registered detectors)`. Overlap resolution is left-to-right, longer-match-wins on tie — see `RedactorEngineTest::test_overlapping_detections_are_resolved_left_to_right`.

---

## AI vibe-coding pack

This repository ships with a `.claude/` directory containing the Padosoft skills, agents, rules, and commands used to build the package. Drop the directory into a host application that has Claude Code installed and you inherit:

- **R36 — Copilot PR review loop** + **R37 — branching strategy** as project rules.
- Pre-push review agent (`pre-push-self-review`) that anticipates Copilot findings.
- Slash commands (`/create-job`, `/domain-scaffold`, `/domain-service`) tuned for the Padosoft Laravel pattern.
- Skills covering testid conventions, PHPUnit / Vitest / Playwright authoring, CI failure investigation.

Open the repo in Claude Code and `/help` lists everything.

---

## Testing — Default + Live

```bash
composer install
vendor/bin/phpunit                              # Full Unit suite — default, ~400 tests, offline.
vendor/bin/phpunit --testsuite Architecture     # standalone-agnostic + pack-isolation invariants.
vendor/bin/phpunit --testsuite Robustness       # Unicode + boundary + 1MB-document regression gate.
```

The `Live` suite is **opt-in** and reserved for scenarios that require a real external dependency (HuggingFace Inference API, spaCy HTTP server). Each Live test self-skips unless `PII_REDACTOR_LIVE=1` is set AND its driver-specific credentials are configured. CI runs Unit + Architecture + Robustness only — Live is operator-driven.

---

## Performance

Concrete numbers for the synchronous, deterministic path (no NER, no cache hit), measured on PHP 8.4 / Laravel 13 / standard CI hardware:

| Input size                      | Time      | Notes                                                              |
|---------------------------------|-----------|--------------------------------------------------------------------|
| 1 KB Italian text (mixed PII)   | ~0.4 ms   | single-pass regex matching against 7 detectors (3 always-on + ItalyPack). |
| 100 KB document                 | ~25 ms    | linear in input length; no per-detector backtracking explosion.     |
| 1 MB document                   | ~280 ms   | gated by `tests/Unit/Robustness/UnicodeAndBoundaryTest::test_engine_handles_1mb_document_in_reasonable_time` to keep regressions out of `main`. |
| Memory (1 MB / ~1000 detections) | < 8 MB total | input string + detection list (~32 bytes per detection on 64-bit). |

NER drivers add **network latency** to the synchronous figures above (NER is opt-in and disabled by default):

- **HuggingFace Inference API** — cold start 10–30 s (model warm-up); warm requests ~150 ms RTT.
- **spaCy local HTTP server** — ~30–80 ms RTT.

Both drivers fail open on HTTP error, so a NER outage **cannot** block deterministic redaction. The robustness suite exercises Unicode boundaries, multi-byte CAP/civic markers, overlapping ranges across detectors, and the 1 MB regression gate on every CI push.

---

## Roadmap

- **v0.1.0 (W7, shipped 2026-04-30)** — 6 deterministic detectors (`codice_fiscale`, `p_iva`, `iban`, `email`, `phone_it`, `credit_card`), 4 strategies, `Pii::extend()`, `pii:scan` command (masked samples by default), 80+ PHPUnit tests, standalone-agnostic invariant.
- **v0.2.0 (W4.1, shipped 2026-05-03)** — `address_it` Italian street-address heuristic detector (7th first-party detector). `PiiRedactionPerformed` Laravel event fired by the engine when `audit_trail.enabled = true`; carries counts only (no raw PII). Persistent `TokenStore` interface + `InMemoryTokenStore` (default) + `DatabaseTokenStore` (Eloquent + `pii_token_maps` migration). NER `NerDriver` scaffold (`StubNerDriver` ships) with `withNerDriver()` immutable setter on the engine. 158 PHPUnit tests on the v0.2 surface.
- **v0.3.0 (W4.1, shipped 2026-05-03)** — production NER drivers (`HuggingFaceNerDriver` + `SpaCyNerDriver` via `Http::`), Italian custom-rule YAML loader (`CustomRule` + `CustomRuleSet` + `YamlCustomRuleLoader` + `CustomRuleDetector` + `CustomRuleException`), cache-backed `TokenStore` driver (`CacheTokenStore` over `Illuminate\Contracts\Cache\Repository` with TTL + index), Live test harness. 320 PHPUnit tests / 658 assertions.
- **v1.0.0 (W4.1, this PR)** — **EU country pack architecture**. `PackContract` interface + `ItalyPack` reference implementation. `DetectorPackRegistry` resolving config-listed packs into engine detectors. SP auto-register loop for custom_rules YAML packs (closes v0.3 deferred TODO). Stable surface lock + semver guarantees + formal compatibility matrix (PHP 8.3/8.4/8.5 × Laravel 12/13). Migration guide v0.x → v1.0 (no breaking changes). [CONTRIBUTING-PACKS.md](CONTRIBUTING-PACKS.md) community PR guide. Hardened [SECURITY.md](SECURITY.md). 400+ PHPUnit tests on the v1.0 surface.
- **v1.1.0 (W4.1, next)** — first community-style packs: `GermanyPack` (Steuer-ID mod-11 + USt-IdNr + German phone/address) + `SpainPack` (DNI letter-checksum + NIE + CIF + Spanish phone/address). Both ship with checksum-source citations + 10/5 valid/invalid fixtures.
- **v1.2+ candidates** — `FrancePack` (NIR mod-97 + TVA + French phone/address), `NetherlandsPack` (BSN + Dutch phone/address), `PortugalPack` (NIF + Portuguese phone/address). PRs welcome — see [CONTRIBUTING-PACKS.md](CONTRIBUTING-PACKS.md).

---

## Migration guide v0.x → v1.0

> **No breaking changes.** v1.0 is a drop-in upgrade from v0.3 / v0.2 / v0.1. Existing import paths, facade calls, config keys, env vars, and the `pii_token_maps` migration all continue to work unchanged.

**What you gain by upgrading**:

- The four Italian detectors continue to be registered automatically (now via `ItalyPack` instead of the flat `pii-redactor.detectors` list, but the observable behaviour is identical — same detector names, same `DetectionReport` shape, same overlap-resolution order).
- Hosts can now opt-in to additional country packs (v1.1+) by adding their FQCN to `config('pii-redactor.packs')`.
- YAML custom-rule packs auto-register at boot when `pii-redactor.custom_rules.auto_register = true` — no more manual `Pii::extend()` bootstrap code.

**What you should consider doing**:

- Move tenant-specific `Pii::extend()` calls out of bootstrap into the YAML pack format (one yaml file per detector pack); set `auto_register = true`.
- If you operate outside Italy and previously stripped Italian detectors via `unset(config('pii-redactor.detectors')[...])`, switch to the cleaner `'packs' => []` pattern.
- If you ship custom country detectors, consider proposing them upstream as a community pack — see [CONTRIBUTING-PACKS.md](CONTRIBUTING-PACKS.md).

---

## Contributing

PRs welcome. Please read:

- [CONTRIBUTING.md](CONTRIBUTING.md) — general PR workflow.
- [CONTRIBUTING-PACKS.md](CONTRIBUTING-PACKS.md) — **how to contribute a country pack** (`GermanyPack`, `SpainPack`, etc.): checksum source citation, 10 valid + 5 invalid fixtures, R37 standalone-agnostic compliance, pack-isolation architecture test.

Every PR follows the **R36 Copilot review + CI green loop** before merge. The architecture test gates standalone-agnostic violations on every push.

---

## Security

Found a vulnerability? Email security@padosoft.com — please do **not** open a public issue. See [SECURITY.md](SECURITY.md) for the full disclosure policy.

---

## License

Apache-2.0 — see [LICENSE](LICENSE).
