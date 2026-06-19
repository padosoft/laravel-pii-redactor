---
title: ADR
description: Architecture decision records.
---

# ADR

::: collapsible "ADR-001: Deterministic layer before NER"
Status: accepted.

The package runs deterministic detectors by default and keeps NER opt-in. This gives predictable latency, no mandatory external service, and stable audit behavior.
:::

::: collapsible "ADR-002: Country packs for national identifiers"
Status: accepted.

National identifiers have jurisdiction-specific formats and checksums. Packs keep those rules isolated and let hosts enable only the countries they process.
:::

::: collapsible "ADR-003: Strategy interface for replacements"
Status: accepted.

Detection and replacement are separate contracts. This keeps detectors reusable across masking, hashing, tokenisation, and dropping.
:::

::: collapsible "ADR-004: Audit event carries counts only"
Status: accepted.

Audit payloads must never include raw PII or redacted output. Counts by detector are enough for observability without expanding the sensitive data surface.
:::
