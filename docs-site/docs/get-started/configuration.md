---
title: Configuration
description: Runtime configuration overview.
---

# Configuration

The published config lives at `config/pii-redactor.php`. Defaults are deliberately conservative: redaction is enabled, the strategy is `mask`, NER is disabled, the token store is in memory, and `ItalyPack` is loaded.

::: callout info "Minimal production review" icon:list-check
Before production, review strategy, salt handling, token store persistence, enabled country packs, custom-rule loading, and audit event consumers.
:::

```php
'strategy' => env('PII_REDACTOR_STRATEGY', 'mask'),
'salt' => env('PII_REDACTOR_SALT', ''),
'token_store' => [
    'driver' => env('PII_REDACTOR_TOKEN_STORE', 'memory'),
],
'packs' => [
    \Padosoft\PiiRedactor\Packs\Italy\ItalyPack::class,
],
```

## Recommended decisions

| Decision | Default | Production note |
| --- | --- | --- |
| `enabled` | `true` | Keep enabled in every environment that emits logs or support exports |
| `strategy` | `mask` | Use `hash` for joins and `tokenise` only when recovery is required |
| `salt` | empty | Required for `hash` and `tokenise`; treat like `APP_KEY` |
| `audit_trail.enabled` | `false` | Emits counts only, never raw PII |
| `ner.enabled` | `false` | Enable only with a latency and failure-open plan |
