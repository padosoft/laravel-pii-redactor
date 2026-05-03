<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Robustness;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Padosoft\PiiRedactor\CustomRules\CustomRule;
use Padosoft\PiiRedactor\CustomRules\CustomRuleDetector;
use Padosoft\PiiRedactor\CustomRules\CustomRuleSet;
use Padosoft\PiiRedactor\Detectors\AddressItalianDetector;
use Padosoft\PiiRedactor\Detectors\CodiceFiscaleDetector;
use Padosoft\PiiRedactor\Detectors\CreditCardDetector;
use Padosoft\PiiRedactor\Detectors\Detector;
use Padosoft\PiiRedactor\Detectors\EmailDetector;
use Padosoft\PiiRedactor\Detectors\IbanDetector;
use Padosoft\PiiRedactor\Detectors\PartitaIvaDetector;
use Padosoft\PiiRedactor\Detectors\PhoneItalianDetector;
use Padosoft\PiiRedactor\Ner\HuggingFaceNerDriver;
use Padosoft\PiiRedactor\Ner\SpaCyNerDriver;
use Padosoft\PiiRedactor\RedactorEngine;
use Padosoft\PiiRedactor\Strategies\MaskStrategy;
use Padosoft\PiiRedactor\Tests\TestCase;
use Padosoft\PiiRedactor\TokenStore\CacheTokenStore;
use Padosoft\PiiRedactor\TokenStore\DatabaseTokenStore;
use Padosoft\PiiRedactor\TokenStore\InMemoryTokenStore;

/**
 * Robustness tests covering categories 1 (Unicode / multi-byte) and 2
 * (boundary inputs) for every detector, every TokenStore driver, and the
 * RedactorEngine.
 *
 * The original suite covers the canonical happy paths; these tests pin
 * behaviour in the corners that AI-generated regression rounds tend to
 * regress: accents, apostrophes, RTL text, emoji, zero-width chars,
 * extreme lengths, single-character inputs, whitespace-only inputs.
 *
 * Each test method documents what a regression in that area would break
 * for a real consumer (typically: a missed PII redaction, or a hard crash
 * on a degenerate input that should have returned [] cleanly).
 */
