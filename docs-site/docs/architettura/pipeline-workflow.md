---
title: Pipeline Workflow
description: Scan and redact workflow.
---

# Pipeline Workflow

```mermaid
sequenceDiagram
  participant App as Laravel app
  participant Pii as Pii facade
  participant Engine as RedactorEngine
  participant Detector as Detectors and NER
  participant Strategy as Strategy
  App->>Pii: redact(text)
  Pii->>Engine: redact(text, optional strategy)
  Engine->>Detector: detect(text)
  Detector-->>Engine: Detection[]
  Engine->>Engine: resolve overlaps
  Engine->>Strategy: apply(value, detector)
  Strategy-->>Engine: replacement
  Engine-->>App: redacted text
```

::: steps
1. **Register detectors**
   The service provider registers configured international detectors, country-pack detectors, and custom YAML detectors.
2. **Collect detections**
   Each detector returns immutable `Detection` values.
3. **Resolve overlap**
   The earliest span wins, with longer match winning on equal offset.
4. **Replace right-to-left**
   Later spans are replaced first so earlier offsets remain valid.
5. **Emit audit event**
   If enabled and at least one match occurred, counts are dispatched without raw PII.
:::
