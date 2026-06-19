---
title: Scan CLI
description: Artisan command for local file and stdin scanning.
---

# Scan CLI

The package exposes `php artisan pii:scan` for operator-driven inspection.

::: tabs
== tab "File"
```bash
php artisan pii:scan storage/logs/laravel.log --pretty
```
== tab "stdin"
```bash
cat sample.txt | php artisan pii:scan --from=stdin
```
== tab "Raw samples"
```bash
php artisan pii:scan sample.txt --show-samples
```
:::

By default, samples are masked. Use raw samples only during controlled local forensics.
