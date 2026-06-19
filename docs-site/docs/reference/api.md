---
title: API
description: Facade and core PHP API reference.
---

# API

## Facade

```php
Pii::redact(string $text, ?RedactionStrategy $override = null): string
Pii::scan(string $text): DetectionReport
Pii::extend(string $alias, Detector $detector): void
```

## DetectionReport

```php
$report->total();
$report->countsByDetector();
$report->samplesByDetector(3);
$report->toArray();
```

## Strategy factory

```php
$factory = app(\Padosoft\PiiRedactor\Strategies\RedactionStrategyFactory::class);
$strategy = $factory->make('mask');
$names = $factory->names();
```
