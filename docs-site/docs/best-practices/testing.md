---
title: Testing
description: Test strategy for package users and contributors.
---

# Testing

First-party tests cover unit, architecture, robustness, and live-driver suites. Host applications should add fixtures that mirror their own text sources.

::: tabs
== tab "Package"
```bash
vendor/bin/phpunit
vendor/bin/phpunit --testsuite Architecture
vendor/bin/phpunit --exclude-group perf
```
== tab "Live"
```bash
PII_REDACTOR_LIVE=1 vendor/bin/phpunit --testsuite Live
```
== tab "Docs"
```bash
cd docs-site
npm run check
npm run build
```
:::

Test valid, invalid, wrong-format, Unicode-boundary, and overlapping examples for every detector you add.
