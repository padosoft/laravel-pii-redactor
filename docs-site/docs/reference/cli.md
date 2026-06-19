---
title: CLI
description: Artisan CLI reference.
---

# CLI

```bash
php artisan pii:scan [path] [--from=stdin] [--pretty] [--show-samples]
```

| Option | Meaning |
| --- | --- |
| `path` | File to scan |
| `--from=stdin` | Read content from standard input |
| `--pretty` | Pretty-print JSON output |
| `--show-samples` | Include raw samples for controlled forensics |

The command scans only; it does not rewrite files.
