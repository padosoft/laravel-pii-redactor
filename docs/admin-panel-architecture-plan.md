# Admin Panel Architecture Plan

This package remains the headless redaction core. A separate Laravel 13 package, `padosoft/laravel-pii-redactor-admin`, should provide the web UI with Vite, React, TypeScript, and Tailwind CSS.

## What the Core Already Provides

- `RedactorEngine` and `Pii` facade for `scan()`, `redact()`, detector registration, strategy override, and enabled-state checks.
- `DetectionReport` for totals, detector counts, deduplicated samples, and stable array output.
- `PiiRedactionPerformed` event with counts-only payload when audit trail is enabled.
- `TokenStore` drivers for in-memory, database, and cache-backed reversible token maps.
- `PackContract` and `DetectorPackRegistry` for country pack discovery.
- `YamlCustomRuleLoader`, `CustomRuleSet`, and `CustomRuleDetector` for tenant-specific YAML rules.
- `NerDriver` with stub, HuggingFace, and spaCy drivers behind opt-in config.
- `pii:scan` command for operator/CI scanning with masked samples by default.

## What Was Missing for an Admin Panel

- A safe status/snapshot API that does not expose salts, API keys, raw PII, or token originals.
- A public strategy factory so an admin API can run strategy-specific previews without duplicating service-provider internals.
- A safe formatter for `DetectionReport` that masks samples by default.
- A detokenise service that works through `TokenStore` even when the current default strategy is not `tokenise`.
- Custom-rule diagnostics that report broken YAML/config without mutating the engine at runtime.
- A precise contract for a separate Laravel 13 React/Tailwind UI package.

## Implemented Core APIs

- `Padosoft\PiiRedactor\Admin\RedactorAdminInspector`
  - `snapshot(): array`
  - Returns enabled state, default strategy, audit setting, token-store driver/class, NER status, detector list, pack list, and custom-rule count.
  - Never returns `salt`, API keys, raw sample values, redacted output, or token originals.
- `Padosoft\PiiRedactor\Strategies\RedactionStrategyFactory`
  - `names(): array`
  - `make(?string $name = null): RedactionStrategy`
  - Centralizes `mask`, `hash`, `tokenise`, and `drop` construction.
- `Padosoft\PiiRedactor\Reports\DetectionReportFormatter`
  - `safeArray(DetectionReport $report, bool $includeRawSamples = false): array`
  - Masks samples by default as `[email]`, `[iban]`, etc.
- `Padosoft\PiiRedactor\TokenStore\TokenResolutionService`
  - `resolveToken(string $token): ?string`
  - `detokeniseString(string $text): DetokeniseResult`
  - Fetches only referenced tokens; never calls `TokenStore::dump()`.
- `Padosoft\PiiRedactor\TokenStore\DetokeniseResult`
  - `output`, `tokenCount`, `resolvedCount`, `unresolvedTokens`, `toArray()`.
- `Padosoft\PiiRedactor\CustomRules\CustomRulePackInspector`
  - `configuredPacks(): array`
  - Reports name, path, exists/readable status, rule count, validity, and error.

## Core Task Gates

- Admin snapshot:
  - `RedactorAdminInspectorTest::test_snapshot_lists_enabled_strategy_detectors_and_packs`
  - `test_snapshot_never_contains_salt_or_api_keys`
  - `test_snapshot_reports_ner_configured_false_when_required_endpoint_or_key_is_missing`
- Strategy factory:
  - `RedactionStrategyFactoryTest::test_builds_all_supported_strategies`
  - `test_hash_and_tokenise_require_salt`
  - `test_tokenise_strategy_uses_configured_token_store`
  - `test_unknown_strategy_throws_strategy_exception`
- Safe report formatter:
  - `DetectionReportFormatterTest::test_masks_samples_by_default`
  - `test_can_include_raw_samples_when_explicitly_requested`
  - `test_preserves_total_and_counts_shape`
- Token resolution:
  - `TokenResolutionServiceTest::test_detokenises_string_without_current_tokenise_strategy`
  - `test_reports_unresolved_tokens`
  - `test_ignores_non_tokenise_placeholders`
  - `test_does_not_call_token_store_dump`
- Custom-rule diagnostics:
  - `CustomRulePackInspectorTest::test_reports_valid_pack_rule_count`
  - `test_reports_missing_file_without_throwing`
  - `test_reports_malformed_yaml_without_throwing`
  - `test_empty_config_returns_empty_list`

## Separate UI Package Plan

Package name: `padosoft/laravel-pii-redactor-admin`.

