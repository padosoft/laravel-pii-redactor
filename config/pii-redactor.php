<?php

declare(strict_types=1);

use Padosoft\PiiRedactor\Detectors\CodiceFiscaleDetector;
use Padosoft\PiiRedactor\Detectors\CreditCardDetector;
use Padosoft\PiiRedactor\Detectors\EmailDetector;
use Padosoft\PiiRedactor\Detectors\IbanDetector;
use Padosoft\PiiRedactor\Detectors\PartitaIvaDetector;
use Padosoft\PiiRedactor\Detectors\PhoneItalianDetector;

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
    | `[hash:...]` substitutions. Must be between 4 and 64.
    |
    */
    'hash_hex_length' => (int) env('PII_REDACTOR_HASH_LENGTH', 8),

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
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit-trail toggle
    |--------------------------------------------------------------------------
    |
    | Reserved for v0.2 — when true the engine will fire a
    | PiiRedactionPerformed event the host application can subscribe to
    | (counts only; never the raw values). Currently informational.
    |
    */
    'audit_trail_enabled' => env('PII_REDACTOR_AUDIT_TRAIL', false),

];
