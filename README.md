# laravel-pii-redactor

[![Tests](https://github.com/padosoft/laravel-pii-redactor/actions/workflows/ci.yml/badge.svg)](https://github.com/padosoft/laravel-pii-redactor/actions/workflows/ci.yml)
[![Latest Version](https://img.shields.io/packagist/v/padosoft/laravel-pii-redactor.svg?style=flat-square)](https://packagist.org/packages/padosoft/laravel-pii-redactor)
[![PHP Version](https://img.shields.io/packagist/php-v/padosoft/laravel-pii-redactor.svg?style=flat-square)](https://packagist.org/packages/padosoft/laravel-pii-redactor)
[![Laravel Version](https://img.shields.io/badge/Laravel-12.x%20%7C%2013.x-red?style=flat-square&logo=laravel)](https://laravel.com)
[![License](https://img.shields.io/badge/license-Apache--2.0-blue.svg?style=flat-square)](LICENSE)
[![Total Downloads](https://img.shields.io/packagist/dt/padosoft/laravel-pii-redactor.svg?style=flat-square)](https://packagist.org/packages/padosoft/laravel-pii-redactor)

> **Italian-first PII redaction for Laravel — deterministic regex + checksum-validated detectors for `codice fiscale`, `partita IVA`, `IBAN`, plus the EU staples (email, phone, credit card, addresses) and a pluggable strategy layer (mask / hash / tokenise / drop). Zero external services, zero LLM cost, GDPR + EU AI Act ready.**

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
- [Comparison vs alternatives](#comparison-vs-alternatives)
- [Installation](#installation)
- [Quick start](#quick-start)
- [Usage examples](#usage-examples)
- [Configuration reference](#configuration-reference)
- [Architecture](#architecture)
- [AI vibe-coding pack](#ai-vibe-coding-pack)
- [Testing — Default + Live](#testing--default--live)
- [Roadmap](#roadmap)
- [Contributing](#contributing)
- [Security](#security)
- [License](#license)

---

## Why this package

PII redaction is one of those domains where the existing options force a bad trade-off:

- **Build it yourself** with a few hand-crafted regexes — fast to write, but the moment a real Italian fiscal code shows up (16 alphanumeric characters with a checksum derived from a Decreto Ministeriale lookup table) your "good enough" regex starts emitting false positives that break audits.
- **Reach for Microsoft Presidio / AWS Comprehend / Google DLP** — robust, but they assume a US-centric set of identifiers. None of them validate the Italian `codice fiscale` checksum out of the box, and routing every chat-log line through a hosted PII service is operationally expensive and a GDPR amplifier.
- **Bolt an LLM-based redactor onto the pipeline** — works, but pays per-token to do something that is, fundamentally, a regular language problem.

`laravel-pii-redactor` covers the **deterministic** layer. v0.1 ships six detectors with checksum validation where the underlying spec defines one (`codice fiscale`, `partita IVA`, `IBAN` — full ISO 13616 country-length table + mod-97), four pluggable replacement strategies, and a typed `DetectionReport` so you can audit every redaction without re-running the engine.

It is **deliberately small** and **deliberately offline**. You can extend it with custom detectors via `Pii::extend()`. v0.2 will add an opt-in NER layer for fuzzy entities (PERSON, ORG, LOC). The core engine fits in ~150 lines of PHP and 30+ unit tests describe every transition.

---

## Design rationale

Five non-negotiable choices that drove the API:

### 1. Italian-first. EU-second. World-third.

Every PII pipeline I have seen for Laravel either ignores Italian fiscal data or matches it with a bare regex that returns false positives on every retry CI run. `codice fiscale` validation is **mandatory** in this package: the 16-character pattern is pre-filtered, then the official odd/even checksum table from the 1976 Decreto Ministeriale is applied. `partita IVA` uses the Luhn-style P.IVA checksum. `IBAN` uses the country-length registry + mod-97 — for **every** ISO 13616 country, not just IT.

### 2. Deterministic regex + checksum, no LLM in the hot path

Every detector is a pure function of its input. No external HTTP call, no per-token cost, no rate limit. A 1 MB chat log redacts in milliseconds and the output is identical on every machine. v0.2 will add an **optional** NER layer behind a config switch; the v0.1 default never touches a network.

### 3. Strategy is a runtime decision, not a compile-time one

The same detected match can be **masked** (`[REDACTED]` for human-facing logs), **hashed** (`[hash:abc123ef]` for cross-record joins on pseudonymous data), **tokenised** (`[tok:email:abc123ef]` with a reversible salt-derived map for forensic recovery), or **dropped** (empty string for forwarding to lossy systems). Switching strategy is a one-line override on `Pii::redact($text, new HashStrategy(...))` — no detector code changes.

### 4. Detector overlap is resolved deterministically

When two detectors emit overlapping byte ranges (e.g. an email-shaped string that also matches a phone heuristic), the engine keeps the **earlier** match (lower offset) and drops the latecomer. The behaviour is documented, tested, and predictable — callers can audit it via `Pii::scan()`.

### 5. Standalone-agnostic — zero AskMyDocs symbols

`laravel-pii-redactor` is a **community** package. It is not coupled to AskMyDocs, the sister patent-box tracker, the eval-harness, the Regolo driver, or any other Padosoft project. An architecture test (`tests/Architecture/StandaloneAgnosticTest.php`) walks `src/` with `RecursiveDirectoryIterator` on every CI run and asserts the forbidden-substring list (KnowledgeDocument, KbSearchService, AskMyDocs, PatentBoxTracker, LaravelFlow, EvalHarness, Regolo, ...) never appears.

---

## Features at a glance

- **6 deterministic detectors out of the box**:
  - `codice_fiscale` — 16-char Italian fiscal code with full CIN checksum (Decreto Ministeriale 23/12/1976).
  - `p_iva` — 11-digit Italian VAT with Luhn-style checksum + zero-payload sentinel rejection.
  - `iban` — ISO 13616 IBAN for every registered country (~75) + mod-97 verification.
  - `email` — pragmatic RFC-5321 shape match.
  - `phone_it` — Italian mobile + landline (with optional `+39` / `0039` prefix).
  - `credit_card` — 13–19 digit PAN with Luhn validation.
- **4 pluggable redaction strategies**: `MaskStrategy`, `HashStrategy` (deterministic, salt-derived, namespaced per detector), `TokeniseStrategy` (reversible pseudonymisation with `detokenise()` + `dumpMap()` / `loadMap()` for cross-process recovery), `DropStrategy`.
- **Typed `DetectionReport`** — `total()`, `countsByDetector()`, `samplesByDetector(cap)`, `toArray()`. Stable JSON shape for downstream auditors.
- **`Pii::extend()` registry** for custom detectors (`custom_codice_iscrizione_albo`, project-specific account ids, etc.).
- **Artisan command** — `pii:scan path/to/file.txt --pretty` or `cat data | pii:scan --from=stdin`.
- **Standalone-agnostic** — zero coupling to AskMyDocs / sister packages, enforced by an architecture test.
- **PHP 8.3 / 8.4 / 8.5** × **Laravel 12 / 13** matrix. Pint + PHPStan level 6 + 30+ PHPUnit tests on every push.
- **Padosoft AI vibe-coding pack** (`.claude/`) — Claude Code skills (R36 review loop, R10–R37 rules) + agents (review pre-push) + commands (`/create-job`, `/domain-scaffold`).

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

`laravel-pii-redactor` is **not** a Presidio replacement for fuzzy entity recognition — Presidio's NER layer (PERSON, ORG, LOC) is genuinely more capable. v0.2 of this package will add an opt-in NER layer; v0.1 deliberately stays in regex + checksum territory because that is the layer where the existing Italian-aware options are weakest.

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
// "[hash:f72a1b09]"
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
php artisan pii:scan storage/exports/chat-log.txt --pretty
```

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

- `enabled` — master switch. When `false`, `Pii::redact()` returns input unchanged.
- `strategy` — `mask | hash | tokenise | drop`. Default mask token is `[REDACTED]`.
- `salt` — required for `hash` and `tokenise`. Treat like `APP_KEY`.
- `mask_token` — override the default mask string.
- `hash_hex_length` — between 4 and 64; default 8.
- `detectors` — whitelist of detector classes the ServiceProvider auto-registers. Removing an entry disables the detector. Custom detectors registered via `Pii::extend()` bypass this list.
- `audit_trail_enabled` — reserved for v0.2 (count-only events emitted to host listeners).

---

## Architecture

```
src/
 ├── PiiRedactorServiceProvider.php        config publish + DI bindings + commands
 ├── RedactorEngine.php                    core orchestrator (detectors + strategy + overlap)
 ├── Facades/Pii.php                       static-method surface for hosts
 ├── Console/PiiScanCommand.php            php artisan pii:scan
 ├── Detectors/
 │    ├── Detector.php                     interface
 │    ├── Detection.php                    immutable value object
 │    ├── CodiceFiscaleDetector.php
 │    ├── PartitaIvaDetector.php
 │    ├── IbanDetector.php
 │    ├── EmailDetector.php
 │    ├── PhoneItalianDetector.php
 │    └── CreditCardDetector.php
 ├── Strategies/
 │    ├── RedactionStrategy.php            interface
 │    ├── MaskStrategy.php
 │    ├── HashStrategy.php
 │    ├── TokeniseStrategy.php             reversible — detokenise() / dump+load map
 │    └── DropStrategy.php
 ├── Reports/
 │    └── DetectionReport.php              total() / counts() / samples() / toArray()
 └── Exceptions/
      ├── PiiRedactorException.php         non-final base
      ├── DetectorException.php
      └── StrategyException.php
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
vendor/bin/phpunit                 # Unit suite — default, ~30 tests, offline.
vendor/bin/phpunit --testsuite Architecture  # standalone-agnostic invariants.
```

The `Live` suite is **opt-in** and reserved for v0.2+ scenarios that require a real external dependency (NER service, LLM-backed detector). Each Live test self-skips unless `PII_REDACTOR_LIVE=1` is set. CI runs Unit + Architecture only.

---

## Roadmap

- **v0.1.0 (W7, this PR)** — 6 deterministic detectors, 4 strategies, `Pii::extend()`, `pii:scan` command, ~30 PHPUnit tests, standalone-agnostic invariant.
- **v0.2.0** — opt-in NER layer (PERSON / ORG / LOC) behind `PII_REDACTOR_NER_DRIVER`. `audit_trail_enabled` event emission. Persistent `TokenStore` (cache / DB / KMS) for cross-process detokenisation. Heuristic Italian street-address detector (`address_it`).
- **v0.3.0** — Italian custom-rule YAML for tenant-specific identifiers. Provider-agnostic NER drivers (HuggingFace + spaCy).
- **v1.0.0** — stable surface, semver guarantees, formal compatibility matrix vs Laravel + PHP LTS lines.

---

## Contributing

PRs welcome. Please read [CONTRIBUTING.md](CONTRIBUTING.md) — every PR follows the **R36 Copilot review + CI green loop** before merge. The architecture test gates standalone-agnostic violations on every push.

---

## Security

Found a vulnerability? Email security@padosoft.com — please do **not** open a public issue. See [SECURITY.md](SECURITY.md) for the full disclosure policy.

---

## License

Apache-2.0 — see [LICENSE](LICENSE).
