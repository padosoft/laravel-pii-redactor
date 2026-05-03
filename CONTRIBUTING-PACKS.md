# Contributing a country pack

Thank you for considering a country-pack contribution to
`padosoft/laravel-pii-redactor`. Country packs are how the package grows
beyond its Italian-first roots into a true EU-grade GDPR / AI-Act-ready
PII redaction library — without bloating the core or sacrificing the
checksum-grade rigor that makes the existing detectors trustworthy.

This guide is opinionated. Read it end-to-end before starting a PR.

---

## 1. What a country pack is

A country pack is a single PHP class under
`src/Packs/<Country>/<Country>Pack.php` that:

- Implements `Padosoft\PiiRedactor\Packs\PackContract`.
- Aggregates jurisdiction-specific `Detector` instances (national IDs
  with structural validation, country phone heuristics, address
  patterns).
- Is fully **standalone** — no imports from sister Padosoft packages
  and no imports from any other country pack.

The reference implementation is
`Padosoft\PiiRedactor\Packs\Italy\ItalyPack` (shipped since v1.0). It
aggregates the four Italian detectors (`CodiceFiscaleDetector`,
`PartitaIvaDetector`, `PhoneItalianDetector`,
`AddressItalianDetector`) and is the model every new pack should
mirror.

Community priority (in rough order): Germany, Spain, France,
Netherlands, Belgium, Austria, Portugal, Poland, Ireland.

---

## 2. When to write a pack vs a YAML custom-rule pack

The package supports two extension surfaces. Pick the right one before
opening a PR.

| Need                                                                                       | Use                                                                              |
|--------------------------------------------------------------------------------------------|----------------------------------------------------------------------------------|
| Identifier with structural validation (checksum: CIN / Luhn / mod-97 / mod-11)             | **Country pack (PHP)** — checksums need real code                                |
| National phone / address heuristics with country-specific vocabulary                       | **Country pack (PHP)** — bigger surface, easier to test                          |
| Tenant-specific identifier (employee ID, project tracker, internal account code)           | **YAML custom-rule pack** (`Pii::extend()`) — pure regex, no PR required         |
| Multi-country, internationally specified (Email, IBAN, CreditCard)                         | **Don't pack — already in `src/Detectors/` top-level**                           |

A rule of thumb: if the only thing your detector does is match a
regex, write a YAML custom-rule pack (your host application owns it,
no upstream PR needed). If you need to validate a checksum, normalise
input characters, or score regex confidence dynamically, you need a
PHP pack.

---

## 3. Pack structure on disk

```
src/Packs/<Country>/
├── <Country>Pack.php                    implements PackContract
├── Detectors/
│   ├── <NationalIdDetector>.php
│   ├── <VATDetector>.php
│   ├── Phone<Country>Detector.php
│   └── Address<Country>Detector.php
└── (optional) Checksums/
    └── <CountryChecksum>.php            extracted-out checksum class

tests/Unit/Packs/<Country>/
├── <Country>PackTest.php
└── Detectors/
    ├── <NationalIdDetector>Test.php     ≥ 10 valid + 5 invalid + 5 wrong-format
    └── ...

tests/Architecture/<Country>PackIsolationTest.php
```

### Naming conventions

- Folder name: ISO 3166-1 alpha-2 country in PascalCase as
  `Country` — `Germany`, `Spain`, `France`, `Iceland`, `Netherlands`.
  Use the long-form English name, not the alpha-2 code, so the FQCN
  reads naturally (`Padosoft\PiiRedactor\Packs\Germany\GermanyPack`).
- Pack class: `<Country>Pack` — `GermanyPack`, `SpainPack`,
  `IcelandPack`.
- Detector classes: follow existing project naming
  (`<Concept><CountryAdjective>Detector` like
  `PhoneItalianDetector`). Examples: `PhoneGermanDetector`,
  `AddressSpanishDetector`, `SteuerIdDetector`,
  `DniSpanishDetector`.
- Pack `name()` return value: kebab-case lowercase, no whitespace —
  `'germany'`, `'spain'`, `'iceland'`.
- Pack `countryCode()` return value: 2-char uppercase ISO 3166-1
  alpha-2 — `'DE'`, `'ES'`, `'IS'`.

---

## 4. The `PackContract` interface — what to implement

```php
namespace Padosoft\PiiRedactor\Packs;

interface PackContract
{
    public function name(): string;
    public function countryCode(): string;
    public function description(): string;

    /** @return list<\Padosoft\PiiRedactor\Detectors\Detector> */
    public function detectors(): array;
}
```

Method-by-method:

- **`name(): string`** — kebab-case lowercase, no whitespace. Used in
  logs, audit trails, and config error messages. Example: `'germany'`,
  `'spain'`, `'iceland'`.
