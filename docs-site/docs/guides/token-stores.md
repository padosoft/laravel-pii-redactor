---
title: Token Stores
description: Reversible token map storage.
---

# Token Stores

`TokeniseStrategy` writes a reversible map from `[tok:detector:id]` to the original value. That map is sensitive and must be protected like the source PII.

| Driver | Persistence | Typical use |
| --- | --- | --- |
| `memory` | Process-local | Tests, short scripts, previews |
| `database` | Eloquent table | Cross-worker detokenisation |
| `cache` | Laravel cache repository | Redis-backed operational stores |

::: steps
1. **Choose tokenise**
   Set `PII_REDACTOR_STRATEGY=tokenise`.
2. **Set a salt**
   Set `PII_REDACTOR_SALT` from secret storage.
3. **Choose a persistent store**
   Set `PII_REDACTOR_TOKEN_STORE=database` or `cache`.
4. **Publish migration if using database**
   Run `php artisan vendor:publish --tag=pii-redactor-migrations && php artisan migrate`.
:::
