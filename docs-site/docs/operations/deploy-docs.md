---
title: Deploy Docs
description: Build and deploy the static docmd site.
---

# Deploy Docs

The documentation site is static and builds into `_site`.

```bash
cd docs-site
npm install
npm run check
npm run build
```

Deploy `_site` to `https://doc.laravel-pii-redactor.padosoft.com`.

::: callout info "Cloudflare project" icon:cloud
The Cloudflare project name is `laravel-pii-redactor`. Use `_site` as the build output directory.
:::
