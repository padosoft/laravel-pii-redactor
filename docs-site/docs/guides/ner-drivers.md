---
title: NER Drivers
description: Optional HuggingFace and spaCy named-entity recognition.
---

# NER Drivers

NER is disabled by default. When enabled, driver detections merge into the same overlap-resolution pipeline as deterministic detectors.

```php
'ner' => [
    'enabled' => env('PII_REDACTOR_NER_ENABLED', false),
    'driver' => env('PII_REDACTOR_NER_DRIVER', 'stub'),
],
```

::: callout warning "Failure-open behavior" icon:activity
The HuggingFace and spaCy drivers fail open on HTTP errors. This prevents redaction from blocking, but it also means deterministic detectors remain the only guaranteed layer.
:::

Use NER on batch paths or surfaces where latency and model availability are acceptable.
