---
title: Modello Dati e Contratto
description: Core value objects and storage contracts.
---

# Modello Dati e Contratto

## Detection

`Detection` is the span contract shared by first-party detectors, custom detectors, and NER drivers.

| Field | Meaning |
| --- | --- |
| `detector` | Canonical detector id such as `email` or `codice_fiscale` |
| `value` | Original matched text |
| `offset` | Byte offset in the input string |
| `length` | Byte length of the match |

## Token map

Database token storage uses the shipped `pii_token_maps` migration. Token values are stable only for a given salt, detector, strategy length, and original value.

::: callout danger "Storage classification" icon:database-zap
The token map contains recoverable PII. Encrypt backups, restrict access, and avoid broad `dump()` use outside controlled maintenance paths.
:::
