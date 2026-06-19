---
title: Privacy
description: Privacy-first use of redaction outputs.
---

# Privacy

Redaction lowers exposure; it does not eliminate privacy obligations. Treat raw inputs, token maps, raw samples, debug logs, and model payloads as sensitive data.

::: callout warning "Operational rule" icon:lock
Persist detector counts and strategy names. Avoid persisting raw samples except in controlled forensic workflows with retention and access controls.
:::

Recommended defaults:

- keep NER disabled on synchronous request paths;
- use `mask` for logs and support previews;
- use `hash` only with a managed salt;
- use `tokenise` only when recovery is a real requirement;
- isolate the database token table when possible.
