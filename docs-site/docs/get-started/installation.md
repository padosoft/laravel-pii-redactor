---
title: Installation
description: Composer installation and Laravel publishing commands.
---

# Installation

The package supports PHP `^8.3` and Laravel `^12.0|^13.0`.

```bash
composer require padosoft/laravel-pii-redactor
```

Laravel package discovery registers `PiiRedactorServiceProvider` and the `Pii` facade alias from `composer.json`.

::: tabs
== tab "Config"
```bash
php artisan vendor:publish --tag=pii-redactor-config
```
== tab "Migrations"
```bash
php artisan vendor:publish --tag=pii-redactor-migrations
php artisan migrate
```
== tab "CLI"
```bash
php artisan pii:scan storage/logs/laravel.log --pretty
```
:::

## Optional runtime dependencies

NER drivers are opt-in. The deterministic path does not require HuggingFace, spaCy, external HTTP, or an LLM.

| Feature | Requirement |
| --- | --- |
| Database token store | Published migration and migrated `pii_token_maps` table |
| Cache token store | Laravel cache repository such as Redis, Memcached, DynamoDB, or array |
| HuggingFace NER | `PII_REDACTOR_HUGGINGFACE_API_KEY` |
| spaCy NER | A server that returns `Doc.to_json()`-style entity payloads |
