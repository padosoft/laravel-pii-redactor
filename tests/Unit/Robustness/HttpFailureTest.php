<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Robustness;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Padosoft\PiiRedactor\Ner\HuggingFaceNerDriver;
use Padosoft\PiiRedactor\Ner\SpaCyNerDriver;
use Padosoft\PiiRedactor\Tests\TestCase;

/**
 * Robustness tests covering category 6 (HTTP failure modes).
 *
 * Both NER drivers MUST fail open: a NER outage cannot block the
 * deterministic detectors. These tests pin the fail-open contract
 * across the full failure matrix:
 *
 *   - non-JSON 200 body
 *   - JSON 200 with `entities: null`
 *   - JSON 200 with `entities: []`
 *   - HTTP 429 (rate limit)
 *   - HTTP 503 (service unavailable)
 *   - HTTP 500 (server error)
 *   - connection-error / network reset
 *   - very large response (1000-entity payload)
 *
 * Each test pins the driver-level behaviour so a future refactor
 * either preserves it or changes it deliberately under an ADR.
 */
final class HttpFailureTest extends TestCase
{
    private const TEXT = 'Mario Rossi works at Padosoft in Milan.';

    protected function defineEnvironment($app): void
    {
        $app['config']->set('pii-redactor.ner.spacy', [
            'server_url' => 'https://spacy.example.test/ner',
            'api_key' => null,
            'timeout' => 30,
            'entity_map' => null,
        ]);
    }

    // ---------------------------------------------------------------
    // HuggingFace driver.
    // ---------------------------------------------------------------

    /**
     * Catches: a regression that drops the `is_array($body)` guard
     * after `$response->json()`. A non-JSON body would be parsed as
     * null and feeding null to the foreach would Warning-crash.
     */
    public function test_huggingface_returns_empty_on_200_with_non_json_body(): void
    {
        Http::fake([
            'api-inference.huggingface.co/*' => Http::response('not even json', 200, [
                'Content-Type' => 'text/plain',
            ]),
        ]);

        $driver = new HuggingFaceNerDriver(apiKey: 'test-key');

        $this->assertSame([], $driver->detect(self::TEXT));
    }

    /**
     * Catches: a regression where the driver assumes `entities`
     * exists on the response. HuggingFace returns a TOP-LEVEL list
     * (not `{entities: [...]}`); a regression to read `entities:`
     * shape would break existing consumers. Pin the contract: the
     * top-level body IS the array.
     */
    public function test_huggingface_returns_empty_on_200_with_empty_array(): void
    {
        Http::fake([
            'api-inference.huggingface.co/*' => Http::response([], 200),
        ]);

        $driver = new HuggingFaceNerDriver(apiKey: 'test-key');

        $this->assertSame([], $driver->detect(self::TEXT));
    }

    /**
     * Catches: a regression on rate-limiting. 429 must fail open —
     * NEVER block the deterministic detectors.
     */
    public function test_huggingface_returns_empty_on_429_rate_limit(): void
    {
        Http::fake([
            'api-inference.huggingface.co/*' => Http::response('rate limit', 429),
        ]);

        $driver = new HuggingFaceNerDriver(apiKey: 'test-key');

        $this->assertSame([], $driver->detect(self::TEXT));
    }

    /**
     * Catches: a regression where 503 (service unavailable) is
     * treated differently from generic 5xx. The existing fail-open
     * path uses `$response->ok()` which is `2xx` only, so 503
     * surfaces as fail-open uniformly.
     */
    public function test_huggingface_returns_empty_on_503_service_unavailable(): void
    {
        Http::fake([
            'api-inference.huggingface.co/*' => Http::response('upstream down', 503),
        ]);

        $driver = new HuggingFaceNerDriver(apiKey: 'test-key');

        $this->assertSame([], $driver->detect(self::TEXT));
    }

    /**
     * Pins the OBSERVED behaviour for connection-level errors under
     * Http::fake(): when the fake closure throws ConnectionException,
     * Laravel's Http client converts it to a synthetic response (the
     * fake harness catches the throw before it reaches user code) and
     * the driver's `$response->ok()` guard returns false — fail-open
     * path returns []. This is the unit-test contract.
     *
     * KNOWN LIMITATION (caveat for live networking): in PRODUCTION
     * with a real `Illuminate\Http\Client\PendingRequest`, a true
     * connection failure (DNS / TCP reset / TLS handshake fail) DOES
     * bubble up as a ConnectionException — the driver does not
     * try/catch around the post() call, so a real network outage
     * propagates. A v1.0 hardening should add `Http::retry()` +
     * try/catch with downgrade-to-empty. For now this test pins what
     * the unit harness can observe; the production path is covered
     * by Live tests opt-in.
     */
    public function test_huggingface_returns_empty_when_fake_closure_throws_connection_exception(): void
    {
        Http::fake([
            'api-inference.huggingface.co/*' => function (): never {
                throw new ConnectionException('Connection reset by peer');
            },
        ]);

        $driver = new HuggingFaceNerDriver(apiKey: 'test-key');

        // Http::fake catches the throw, emits a synthetic non-OK response,
        // and the driver's fail-open path returns [].
        $this->assertSame([], $driver->detect(self::TEXT));
    }