- **`countryCode(): string`** — 2-char uppercase ISO 3166-1 alpha-2.
  Example: `'DE'`, `'ES'`, `'IS'`. Region packs covering multiple
  countries MAY return `''` and document the convention in their
  docblock.
- **`description(): string`** — human-readable string surfaced in
  admin UIs and debug listings. Mention the identifiers covered AND
  any unique calling cards (checksum algorithm names, CIN-style
  variations) so operators can read the listing without opening
  source.
- **`detectors(): array`** — `list<Detector>`. **Return a fresh list
  on every call** (no shared mutation across callers). Detector
  instances themselves can be memoised internally if construction is
  expensive.

---

## 5. Checksum implementation discipline

For any detector that validates a national identifier with a
checksum (codice fiscale CIN, P.IVA Luhn-IT, German Steuer-ID,
Spanish DNI letter, French INSEE, Dutch BSN elfproef, etc.):

### Cite the official source

The detector class docblock MUST cite the regulating authority's
spec. Examples:

- Italian `CodiceFiscaleDetector` — Decreto Ministeriale 23/12/1976
  (Ministero delle Finanze, allegato al D.M.).
- Italian `PartitaIvaDetector` — D.P.R. 26 ottobre 1972, n. 633,
  art. 35-bis (P.IVA structure) + Luhn-style IT checksum spec.
- German `SteuerIdDetector` — Bundeszentralamt für Steuern §139b AO
  (Abgabenordnung), national tax identifier.
- Spanish `DniSpanishDetector` — Real Decreto 1553/2005 (DNI letter
  algorithm: position = number mod 23, look up in `TRWAGMYFPDXBNJZSQVHLCKE`).
- French `InseeDetector` — INSEE specification, NIR / numéro de
  sécurité sociale, mod-97 control key over 13-digit prefix.
- Dutch `BsnDetector` — Burgerservicenummer elfproef
  (`9 * d1 + 8 * d2 + 7 * d3 + 6 * d4 + 5 * d5 + 4 * d6 + 3 * d7 + 2 * d8 - 1 * d9 ≡ 0 (mod 11)`).

### Include a citation comment with the algorithm steps

Future readers should be able to verify the implementation matches
the spec without leaving the source file. Example shape (pseudo):

```php
/**
 * Validates the German Steuer-ID checksum per §139b AO.
 *
 * Algorithm (Bundeszentralamt für Steuern, Verfahrensbeschreibung
 * Steuerliche Identifikationsnummer, §2):
 *
 *   1. Multiply each of the first 10 digits with running products
 *      modulo 11 then modulo 10 (with 0 → 10 substitution).
 *   2. The 11th digit is the check digit; compute `expected = 11 - product`,
 *      with 11 mapped to 0.
 *   3. Reject IDs where any digit appears more than 3 times or where
 *      a single digit appears exactly twice (rule §139b AO Abs. 4).
 *
 * @see https://www.bzst.de/...
 */
```

The docblock + citation comment is a hard review gate. PRs missing
the citation will be asked to add one before merge — checksum-grade
detectors that future maintainers cannot verify against a spec are a
maintenance liability we will not accept.

### Test fixtures

Each detector ships with at minimum:

- **10 valid synthetic-but-checksum-correct values** — generate fresh
  inputs that satisfy the algorithm, do NOT use real-world IDs of
  real persons. Checksum-correct fictional inputs are easy to compute
  with the algorithm itself in reverse (or mirror the canonical
  examples published by the regulating authority where the spec
  includes them).
- **5 invalid-checksum values** — same shape, deliberately wrong
  checksum digit.
- **5 wrong-format values** — wrong length, wrong character set,
  off-by-one separator, etc.

Fixture storage: either inline as a PHPUnit `dataProvider` method,
or under `tests/fixtures/packs/<country>/<id>-fixtures.json` with
`{ "valid": [...], "invalid_checksum": [...], "wrong_format": [...] }`.
Pick whichever is more readable — avoid scattering fixtures across
half-a-dozen formats per pack.

### Synthetic-only rule

DO NOT contribute test fixtures sourced from real identification
documents (real codice fiscale of a real person, real BSN of a real
Dutch citizen, etc.). Use:

- The well-documented synthetic examples from the spec itself (most
  EU regulators publish reference inputs).
- Fresh checksum-correct values you generate for fictional inputs.
- Names + dates of birth of fictional characters when the checksum
  algorithm requires them as inputs (Italian codice fiscale, Spanish
  DNI letter).

A PR that ships real-person IDs as fixtures will be rejected on the
spot regardless of how clean the code is.

---

## 6. Standalone-agnostic invariant (R37)

Every pack MUST be standalone-agnostic. Zero references to:

