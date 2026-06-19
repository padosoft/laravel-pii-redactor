# Rule: docmd Docs Sync

When package behavior, public APIs, configuration keys, commands, events, detector packs, strategies, token stores, or NER drivers change, update the docmd site in `docs-site/` in the same change.

Required checks for docs changes:

- `cd docs-site && npm run check`
- `cd docs-site && npm run build`

The docs must remain Markdown-only and every page must be present in `docs-site/docmd.config.json` navigation.