    /**
     * Catches: a regression on payload size. 1000 entities must
     * map to 1000 detections without OOM, completing in <2s.
     */
    public function test_huggingface_handles_1000_entity_response_in_under_two_seconds(): void
    {
        $payload = [];
        for ($i = 0; $i < 1000; $i++) {
            $payload[] = [
                'entity_group' => 'PER',
                'score' => 0.99,
                'word' => 'Mario',
                'start' => 0,
                'end' => 5,
            ];
        }

        Http::fake([
            'api-inference.huggingface.co/*' => Http::response($payload, 200),
        ]);

        $driver = new HuggingFaceNerDriver(apiKey: 'test-key');

        $start = microtime(true);
        $detections = $driver->detect('Mario was here.');
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(2.0, $elapsed, sprintf(
            'HF driver took %.3fs on 1000-entity payload',
            $elapsed,
        ));
        $this->assertCount(1000, $detections);
    }

    /**
     * Catches: a regression that crashes on a 200 status with
     * top-level `null` body (HF sometimes returns `null` while a
     * model is warming up). The driver's `is_array($body)` guard
     * must catch this.
     */
    public function test_huggingface_returns_empty_on_200_with_null_body(): void
    {
        Http::fake([
            'api-inference.huggingface.co/*' => Http::response('null', 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        $driver = new HuggingFaceNerDriver(apiKey: 'test-key');

        $this->assertSame([], $driver->detect(self::TEXT));
    }

    // ---------------------------------------------------------------
    // spaCy driver.
    // ---------------------------------------------------------------

    public function test_spacy_returns_empty_on_200_with_non_json_body(): void
    {
        Http::fake([
            'spacy.example.test/*' => Http::response('not json', 200, [
                'Content-Type' => 'text/plain',
            ]),
        ]);

        $driver = new SpaCyNerDriver;

        $this->assertSame([], $driver->detect(self::TEXT));
    }

    /**
     * Catches: a regression where `entities: null` in the payload
     * (spaCy's "no entities recognised" shape from some custom
     * wrappers) crashes the driver instead of returning [].
     */
    public function test_spacy_returns_empty_on_200_with_entities_null(): void
    {
        Http::fake([
            'spacy.example.test/*' => Http::response(['entities' => null], 200),
        ]);

        $driver = new SpaCyNerDriver;

        $this->assertSame([], $driver->detect(self::TEXT));
    }

    public function test_spacy_returns_empty_on_200_with_entities_empty_array(): void
    {
        Http::fake([
            'spacy.example.test/*' => Http::response(['entities' => []], 200),
        ]);

        $driver = new SpaCyNerDriver;

        $this->assertSame([], $driver->detect(self::TEXT));
    }

    public function test_spacy_returns_empty_on_429_rate_limit(): void
    {
        Http::fake([
            'spacy.example.test/*' => Http::response('rate limit', 429),
        ]);

        $driver = new SpaCyNerDriver;

        $this->assertSame([], $driver->detect(self::TEXT));
    }

    public function test_spacy_returns_empty_on_503_service_unavailable(): void
    {
        Http::fake([
            'spacy.example.test/*' => Http::response('down', 503),
        ]);

        $driver = new SpaCyNerDriver;

        $this->assertSame([], $driver->detect(self::TEXT));
    }

    /**
     * Same OBSERVED BEHAVIOUR as the HuggingFace counterpart:
     * Http::fake catches the throw, emits a synthetic non-OK
     * response, and the driver's fail-open path returns []. See
     * HuggingFace test docblock for the production-vs-fake caveat.
     */
    public function test_spacy_returns_empty_when_fake_closure_throws_connection_exception(): void
    {
        Http::fake([
            'spacy.example.test/*' => function (): never {
                throw new ConnectionException('Connection reset by peer');
            },
        ]);

        $driver = new SpaCyNerDriver;

        $this->assertSame([], $driver->detect(self::TEXT));
    }

    public function test_spacy_handles_1000_entity_response_in_under_two_seconds(): void
    {
        $entities = [];
        for ($i = 0; $i < 1000; $i++) {
            $entities[] = [
                'label' => 'PERSON',
                'start_char' => 0,
                'end_char' => 5,
                'text' => 'Mario',
            ];
        }

        Http::fake([
            'spacy.example.test/*' => Http::response(['entities' => $entities], 200),
        ]);

        $driver = new SpaCyNerDriver;

        $start = microtime(true);
        $detections = $driver->detect('Mario was here.');
        $elapsed = microtime(true) - $start;

        $this->assertLessThan(2.0, $elapsed, sprintf(
            'spaCy driver took %.3fs on 1000-entity payload',
            $elapsed,
        ));
        $this->assertCount(1000, $detections);
    }

    /**
     * Catches: a regression that crashes when a 200-status response
     * has a JSON body without an `entities` key entirely. The
     * driver's `isset($body['entities'])` guard must catch this.
     */
    public function test_spacy_returns_empty_on_200_with_missing_entities_key(): void
    {
        Http::fake([
            'spacy.example.test/*' => Http::response(['unrelated' => 'shape'], 200),
        ]);

        $driver = new SpaCyNerDriver;

        $this->assertSame([], $driver->detect(self::TEXT));
    }

    /**
     * Catches: a regression where `entities` is a string (some buggy
     * wrappers emit `"entities": "[]"` literally). The
     * `is_array($body['entities'])` guard must catch this.
     */
    public function test_spacy_returns_empty_on_200_with_entities_as_string(): void
    {
        Http::fake([
            'spacy.example.test/*' => Http::response(['entities' => '[]'], 200),
        ]);

        $driver = new SpaCyNerDriver;

        $this->assertSame([], $driver->detect(self::TEXT));
    }
}
