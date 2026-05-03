<?php

declare(strict_types=1);

use Padosoft\PiiRedactor\Detectors\AddressItalianDetector;
use Padosoft\PiiRedactor\Detectors\CodiceFiscaleDetector;
use Padosoft\PiiRedactor\Detectors\CreditCardDetector;
use Padosoft\PiiRedactor\Detectors\EmailDetector;
use Padosoft\PiiRedactor\Detectors\IbanDetector;
use Padosoft\PiiRedactor\Detectors\PartitaIvaDetector;
use Padosoft\PiiRedactor\Detectors\PhoneItalianDetector;
use Padosoft\PiiRedactor\Ner\HuggingFaceNerDriver;
use Padosoft\PiiRedactor\Ner\SpaCyNerDriver;
use Padosoft\PiiRedactor\Ner\StubNerDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | When false the facade still resolves but redact() returns the input
    | unchanged. Useful for staging environments that need to disable
    | redaction temporarily without code changes.
    |
    */
    'enabled' => env('PII_REDACTOR_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Default redaction strategy
    |--------------------------------------------------------------------------
    |
    | One of: 'mask', 'hash', 'tokenise', 'drop'. The default strategy is
    | applied when callers do not pass an override to Pii::redact().
    |
    */
    'strategy' => env('PII_REDACTOR_STRATEGY', 'mask'),

    /*
    |--------------------------------------------------------------------------
    | Pseudonymisation salt
    |--------------------------------------------------------------------------
    |
    | REQUIRED when strategy is 'hash' or 'tokenise'. A non-empty value
    | seeds the deterministic pseudonymisation so cross-record joins on
    | redacted output remain possible without leaking the original PII.
    | Treat this value like an APP_KEY: rotate it carefully — every previous
    | hash / token will become unjoinable after rotation.
    |
    */
    'salt' => env('PII_REDACTOR_SALT', ''),

    /*
    |--------------------------------------------------------------------------
    | Mask token
    |--------------------------------------------------------------------------
    |
    | The literal replacement used when the active strategy is 'mask'.
    |
    */
    'mask_token' => env('PII_REDACTOR_MASK_TOKEN', '[REDACTED]'),

    /*
    |--------------------------------------------------------------------------
    | Hash strategy hex length
    |--------------------------------------------------------------------------
    |
    | Number of hex chars from the SHA-256 prefix to emit inside
    | `[hash:...]` substitutions. Must be between 4 and 64. The 16-char
    | default gives a 64-bit namespace, comfortably above the birthday
    | bound for any realistic corpus (collisions stay theoretical until
    | tens of millions of distinct values). Drop to 8 only if you
    | accept that downstream joins on `[hash:...]` may collapse
    | unrelated records once the dataset crosses ~30k uniques.
    |
    */
    'hash_hex_length' => (int) env('PII_REDACTOR_HASH_LENGTH', 16),

    /*
    |--------------------------------------------------------------------------
    | Tokenise strategy hex length
    |--------------------------------------------------------------------------
    |
    | Number of hex chars in the deterministic id portion of
    | `[tok:<detector>:<id>]`. Must be between 8 and 64. Defaults to 16
    | (= 64-bit namespace) so the reverse-map collision risk is
    | negligible for any realistic corpus. Bump higher when working
    | against a very large unique-value space; never drop below 8.
    |
    */
    'token_hex_length' => (int) env('PII_REDACTOR_TOKEN_LENGTH', 16),

    /*
    |--------------------------------------------------------------------------
    | Enabled detectors
    |--------------------------------------------------------------------------
    |
    | Whitelist of detector class names that the ServiceProvider should
    | register. Unknown classes are skipped silently; remove an entry to
    | disable a detector. Custom detectors registered via Pii::extend()
    | bypass this list.
    |
    */
    'detectors' => [
        CodiceFiscaleDetector::class,
        PartitaIvaDetector::class,
        IbanDetector::class,
        EmailDetector::class,
        PhoneItalianDetector::class,
        CreditCardDetector::class,
        AddressItalianDetector::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit-trail toggle (v0.1 backward-compat flat key)
    |--------------------------------------------------------------------------
    |
    | v0.1 published this as a flat boolean. v0.2 promotes it to the
    | structured `audit_trail.enabled` block below; the flat key is preserved
    | for existing hosts upgrading without republishing the config. The SP
    | ORs this value with `audit_trail.enabled` — setting EITHER key to true
    | is sufficient to enable the audit trail. New deployments should use only
    | the structured key; leave this entry untouched until a future major drops
    | it.
    |
    */
    'audit_trail_enabled' => env('PII_REDACTOR_AUDIT_TRAIL', false),

    /*
    |--------------------------------------------------------------------------
    | Audit-trail (v0.2 structured)
    |--------------------------------------------------------------------------
    |
    | When enabled, RedactorEngine fires a PiiRedactionPerformed event after
    | any redact() call that produced at least one detection, carrying ONLY
    | counts (never raw PII). The SP ORs this key with the v0.1 flat key above
    | so EITHER being truthy enables the trail — existing hosts upgrading from
    | v0.1 without republishing the config are honoured automatically.
    |
    */
    'audit_trail' => [
        'enabled' => env('PII_REDACTOR_AUDIT_TRAIL_V2', env('PII_REDACTOR_AUDIT_TRAIL', false)),
    ],

    /*
    |--------------------------------------------------------------------------
    | NER (named-entity recognition) driver
    |--------------------------------------------------------------------------
    |
    | Pluggable scaffold for v0.3 HuggingFace + spaCy drivers. v0.2 ships only
    | the `stub` driver (no-op) so the surface is stable. To enable real NER
    | in v0.3+ flip `enabled => true` and select a `driver` whose FQCN appears
    | in the `drivers` map.
    |
    */
    'ner' => [
        'enabled' => env('PII_REDACTOR_NER_ENABLED', false),
        'driver' => env('PII_REDACTOR_NER_DRIVER', 'stub'),
        'drivers' => [
            'stub' => StubNerDriver::class,
            'huggingface' => HuggingFaceNerDriver::class,
            'spacy' => SpaCyNerDriver::class,
        ],

        /*
        |----------------------------------------------------------------------
        | HuggingFace Inference API driver
        |----------------------------------------------------------------------
        |
        | Reads at construction time when the SP resolves the
        | HuggingFaceNerDriver via $app->make(...). `api_key` MUST be set
        | (the constructor throws StrategyException on empty). The default
        | model `Davlan/bert-base-multilingual-cased-ner-hrl` covers Italian
        | + 9 other languages; swap via PII_REDACTOR_HUGGINGFACE_MODEL when
        | you want a more specialised model. Cold-start latency on the free
        | inference endpoint can exceed 20s — bump the timeout if you see
        | spurious empty returns.
        |
        */
        'huggingface' => [
            'api_key' => env('PII_REDACTOR_HUGGINGFACE_API_KEY', ''),
            'model' => env('PII_REDACTOR_HUGGINGFACE_MODEL', 'Davlan/bert-base-multilingual-cased-ner-hrl'),
            'base_url' => env('PII_REDACTOR_HUGGINGFACE_BASE_URL', 'https://api-inference.huggingface.co'),
            'timeout' => (int) env('PII_REDACTOR_HUGGINGFACE_TIMEOUT', 30),
        ],

        /*
        |----------------------------------------------------------------------
        | spaCy HTTP server driver
        |----------------------------------------------------------------------
        |
        | Generic JSON contract: POST <server_url> { "text": "..." } responds
        | { "entities": [ { "label": ..., "start_char": ..., "end_char": ...,
        | "text": ... }, ... ] }. That is exactly the shape
        | `spacy.tokens.Doc.to_json()` emits, so any spaCy server returning a
        | serialised Doc is compatible. The constructor throws
        | StrategyException on empty `server_url`.
        |
        | `api_key` is OPTIONAL — the protocol allows anonymous servers (a
        | trusted self-hosted Flask/FastAPI on a private VPC). When set, the
        | driver attaches `Authorization: Bearer <key>`. Set `entity_map` to
        | null (default) to use the built-in spaCy → detector mapping
        | (PERSON/PER → person, ORG → organisation, GPE/LOC → location, NORP
        | → group, FAC → facility); pass an array to override.
        |
        */
        'spacy' => [
            'server_url' => env('PII_REDACTOR_SPACY_SERVER_URL', ''),
            'api_key' => env('PII_REDACTOR_SPACY_API_KEY'),
            'timeout' => (int) env('PII_REDACTOR_SPACY_TIMEOUT', 30),
            'entity_map' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | TokenStore (v0.2 persistent reverse map)
    |--------------------------------------------------------------------------
    |
    | Backs the reversible TokeniseStrategy. The default `memory` driver is
    | process-local — a queue worker restart discards every mapping, which
    | is fine for short-lived ad-hoc redactions but useless when the same
    | token must detokenise across deploys / multiple workers.
    |
    | Set `driver => 'database'` to persist the map into the
    | `pii_token_maps` table. Run the migration first:
    |
    |     php artisan vendor:publish --tag=pii-redactor-migrations
    |     php artisan migrate
    |
    | The `connection` knob lets you isolate the table on a dedicated
    | DB connection (recommended for hosts that already partition PII
    | from operational data).
    |
    */
    'token_store' => [
        'driver' => env('PII_REDACTOR_TOKEN_STORE', 'memory'),
        'database' => [
            'connection' => env('PII_REDACTOR_TOKEN_STORE_CONNECTION', null),
            'table' => env('PII_REDACTOR_TOKEN_STORE_TABLE', 'pii_token_maps'),
        ],
        'cache' => [
            'store' => env('PII_REDACTOR_TOKEN_STORE_CACHE_STORE'),  // null = host's default cache
            'prefix' => env('PII_REDACTOR_TOKEN_STORE_CACHE_PREFIX', 'pii_token:'),
            'ttl' => (int) env('PII_REDACTOR_TOKEN_STORE_CACHE_TTL', 0),  // 0 = forever
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom-rule YAML packs (v0.3)
    |--------------------------------------------------------------------------
    |
    | Per-tenant detector packs loaded from YAML files at boot. Each pack
    | registers as a named detector through Pii::extend() at the host's
    | convenience — see README "Custom rule packs" section.
    |
    | When this list is non-empty AND `auto_register => true`, the
    | ServiceProvider walks every entry, loads the YAML via
    | YamlCustomRuleLoader, and registers a CustomRuleDetector under the
    | pack's `name`. Failures throw CustomRuleException at boot — better
    | to crash early than silently miss rules.
    |
    */
    'custom_rules' => [
        'auto_register' => env('PII_REDACTOR_CUSTOM_RULES_AUTO_REGISTER', false),
        'packs' => [
            // Example shape; populated by the host application.
            // [
            //     'name' => 'custom_it_albo',
            //     'path' => storage_path('app/pii-rules/it-albo.yaml'),
            // ],
        ],
    ],

];
