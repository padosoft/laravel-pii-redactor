---
title: Gotchas and Limits
description: Known limitations and warnings.
---

# Gotchas and Limits

::: callout warning "No detector catches everything" icon:triangle-alert
Deterministic redaction is excellent for structured identifiers. It is not a universal privacy classifier. Free-form names, ambiguous addresses, and context-dependent secrets may need custom rules, NER, or upstream data minimisation.
:::

Important limits:

- regex heuristics can create false positives on address and phone-like text;
- NER can miss entities and is allowed to fail open;
- `drop` can alter sentence meaning;
- token maps are recoverable PII;
- salt rotation invalidates old hash and token joins;
- overlap resolution intentionally discards later overlapping detections.