Target stack:

- Laravel `^13.0`
- PHP `^8.3`
- Vite
- React
- TypeScript
- Tailwind CSS
- Required dependency: `padosoft/laravel-pii-redactor`

Service provider:

- Publish config as `pii-redactor-admin.php`.
- Register routes only when `pii-redactor-admin.enabled` is true.
- Register web route prefix `/pii-redactor-admin`.
- Register API route prefix `/pii-redactor-admin/api`.
- Publish production assets to `public/vendor/pii-redactor-admin`.
- Publish migrations for admin audit tables.

Default config:

```php
return [
    'enabled' => env('PII_REDACTOR_ADMIN_ENABLED', false),
    'middleware' => ['web', 'auth'],
    'route_prefix' => 'pii-redactor-admin',
    'api_prefix' => 'pii-redactor-admin/api',
    'abilities' => [
        'view' => 'viewPiiRedactorAdmin',
        'detokenise' => 'detokenisePiiRedactor',
        'raw_samples' => 'viewPiiRedactorRawSamples',
    ],
];
```

Required API endpoints:

- `GET /api/status`
  - Uses `RedactorAdminInspector::snapshot()`.
  - Requires `viewPiiRedactorAdmin`.
- `POST /api/scan`
  - Input: `{ "text": "...", "include_raw_samples": false }`.
  - Uses `Pii::scan()` and `DetectionReportFormatter`.
  - `include_raw_samples=true` requires `viewPiiRedactorRawSamples`.
- `POST /api/redact`
  - Input: `{ "text": "...", "strategy": "mask|hash|tokenise|drop|null" }`.
  - Uses `RedactionStrategyFactory` for overrides.
  - Returns redacted output plus safe report.
- `POST /api/detokenise`
  - Input: `{ "text": "..." }`.
  - Uses `TokenResolutionService`.
  - Requires `detokenisePiiRedactor`.
  - Returns 422 when no `[tok:...]` token is present.
- `GET /api/custom-rules`
  - Uses `CustomRulePackInspector::configuredPacks()`.
- `GET /api/audit-events`
  - Returns paginated admin audit records.
- `GET /api/token-maps`
  - Read-only metadata only: `token`, `detector`, `created_at`.
  - Must never return `original`.

Required audit migration:

- Table `pii_redactor_admin_audit_events`
- Columns: `id`, `event_type`, `actor_id`, `ip`, `user_agent`, `strategy`, `total`, `counts_json`, `target_hash`, `status_code`, `created_at`.
- Do not store raw PII, redacted output, detokenised output, salts, API keys, or token originals.

React screens:

- Overview: enabled state, strategy, audit, NER, token store, detector count, pack count.
- Playground: scan/redact textarea, strategy selector, safe report output.
- Audit: paginated table with event type, strategy, detector/count filters, date filters.
- Token Map: read-only token metadata, detector filter, created date sorting.
- Detokenise: separate permission-gated screen with confirmation and audit.
- Detectors & Packs: list from status snapshot.
- Custom Rules: diagnostics from `CustomRulePackInspector`.

UI package PHPUnit gates:

- `ServiceProviderTest::test_registers_routes_config_migrations_and_assets_publish_group`
- `ServiceProviderTest::test_routes_are_not_registered_when_package_disabled`
- `AuthorizationTest::test_authenticated_user_without_gate_gets_403`
- `AuthorizationTest::test_view_gate_allows_dashboard`
- `AuthorizationTest::test_detokenise_requires_dedicated_gate`
- `StatusApiTest::test_returns_core_snapshot_without_secrets`
- `ScanApiTest::test_scan_masks_samples_by_default`
- `RedactApiTest::test_redact_supports_strategy_override`
- `DetokeniseApiTest::test_detokenise_returns_422_when_no_tokens`
- `TokenMapsApiTest::test_token_map_never_returns_original_column`
- `AuditListenerTest::test_persists_redaction_event_counts_only`
- `DetokeniseAuditTest::test_successful_and_forbidden_detokenise_attempts_are_audited`

Frontend gates:

- `npm run build`
- `npm run typecheck`
- API client tests for status, scan, redact, detokenise, audit events, and token-map metadata.
- No component renders token originals except the explicit detokenise result panel after a successful authorized request.

## Security Rules

- Default admin package state is disabled.
- Access requires host-defined Laravel Gates.
- Detokenise requires a dedicated ability and audit row.
- Raw scan samples require a dedicated ability.
- Token-map listing never exposes the `original` column.
- The core package remains safe for non-admin installs and does not ship UI assets.