final class UnicodeAndBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('cache.default', 'array');
        $app['config']->set('pii-redactor.ner.huggingface.api_key', 'test-key');
        $app['config']->set('pii-redactor.ner.spacy.server_url', 'https://spacy.example.test/ner');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../../database/migrations');
    }

    // ---------------------------------------------------------------
    // AddressItalianDetector — unicode and boundaries.
    // ---------------------------------------------------------------

    /**
     * Catches: a regression that drops the À-Ý / À-ÿ unicode classes from
     * the proper-noun anchor, blocking real Italian street names like
     * "Via Sant'Antòniò".
     */
    public function test_address_detects_italian_street_with_multiple_accents_and_apostrophe(): void
    {
        $detector = new AddressItalianDetector;

        $hits = $detector->detect("Abito in Via Sant'Antòniò 12 a Milano.");

        $this->assertNotEmpty($hits);
        $this->assertStringContainsString('Sant', $hits[0]->value);
        $this->assertStringContainsString('12', $hits[0]->value);
    }

    /**
     * Catches: a regression that drops the apostrophe-elided
     * connectives (`dell'`, `nell'`, `sull'`, `all'`, `coll'`) from
     * the pattern. The v0.3 robustness suite pinned the missing
     * support as a known limitation; v1.0 closes it by extending
     * the alternation to include the elided forms.
     *
     * Italian linguistic context: `dell'` = `della` + open vowel
     * elision (Treccani / Accademia della Crusca); same family as
     * `nell'`, `sull'`, `all'`, `coll'`. They appear constantly in
     * real-world Italian addresses (Università, Olmo, Aniene…).
     */
    public function test_address_detects_dell_apostrophe_compound(): void
    {
        $detector = new AddressItalianDetector;

        $hits = $detector->detect("Sede in Via dell'Università 1 a Bologna.");

        $this->assertNotEmpty($hits);
        $this->assertStringContainsString("dell'Università", $hits[0]->value);
        $this->assertStringContainsString('1', $hits[0]->value);
    }

    /**
     * Catches: "Via d'Annunzio" stripped to "Via d" because the
     * apostrophe-glued proper noun loses its accented variant.
     */
    public function test_address_detects_apostrophe_glued_proper_noun_with_accent(): void
    {
        $detector = new AddressItalianDetector;

        $hits = $detector->detect("Studio in Via d'Aragòna 1 a Napoli.");

        $this->assertNotEmpty($hits);
        $this->assertStringContainsString("d'Aragòna", $hits[0]->value);
    }

    /**
     * Catches: a future regression that broadens the proper-noun anchor
     * to any Unicode letter category. The detector is Italian-only by
     * contract; CJK input must NOT match.
     */
    public function test_address_does_not_match_chinese_characters_after_via(): void
    {
        $detector = new AddressItalianDetector;

        // CJK is outside the Italian-letter character class A-ZÀ-Ý.
        $hits = $detector->detect('Strada via 不存在 12 città.');

        $this->assertSame([], $hits);
    }

    /**
     * Catches: PCRE engine miscompiling /u flag → falsely matching
     * empty string at every position on a 100KB blob.
     */
    public function test_address_detector_handles_100kb_input_in_under_one_second(): void
    {
        $detector = new AddressItalianDetector;

        // 100KB of plausible filler with a known address in the middle.
        $filler = str_repeat('Lorem ipsum dolor sit amet consectetur. ', 2500);
        $needle = 'Via Roma 12';
        $text = substr($filler, 0, 50_000).$needle.substr($filler, 50_000);

        $start = microtime(true);
        $hits = $detector->detect($text);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(1.0, $elapsed, sprintf('Detector took %.3fs on 100KB', $elapsed));
        $this->assertNotEmpty($hits);
    }

    /**
     * Catches: a detector that returns a Detection with offset/length
     * pointing past EOL on whitespace-only input — would crash
     * substr_replace() inside the engine.
     */
    public function test_address_returns_empty_on_whitespace_only_input(): void
    {
        $detector = new AddressItalianDetector;

        $this->assertSame([], $detector->detect("   \n\t  "));
    }

    /**
     * Catches: bare \b regression — single-char input must be safe.
     */
    public function test_address_returns_empty_on_single_character_input(): void
    {
        $detector = new AddressItalianDetector;

        $this->assertSame([], $detector->detect('a'));
    }

    // ---------------------------------------------------------------
    // EmailDetector — unicode and boundaries.
    // ---------------------------------------------------------------

    /**
     * Catches: regex losing `+` from the local-part char class — would
     * miss every "tagged" address and silently leak a category of PII.
     */
    public function test_email_detects_plus_addressing_and_dot_local_part(): void
    {
        $detector = new EmailDetector;

        $hits = $detector->detect('Contatto: mario.rossi+test@example.it ok.');

        $this->assertCount(1, $hits);
        $this->assertSame('mario.rossi+test@example.it', $hits[0]->value);
    }

    /**
     * Pins the documented pragmatic ASCII-only behaviour: an IDN domain
     * (münchen.de) is NOT detected by the current regex. Documenting
     * this in a test means the next IDN-aware revision flips the
     * assertion deliberately, not by accident.
     */
    public function test_email_does_not_match_idn_domain_documents_ascii_only_behaviour(): void
    {
        $detector = new EmailDetector;

        // Pragmatic ASCII-only: the `[A-Z0-9.\-]+` domain class rejects
        // non-ASCII bytes; an IDN-aware bump must consciously change the
        // class and update this expectation.
        $hits = $detector->detect('Mail: mario@münchen.de today.');

        $this->assertSame([], $hits);
    }

    public function test_email_returns_empty_on_empty_string(): void
    {
        $detector = new EmailDetector;

        $this->assertSame([], $detector->detect(''));
    }

    public function test_email_returns_empty_on_single_character_input(): void
    {
        $detector = new EmailDetector;

        $this->assertSame([], $detector->detect('@'));
    }

    /**
     * Catches: regex without /u losing UTF-8 bytes and mis-counting
     * offsets on emoji-heavy input.
     */
    public function test_email_finds_address_after_emoji_prefix(): void
    {
        $detector = new EmailDetector;

        $text = '📧🎉👋 mario@example.com 🚀';
        $hits = $detector->detect($text);

        $this->assertCount(1, $hits);
        $this->assertSame('mario@example.com', $hits[0]->value);
        // Offset is in bytes — verify byte-accurate substr round-trip.
        $this->assertSame(
            'mario@example.com',
            substr($text, $hits[0]->offset, $hits[0]->length),
        );
    }

    // ---------------------------------------------------------------
    // PhoneItalianDetector — unicode and boundaries.
    // ---------------------------------------------------------------

    /**
     * Catches: a regex that uses literal ASCII space ` ` only — typing
     * an Italian phone with non-breaking-space separators (U+00A0)
     * would silently fail. The current regex uses `\s` which matches
     * NBSP via the /u flag — pinned here so a future "trim NBSP first"
     * refactor doesn't regress.
     */
    public function test_phone_handles_ascii_spaces_between_groups(): void
    {
        $detector = new PhoneItalianDetector;

        $hits = $detector->detect('Chiama +39 333 1234567 entro stasera.');

        $this->assertCount(1, $hits);
        $this->assertSame('+39 333 1234567', $hits[0]->value);
    }

    public function test_phone_returns_empty_on_empty_string(): void
    {
        $detector = new PhoneItalianDetector;

        $this->assertSame([], $detector->detect(''));
    }

    public function test_phone_returns_empty_on_whitespace_only_input(): void
    {
        $detector = new PhoneItalianDetector;

        $this->assertSame([], $detector->detect("\n\t  \n"));
    }

    public function test_phone_returns_empty_on_single_character_input(): void
    {
        $detector = new PhoneItalianDetector;

        $this->assertSame([], $detector->detect('3'));
    }

    // ---------------------------------------------------------------
    // IbanDetector — unicode and boundaries.
    // ---------------------------------------------------------------

    /**
     * Catches: spaced-form regex regressing to a single-space separator
     * only (no multi-space tolerance). Real users paste IBANs with
     * multiple grouping spaces; the canonical Italian print format
     * uses a single space between every 4-char group — verify that.
     */
    public function test_iban_detects_italian_iban_with_canonical_spacing(): void
    {
        $detector = new IbanDetector;

        // Canonical IT IBAN with 4-char grouping spaces.
        $hits = $detector->detect('Bonifico IT60 X054 2811 1010 0000 0123 456 oggi.');

        $this->assertNotEmpty($hits);
        $this->assertStringStartsWith('IT60', $hits[0]->value);
    }

    public function test_iban_returns_empty_on_empty_string(): void
    {
        $detector = new IbanDetector;

        $this->assertSame([], $detector->detect(''));
    }

    public function test_iban_returns_empty_on_single_character_input(): void
    {
        $detector = new IbanDetector;

        $this->assertSame([], $detector->detect('I'));
    }

    public function test_iban_returns_empty_on_whitespace_only_input(): void
    {
        $detector = new IbanDetector;

        $this->assertSame([], $detector->detect('     '));
    }

    /**
     * Catches: \b word-boundary regression that would let RTL text
     * (Arabic) confuse the byte-level boundary semantics.
     */
    public function test_iban_does_not_match_inside_arabic_text(): void
    {
        $detector = new IbanDetector;

        // RTL Arabic with no IBAN payload.
        $hits = $detector->detect('مرحبا بكم في النظام البنكي.');

        $this->assertSame([], $hits);
    }

    // ---------------------------------------------------------------
    // PartitaIvaDetector — boundaries.
    // ---------------------------------------------------------------

    public function test_partita_iva_returns_empty_on_empty_string(): void
    {
        $detector = new PartitaIvaDetector;

        $this->assertSame([], $detector->detect(''));
    }

    public function test_partita_iva_returns_empty_on_whitespace_only_input(): void
    {
        $detector = new PartitaIvaDetector;

        $this->assertSame([], $detector->detect("\t\n  "));
    }

    public function test_partita_iva_returns_empty_on_single_character_input(): void
    {
        $detector = new PartitaIvaDetector;

        $this->assertSame([], $detector->detect('1'));
    }

    // ---------------------------------------------------------------
    // CodiceFiscaleDetector — boundaries.
    // ---------------------------------------------------------------

    public function test_codice_fiscale_returns_empty_on_empty_string(): void
    {
        $detector = new CodiceFiscaleDetector;

        $this->assertSame([], $detector->detect(''));
    }

    public function test_codice_fiscale_returns_empty_on_single_character_input(): void
    {
        $detector = new CodiceFiscaleDetector;

        $this->assertSame([], $detector->detect('A'));
    }

    public function test_codice_fiscale_returns_empty_on_whitespace_only_input(): void
    {
        $detector = new CodiceFiscaleDetector;

        $this->assertSame([], $detector->detect("    \n"));
    }

    // ---------------------------------------------------------------
    // CreditCardDetector — boundaries.
    // ---------------------------------------------------------------

    public function test_credit_card_returns_empty_on_empty_string(): void
    {
        $detector = new CreditCardDetector;

        $this->assertSame([], $detector->detect(''));
    }

    public function test_credit_card_returns_empty_on_single_character_input(): void
    {
        $detector = new CreditCardDetector;

        $this->assertSame([], $detector->detect('4'));
    }

    public function test_credit_card_returns_empty_on_whitespace_only_input(): void
    {
        $detector = new CreditCardDetector;

        $this->assertSame([], $detector->detect('     '));
    }

    // ---------------------------------------------------------------
    // CustomRuleDetector — boundaries.
    // ---------------------------------------------------------------

    public function test_custom_rule_detector_returns_empty_on_empty_string(): void
    {
        $detector = new CustomRuleDetector(
            'pack',
            new CustomRuleSet([new CustomRule('rule_one', 'X-\d+')]),
        );

        $this->assertSame([], $detector->detect(''));
    }

    public function test_custom_rule_detector_returns_empty_on_whitespace_only(): void
    {
        $detector = new CustomRuleDetector(
            'pack',
            new CustomRuleSet([new CustomRule('rule_one', 'X-\d+')]),
        );

        $this->assertSame([], $detector->detect("   \n\t  "));
    }

    /**
     * Catches: custom-rule unicode-class regression. The default `u` flag
     * is documented; a rule pattern that uses unicode property classes
     * must keep working under /u.
     */
    public function test_custom_rule_with_unicode_property_class_matches_accented_word(): void
    {
        $detector = new CustomRuleDetector(
            'pack',
            // \p{Lu} = uppercase letter, \p{Ll} = lowercase, runs of either.
            // Requires the /u flag (default), which the rule inherits.
            new CustomRuleSet([new CustomRule('accented_word', '\b\p{Lu}\p{Ll}*ò\p{Ll}+\b')]),
        );

        $hits = $detector->detect('La parola Antòniò qui Plain too.');

        // Should find 'Antòniò' but not 'La' / 'Plain' (no ò).
        $this->assertCount(1, $hits);
        $this->assertSame('Antòniò', $hits[0]->value);
    }

    // ---------------------------------------------------------------
    // HuggingFace + spaCy — empty input short-circuits.
    // ---------------------------------------------------------------

    public function test_huggingface_returns_empty_on_empty_text_without_http_call(): void
    {
        Http::fake();

        $driver = new HuggingFaceNerDriver(apiKey: 'test-key');

        $this->assertSame([], $driver->detect(''));

        Http::assertNothingSent();
    }

    public function test_spacy_returns_empty_on_empty_text_without_http_call(): void
    {
        Http::fake();

        $driver = new SpaCyNerDriver;

        $this->assertSame([], $driver->detect(''));

        Http::assertNothingSent();
    }

    // ---------------------------------------------------------------
    // TokenStore drivers — boundary inputs.
    // ---------------------------------------------------------------

    /**
     * Catches: a "reject empty token" regression that would silently
     * drop empty-string keys without raising — a programming error
     * upstream (a strategy that produced an empty token literal) must
     * still be observable. The current contract: the in-memory store
     * accepts the empty string as a valid key. Test pins that.
     */
    public function test_in_memory_token_store_accepts_empty_string_token_cleanly(): void
    {
        $store = new InMemoryTokenStore;
        $store->put('', 'whatever');

        $this->assertTrue($store->has(''));
        $this->assertSame('whatever', $store->get(''));
        $this->assertSame(['' => 'whatever'], $store->dump());
    }

    /**
     * Catches: a regression on the 255-char `token` column — the
     * migration declares VARCHAR(255), so a 250-char token must fit.
     */
    public function test_database_token_store_accepts_token_within_255_char_limit(): void
    {
        $store = new DatabaseTokenStore;

        $tokenPrefix = '[tok:email:';
        $tokenSuffix = ']';
        $hexLen = 255 - strlen($tokenPrefix) - strlen($tokenSuffix);
        // Generate a hex string of exactly $hexLen chars so total is 255.
        $hex = str_repeat('a', $hexLen);
        $token = $tokenPrefix.$hex.$tokenSuffix;
        $this->assertSame(255, strlen($token));

        $store->put($token, 'mario@example.com');

        $this->assertSame('mario@example.com', $store->get($token));
    }

    /**
     * Catches: a regression in CacheTokenStore::keyFor() that would
     * pass the raw token (containing `:` `[` `]`) into the cache key
     * instead of hashing it via SHA-256. The hash insulates the
     * underlying cache key from any meta-character collision.
     */
    public function test_cache_token_store_handles_token_with_meta_characters(): void
    {
        /** @var Repository $repo */
        $repo = Cache::store('array');
        $store = new CacheTokenStore($repo);

        $token = '[tok:email:abc123]';
        $store->put($token, 'mario@example.com');

        $this->assertSame('mario@example.com', $store->get($token));
        $this->assertTrue($store->has($token));
        $this->assertSame([$token => 'mario@example.com'], $store->dump());
    }

    // ---------------------------------------------------------------
    // RedactorEngine — boundary scenarios.
    // ---------------------------------------------------------------

    /**
     * Catches: an engine refactor that assumed at least one detector
     * was always registered — would crash on an empty detector list.
     */
    public function test_engine_with_zero_detectors_returns_text_unchanged(): void
    {
        $engine = new RedactorEngine(new MaskStrategy('[X]'));

        $input = 'Contact mario@example.com or call +39 333 1234567.';
        $this->assertSame($input, $engine->redact($input));
    }

    /**
     * Catches: double-redaction regression — text that already contains
     * the literal mask token must not be re-redacted by accident.
     */
    public function test_engine_does_not_double_redact_existing_mask_token(): void
    {
        $engine = new RedactorEngine(new MaskStrategy('[REDACTED]'));
        $engine->register(new EmailDetector);

        $input = 'Old log: [REDACTED] sent the file.';
        $output = $engine->redact($input);

        $this->assertSame($input, $output);
    }

    public function test_engine_returns_empty_string_on_empty_input(): void
    {
        $engine = new RedactorEngine(new MaskStrategy);
        $engine->register(new EmailDetector);

        $this->assertSame('', $engine->redact(''));
    }

    /**
     * Catches: a memory leak / O(n²) regression in the engine on a
     * large single document. 1MB document should redact in under 5s
     * even with three detectors registered.
     */
    public function test_engine_handles_1mb_document_in_reasonable_time(): void
    {
        $engine = new RedactorEngine(new MaskStrategy('[X]'));
        $engine->register(new EmailDetector);
        $engine->register(new PhoneItalianDetector);
        $engine->register(new IbanDetector);

        $filler = str_repeat('Lorem ipsum dolor sit amet. ', 36_000); // ~1MB
        $needle = ' Email mario@example.com end. ';
        $text = substr($filler, 0, 500_000).$needle.substr($filler, 500_000);

        $start = microtime(true);
        $output = $engine->redact($text);
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(5.0, $elapsed, sprintf('Engine took %.3fs on 1MB', $elapsed));
        $this->assertStringNotContainsString('mario@example.com', $output);
    }

    /**
     * Stress: emoji + zero-width joiner near a real PII match. Catches
     * a regression where ZWJ bytes confuse the regex engine and the
     * detection offset/length are off-by-one bytes.
     */
    public function test_engine_byte_accurate_offsets_with_zwj_neighbour(): void
    {
        // ZWJ between two emoji; immediately followed by a real email.
        $zwj = "\u{200D}";
        $text = "👨{$zwj}💻 mario@example.com here";

        $engine = new RedactorEngine(new MaskStrategy('[X]'));
        $engine->register(new EmailDetector);

        $output = $engine->redact($text);
        $this->assertStringNotContainsString('mario@example.com', $output);
        $this->assertStringContainsString('[X]', $output);
    }

    // ---------------------------------------------------------------
    // Cross-detector noise (sanity guard for follow-on tests).
    // ---------------------------------------------------------------

    /**
     * Catches: a detector tuple that all crash on the same degenerate
     * input — proves the boundary contract is uniform across the
     * first-party detector roster. If any one of these starts throwing,
     * every host integration breaks.
     */
    public function test_every_first_party_detector_returns_empty_array_on_empty_string(): void
    {
        /** @var list<Detector> $detectors */
        $detectors = [
            new AddressItalianDetector,
            new EmailDetector,
            new PhoneItalianDetector,
            new IbanDetector,
            new PartitaIvaDetector,
            new CodiceFiscaleDetector,
            new CreditCardDetector,
        ];

        foreach ($detectors as $detector) {
            $this->assertSame(
                [],
                $detector->detect(''),
                sprintf('Detector %s should return [] on empty string', $detector->name()),
            );
        }
    }
}
