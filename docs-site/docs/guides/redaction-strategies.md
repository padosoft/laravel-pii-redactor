---
title: Redaction Strategies
description: Mask, hash, tokenise, and drop strategies.
---

# Redaction Strategies

Strategies receive a detected value and detector name. Detectors decide what the sensitive span is; strategies decide what replaces it.

::: tabs
== tab "mask"
```php
Pii::redact($text);
// [REDACTED]
```
== tab "hash"
```php
$strategy = app(\Padosoft\PiiRedactor\Strategies\RedactionStrategyFactory::class)->make('hash');
Pii::redact($text, $strategy);
// [hash:...]
```
== tab "tokenise"
```php
$strategy = app(\Padosoft\PiiRedactor\Strategies\RedactionStrategyFactory::class)->make('tokenise');
Pii::redact($text, $strategy);
// [tok:email:...]
```
== tab "drop"
```php
$strategy = app(\Padosoft\PiiRedactor\Strategies\RedactionStrategyFactory::class)->make('drop');
Pii::redact($text, $strategy);
// sensitive span removed
```
:::

## Strategy choice

| Strategy | Use when | Risk |
| --- | --- | --- |
| `mask` | Human-facing logs and support views | Loses joinability |
| `hash` | Pseudonymous joins across records | Salt rotation breaks joins |
| `tokenise` | Forensic recovery is required | Token store becomes sensitive infrastructure |
| `drop` | Downstream cannot receive placeholders | Text may become harder to read |

The birthday-bound intuition for truncated identifiers is approximately:

$$p \approx \frac{n^2}{2m}$$

where `n` is the number of unique values and `m` is the hash namespace size.
