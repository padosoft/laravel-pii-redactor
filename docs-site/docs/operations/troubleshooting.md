---
title: Troubleshooting
description: Common failures and fixes.
---

# Troubleshooting

| Symptom | Likely cause | Fix |
| --- | --- | --- |
| `StrategyException` for hash or tokenise | Missing salt | Set `PII_REDACTOR_SALT` |
| Tokens cannot be resolved after deploy | Memory token store | Use database or cache token store |
| Germany or Spain identifiers are not detected | Pack not enabled | Add the pack FQCN to `packs` |
| NER returns no detections | Driver disabled or HTTP failure-open | Check `ner.enabled`, credentials, server URL, and logs |
| Build has no semantic manifest | Search dependency unavailable | Reinstall `docmd-search`, `@huggingface/transformers`, and `onnxruntime-node` |

::: collapsible "Debug checklist"
Run `php artisan config:clear`, inspect `config('pii-redactor')`, run a local `Pii::scan()` sample, then compare detector counts before changing strategy behavior.
:::
