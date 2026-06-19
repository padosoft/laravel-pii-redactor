---
title: Config
description: Configuration key reference.
---

# Config

| Key | Type | Purpose |
| --- | --- | --- |
| `enabled` | bool | Master redaction switch |
| `strategy` | string | `mask`, `hash`, `tokenise`, or `drop` |
| `salt` | string | Secret for hash and tokenise |
| `mask_token` | string | Replacement for mask strategy |
| `detectors` | array | International detector class list |
| `packs` | array | Country pack class list |
| `audit_trail.enabled` | bool | Count-only event dispatch |
| `ner.enabled` | bool | Optional NER layer |
| `token_store.driver` | string | `memory`, `database`, or `cache` |
| `custom_rules.auto_register` | bool | YAML pack registration at boot |

Use the published `config/pii-redactor.php` as the source of truth for nested driver options.
