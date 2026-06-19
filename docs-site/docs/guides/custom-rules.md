---
title: Custom Rules
description: YAML custom detector packs.
---

# Custom Rules

Custom-rule packs let hosts register tenant-specific identifiers without shipping PHP detector classes for each tenant.

```yaml
rules:
  - name: professional_registry
    pattern: '\bISCR-[A-Z]{2}-\d{6}\b'
    flags: u
```

```php
'custom_rules' => [
    'auto_register' => true,
    'packs' => [
        ['name' => 'custom_it_albo', 'path' => storage_path('app/pii-rules/it-albo.yaml')],
    ],
],
```

::: callout warning "Regex quality" icon:shield-alert
Custom regexes bypass first-party checksum validation. Keep them narrow, Unicode-aware, and covered by fixtures before enabling auto-registration.
:::
