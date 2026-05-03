# Migrating from v0.x to v1.0

## Overview

`padosoft/laravel-pii-redactor` v1.0 is a **drop-in upgrade** from any
v0.x release. There are **no breaking changes**:

- Existing imports continue to resolve (`Padosoft\PiiRedactor\Detectors\CodiceFiscaleDetector`
  et al. stay where they are).
- Existing config keys (`pii-redactor.detectors`, `pii-redactor.strategies`,
  `pii-redactor.token_store`, `pii-redactor.ner`, `pii-redactor.custom_rules`,
  audit/event keys) all continue to work unchanged.
- Existing env vars (`PII_REDACTOR_*`) all continue to work unchanged.
- Existing migrations (`pii_token_maps`, `pii_audit_redactions`) ship in v1.0
  with identical schema.

Upgrade by bumping the constraint in your host's `composer.json`:

```diff
  "require": {
-     "padosoft/laravel-pii-redactor": "^0.3"
+     "padosoft/laravel-pii-redactor": "^1.0"
  }
```

Run `composer update padosoft/laravel-pii-redactor`. No code changes
in your host application are required to keep v0.x behaviour.

---

## What's new architecturally

v1.0 introduces three additive surfaces that pave the way for
community-contributed country packs:

- **`Padosoft\PiiRedactor\Packs\PackContract`** — interface for
  jurisdiction-specific detector bundles (Italy, Germany, Spain,
  France, Netherlands, …). See `CONTRIBUTING-PACKS.md` for the
  community contribution workflow.
- **`Padosoft\PiiRedactor\Packs\Italy\ItalyPack`** — first
  reference pack, aggregating the four Italian detectors
  (`CodiceFiscaleDetector`, `PartitaIvaDetector`,
  `PhoneItalianDetector`, `AddressItalianDetector`).
- **`Padosoft\PiiRedactor\Packs\DetectorPackRegistry`** — service
  provider walks `config('pii-redactor.packs')` at boot,
  instantiates each pack class, calls `detectors()`, and registers
  each returned detector with the engine. Hosts disable a pack by
  removing it from the config list.
- **Custom-rule pack auto-register loop** — the SP now iterates
  `config('pii-redactor.custom_rules.packs')` (closing the v0.3
  deferred TODO). YAML rule packs configured under
  `custom_rules.packs[]` are loaded automatically at boot when
  `custom_rules.auto_register` is `true`.
- **Stable surface lock** — every public method of the package is
  now governed by SemVer. Breaking changes only land in v2.0+.

---

## What changed under the hood (transparent to consumers)

These are internals — your host application does not need to know,
but if you read source you'll notice:

- The four Italian detectors are now registered via `ItalyPack` by
  default (was: flat `pii-redactor.detectors` list expanded
  inline by the SP). The flat list still works; the two surfaces
  coexist. If both surfaces register the same detector, the engine
  deduplicates by detector `name()`.
- `config('pii-redactor.packs')` is the new **preferred** config
  key for jurisdictional bundles. The legacy
  `config('pii-redactor.detectors')` flat list remains valid for
  individual detectors that don't belong to a pack (Email, IBAN,
  CreditCard).
- `composer.json` `minimum-stability` flipped from `dev` to
  `stable` — production-grade composer flag now matches the
  package's actual maturity. Hosts requiring v1.0 from a stable
  composer profile (the common case) no longer need to set
  `"minimum-stability": "dev"` themselves.

---

## What hosts MAY want to do (no urgency — purely additive)

If you want to align your host config with the new pack-level
surface (recommended, but not required), append the following to
your `config/pii-redactor.php`:

```diff
  // config/pii-redactor.php

+ // v1.0+: prefer pack-level config for jurisdictional bundles.
+ // The flat 'detectors' list still works; this is the new
+ // preferred surface for country packs.
+ 'packs' => [
+     \Padosoft\PiiRedactor\Packs\Italy\ItalyPack::class,
+ ],

  // OPTIONAL: enable custom_rules auto-register if you have YAML packs.
  'custom_rules' => [
+     'auto_register' => true,
+     'packs' => [
+         [
+             'name' => 'custom_it_albo',
+             'path' => storage_path('app/pii-rules/it-albo.yaml'),
+         ],
+     ],
  ],
```

Future community packs (`GermanyPack`, `SpainPack`, `FrancePack`,
…) will publish guidance on appending themselves to the `packs`
array as they ship in v1.1+ minor releases.

---

## What hosts MUST NOT do

- **Don't move the existing detectors out of `src/Detectors/`.**
  The pack architecture is additive; the existing classes stay
  where they are. Future imports of `CodiceFiscaleDetector`,
  `PartitaIvaDetector`, `PhoneItalianDetector`,
  `AddressItalianDetector` continue to work from the top-level
  `Padosoft\PiiRedactor\Detectors\` namespace.
- **Don't modify pack-class instances directly.** Treat packs as
  immutable configuration. If you need a different detector
  set, write your own pack class that implements `PackContract`
  and list its FQCN in `config('pii-redactor.packs')`.
- **Don't rely on a specific detector registration order across
  the flat list and the packs list.** The engine deduplicates by
  detector `name()` and applies its own overlap-resolution rules
  (left-most + longer-on-tie). If you need a specific tie-break,
  pin it explicitly in your custom pack.

---

## Compatibility matrix (formal v1.0 lock)

| Pkg version | PHP supported     | Laravel supported |
|-------------|-------------------|-------------------|
| v1.0.x      | 8.3 / 8.4 / 8.5   | 12.x / 13.x       |
| v0.3.x      | 8.3 / 8.4 / 8.5   | 12.x / 13.x       |
| v0.2.x      | 8.3 / 8.4 / 8.5   | 12.x / 13.x       |

Future minors (v1.1, v1.2, …) MAY ADD support for newer PHP /
Laravel lines as they release. They will NOT drop support for the
listed versions during the v1.x lifetime — that's the SemVer
contract for the v1.x major.

---

## Upgrade path summary

| From   | To    | Steps                                                                 |
|--------|-------|-----------------------------------------------------------------------|
| v0.1.x | v1.0  | Bump composer constraint to `^1.0`, run `composer update`. Done.      |
| v0.2.x | v1.0  | Bump composer constraint to `^1.0`, run `composer update`. Done.      |
| v0.3.x | v1.0  | Bump composer constraint to `^1.0`, run `composer update`. Done.      |

Optional follow-up after upgrade (recommended for new deployments,
optional for existing ones): adopt the new
`config('pii-redactor.packs')` surface as shown above so your config
matches the v1.x pattern that future community packs will plug
into.

If you encounter unexpected behaviour after upgrading, please open
an issue with a minimal reproducer — v1.0 is intended to be a
zero-friction upgrade and any regression is a high-priority bug.
