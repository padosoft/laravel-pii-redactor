---
title: Detector Authoring
description: How to write custom detectors and packs.
---

# Detector Authoring

A detector should be narrow, deterministic, Unicode-aware, and covered by fixtures. Checksum identifiers should validate checksums before returning detections.

```php
final class ExampleDetector implements Detector
{
    public function name(): string
    {
        return 'example';
    }

    public function detect(string $text): array
    {
        return [];
    }
}
```

::: steps
1. **Name the detector**
   Choose a stable lowercase id.
2. **Match candidates**
   Use bounded patterns and Unicode flags.
3. **Validate**
   Reject checksum failures and sentinel values.
4. **Return spans**
   Preserve original offset and length.
5. **Test overlap**
   Add tests against adjacent, nested, and multi-byte text.
:::