- AskMyDocs internals — `lopadova/askmydocs`, `KnowledgeDocument`,
  `KbSearchService`, anything under `App\` namespace.
- Other Padosoft sister packages — `laravel-flow`, `eval-harness`,
  `laravel-ai-regolo`, `patent-box-tracker`, `askmydocs-pro`. Pull
  request reviewers run a grep before merge.
- Other country packs — `GermanyPack` MUST NOT import from
  `Padosoft\PiiRedactor\Packs\Spain\*`, and vice versa. If two
  packs share helper code (a generic mod-11 checksum routine, a
  shared phone-prefix lookup), promote the helper to
  `src/Support/` first in a separate PR.

The pack-isolation architecture test gates this on every CI run. See
`tests/Architecture/StandaloneAgnosticTest.php` for the existing
pattern. Add a new
`tests/Architecture/<Country>PackIsolationTest.php` per pack that
asserts no symbol under `Padosoft\PiiRedactor\Packs\<Country>\` imports
from `Padosoft\PiiRedactor\Packs\<Other>\` for any other listed
pack.

Why this matters: the standalone-agnostic invariant is what lets a
GDPR-conscious host enable, say, only `GermanyPack` without
accidentally pulling in Italian phone heuristics that would skew
detection statistics in a German-only deployment. Cross-pack imports
violate that boundary.

---

## 7. Test parity expectations

A pack PR ships with test coverage at parity with `ItalyPack`:

- **Pack class test** — instantiation, `name()` returns expected
  string, `countryCode()` returns expected ISO code, `description()`
  is non-empty, `detectors()` returns the expected count + types,
  `detectors()` returns a fresh list each call (no mutation across
  invocations).
- **One unit test class per detector** — at minimum 10 valid + 5
  invalid + 5 wrong-format fixtures (see §5).
- **Architecture test (standalone-agnostic + cross-pack isolation)**
  under `tests/Architecture/<Country>PackIsolationTest.php`.
- **Live test OPTIONAL** — only if the pack integrates an external
  service (NER driver, KMS lookup, country-specific OAuth scope).
  Default to no Live tests; PHP-only checksums don't need them.

Coverage benchmark: the existing Italian pack has ~95% line coverage
on its detectors (`CodiceFiscaleDetector`, `PartitaIvaDetector`,
`PhoneItalianDetector`, `AddressItalianDetector`). New packs are
expected to land at or above that bar.

---

## 8. PR checklist

Tick every box before requesting review:

```
[ ] Pack class implements `PackContract` and is registered nowhere by default.
[ ] Each detector has a docblock citing its checksum spec source.
[ ] Test fixtures: ≥ 10 valid + 5 invalid + 5 wrong-format per detector.
[ ] Architecture test (standalone-agnostic + cross-pack isolation) added under tests/Architecture/.
[ ] Pint --test green.
[ ] PHPStan level 6 green (--memory-limit=512M).
[ ] PHPUnit Unit + Architecture suites green on the full matrix (PHP 8.3 / 8.4 / 8.5, Laravel 12 / 13).
[ ] README community-contribution table updated to mention the new pack.
[ ] CHANGELOG updated under [Unreleased] / Added.
```

The pack is intentionally NOT registered in default config — hosts
opt in by appending the FQCN to `config('pii-redactor.packs')`.
This keeps the package's default surface narrow and predictable, and
lets multi-tenant hosts enable different packs per tenant.

---

## 9. Review SLA

Maintainers commit to a first-pass review within **7 days** of PR
open (modulo holidays). The acceptance gate is:

- Checksum-spec correctness (the algorithm matches the citation in
  the docblock).
- Test coverage parity (the fixture counts match §7).
- Standalone-agnostic invariant (the architecture test passes).
- Pint / PHPStan / PHPUnit green on the full matrix.

Style is automatic — Pint enforces it, you don't need to debate
brace placement. Subjective code-style nits are not blocking;
checksum correctness is.

---

## 10. License

The package is Apache-2.0. Contributing implies your pack is also
Apache-2.0 (or a compatible permissive license). Specifically:

- Do NOT contribute checksum implementations sourced from
  incompatible-license codebases (GPL-3.0 reference implementations,
  proprietary spec PDFs with restrictive reuse clauses).
- DO cite the algorithm source in the docblock (the spec itself is
  fact, not copyrightable; your implementation is original work
  under Apache-2.0).
- Test fixtures you contribute are also Apache-2.0; do not include
  fixtures from incompatible-license corpora.

If in doubt about a checksum source's license, ask in the PR
description before writing the code. Maintainers are happy to confirm
upstream-license compatibility ahead of time.

---

Thanks for contributing. The goal is a community-grade EU PII
toolkit; every pack you ship moves the needle for everyone.
