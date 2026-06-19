---
name: docmd-docs
description: Create and maintain the docmd static documentation site for laravel-pii-redactor.
---

# docmd Docs

Use this skill when changing `docs-site/`.

## Rules

- Keep documentation source in `docs-site/docs/`.
- Use Markdown only; do not add MDX, JSX, or raw HTML.
- Use docmd containers for rich content: `callout`, `tabs`, `steps`, `collapsible`, `grids`, `grid`, and `card`.
- Add every new page to `docs-site/docmd.config.json` navigation.
- Keep semantic search enabled with `Xenova/all-MiniLM-L6-v2`.
- Preserve `.docmd-search/config.json` with chunk size `512`, overlap `64`, incremental indexing, and `topK` `10`.
- Run `npm run check` and `npm run build` from `docs-site/` before committing docs changes.

## Deployment

Build output is `docs-site/_site/`. The production URL is `https://doc.laravel-pii-redactor.padosoft.com`.
