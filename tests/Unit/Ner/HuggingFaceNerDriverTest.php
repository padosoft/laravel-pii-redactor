<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Ner;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Padosoft\PiiRedactor\Detectors\Detection;
use Padosoft\PiiRedactor\Exceptions\StrategyException;
use Padosoft\PiiRedactor\Ner\HuggingFaceNerDriver;
use Padosoft\PiiRedactor\Ner\NerDriver;
use Padosoft\PiiRedactor\Tests\TestCase;

final class HuggingFaceNerDriverTest extends TestCase
{
    private const TEXT = 'Mario Rossi works at Padosoft in Milan.';

    public function test_implements_ner_driver_interface(): void
    {
        $driver = new HuggingFaceNerDriver(apiKey: 'test-key');

        $this->assertInstanceOf(NerDriver::class, $driver);
    }

    public function test_name_returns_huggingface(): void
    {
        $driver = new HuggingFaceNerDriver(apiKey: 'test-key');

        $this->assertSame('huggingface', $driver->name());
    }

    public function test_constructor_throws_on_empty_api_key(): void
    {
        $this->expectException(StrategyException::class);
        $this->expectExceptionMessage('HuggingFaceNerDriver requires a non-empty API key');

        // Override the config-fallback explicitly so any host-side env doesn't
        // mask the constructor guard.
        config()->set('pii-redactor.ner.huggingface', ['api_key' => '']);

        new HuggingFaceNerDriver(apiKey: '');
    }

    public function test_empty_input_short_circuits_without_http_call(): void
    {
        Http::fake();

        $driver = new HuggingFaceNerDriver(apiKey: 'test-key');

        $this->assertSame([], $driver->detect(''));

        Http::assertNothingSent();
    }

    public function test_detects_aggregated_entities_with_correct_offsets(): void
    {
        Http::fake([
            'api-inference.huggingface.co/*' => Http::response([
                ['entity_group' => 'PER', 'score' => 0.99, 'word' => 'Mario Rossi', 'start' => 0, 'end' => 11],
                ['entity_group' => 'ORG', 'score' => 0.97, 'word' => 'Padosoft', 'start' => 21, 'end' => 29],
                ['entity_group' => 'LOC', 'score' => 0.95, 'word' => 'Milan', 'start' => 33, 'end' => 38],
            ], 200),
        ]);

        $driver = new HuggingFaceNerDriver(apiKey: 'test-key');

        $detections = $driver->detect(self::TEXT);

        $this->assertCount(3, $detections);

        $this->assertSame('person', $detections[0]->detector);
        $this->assertSame('Mario Rossi', $detections[0]->value);
        $this->assertSame(0, $detections[0]->offset);
        $this->assertSame(11, $detections[0]->length);

        $this->assertSame('organisation', $detections[1]->detector);
        $this->assertSame('Padosoft', $detections[1]->value);
        $this->assertSame(21, $detections[1]->offset);
        $this->assertSame(8, $detections[1]->length);

        $this->assertSame('location', $detections[2]->detector);
        $this->assertSame('Milan', $detections[2]->value);
        $this->assertSame(33, $detections[2]->offset);
        $this->assertSame(5, $detections[2]->length);

        foreach ($detections as $d) {
            $this->assertInstanceOf(Detection::class, $d);
        }
    }

    public function test_uses_bearer_auth_and_correct_endpoint(): void
    {
        Http::fake([
            'api-inference.huggingface.co/*' => Http::response([], 200),
        ]);

        $driver = new HuggingFaceNerDriver(
            apiKey: 'secret-token',
            model: 'My/Custom-Model',
        );

        $driver->detect(self::TEXT);

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('POST', $request->method());
            $this->assertSame(
                'https://api-inference.huggingface.co/models/My/Custom-Model',
                $request->url(),
            );
            $this->assertSame('Bearer secret-token', $request->header('Authorization')[0] ?? null);

            $body = $request->data();
            $this->assertSame(self::TEXT, $body['inputs'] ?? null);
            $this->assertSame(['wait_for_model' => true], $body['options'] ?? null);

            return true;
        });
    }

    public function test_failure_response_returns_empty_list(): void
    {
        // Fail open on 5xx — NER outage cannot block deterministic detectors.
        Http::fake([
            'api-inference.huggingface.co/*' => Http::response('upstream blew up', 500),
        ]);

        $driver = new HuggingFaceNerDriver(apiKey: 'test-key');

        $this->assertSame([], $driver->detect(self::TEXT));
    }

    public function test_unauthorized_response_returns_empty_list(): void
    {
        Http::fake([
            'api-inference.huggingface.co/*' => Http::response(['error' => 'invalid token'], 401),
        ]);

        $driver = new HuggingFaceNerDriver(apiKey: 'bad-token');

        $this->assertSame([], $driver->detect(self::TEXT));
    }

    public function test_malformed_non_array_body_returns_empty_list(): void
    {
        // HF can return a string error on the success channel for some
        // models (e.g. 'currently loading' message). Defend the parser.
        Http::fake([
            'api-inference.huggingface.co/*' => Http::response('"loading"', 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        $driver = new HuggingFaceNerDriver(apiKey: 'test-key');

        $this->assertSame([], $driver->detect(self::TEXT));
    }

    public function test_entries_missing_offsets_are_skipped(): void
    {
        Http::fake([
            'api-inference.huggingface.co/*' => Http::response([
                // Missing start
                ['entity_group' => 'PER', 'word' => 'Mario Rossi', 'end' => 11],
                // Missing end
                ['entity_group' => 'ORG', 'word' => 'Padosoft', 'start' => 21],
                // Missing entity_group entirely (per-token form — silently dropped)
                ['entity' => 'B-PER', 'score' => 0.9, 'word' => 'Mario', 'start' => 0, 'end' => 5],
                // Valid one to prove the loop continues
                ['entity_group' => 'LOC', 'word' => 'Milan', 'start' => 33, 'end' => 38],
            ], 200),
        ]);

        $driver = new HuggingFaceNerDriver(apiKey: 'test-key');

        $detections = $driver->detect(self::TEXT);

        $this->assertCount(1, $detections);
        $this->assertSame('location', $detections[0]->detector);
        $this->assertSame('Milan', $detections[0]->value);
    }

    public function test_unknown_entity_group_is_skipped(): void
    {
        Http::fake([
            'api-inference.huggingface.co/*' => Http::response([
                // Unmapped label — model emits something we don't translate.
                ['entity_group' => 'DATE', 'word' => '2026', 'start' => 0, 'end' => 4],
                ['entity_group' => 'PER', 'word' => 'Mario', 'start' => 0, 'end' => 5],
            ], 200),
        ]);

        $driver = new HuggingFaceNerDriver(apiKey: 'test-key');

        $detections = $driver->detect(self::TEXT);

        $this->assertCount(1, $detections);
        $this->assertSame('person', $detections[0]->detector);
    }

    public function test_zero_length_entity_is_skipped(): void
    {
        Http::fake([
            'api-inference.huggingface.co/*' => Http::response([
                ['entity_group' => 'PER', 'word' => '', 'start' => 5, 'end' => 5],
                ['entity_group' => 'PER', 'word' => 'Mario', 'start' => 0, 'end' => 5],
            ], 200),
        ]);

        $driver = new HuggingFaceNerDriver(apiKey: 'test-key');

        $detections = $driver->detect(self::TEXT);

        $this->assertCount(1, $detections);
        $this->assertSame(0, $detections[0]->offset);
        $this->assertSame(5, $detections[0]->length);
    }

    public function test_negative_or_inverted_offsets_are_skipped(): void
    {
        Http::fake([
            'api-inference.huggingface.co/*' => Http::response([
                ['entity_group' => 'PER', 'word' => 'oops', 'start' => -1, 'end' => 4],
                ['entity_group' => 'PER', 'word' => 'oops', 'start' => 10, 'end' => 5],
                ['entity_group' => 'PER', 'word' => 'Mario', 'start' => 0, 'end' => 5],
            ], 200),
        ]);

        $driver = new HuggingFaceNerDriver(apiKey: 'test-key');

        $detections = $driver->detect(self::TEXT);

        $this->assertCount(1, $detections);
        $this->assertSame('Mario', $detections[0]->value);
    }

    public function test_custom_entity_map_overrides_defaults(): void
    {
        Http::fake([
            'api-inference.huggingface.co/*' => Http::response([
                ['entity_group' => 'GPE', 'word' => 'Italy', 'start' => 0, 'end' => 5],
            ], 200),
        ]);

        $driver = new HuggingFaceNerDriver(
            apiKey: 'test-key',
            entityMap: ['GPE' => 'location'],
        );

        $detections = $driver->detect('Italy is a country.');

        $this->assertCount(1, $detections);
        $this->assertSame('location', $detections[0]->detector);
    }

    public function test_constructor_falls_back_to_config_when_args_omitted(): void
    {
        // Mirror the SP path: $app->make(HuggingFaceNerDriver::class) with no
        // explicit args. The driver must self-resolve from config.
        config()->set('pii-redactor.ner.huggingface', [
            'api_key' => 'config-key',
            'model' => 'configured/model',
            'base_url' => 'https://api-inference.huggingface.co',
            'timeout' => 5,
        ]);

        Http::fake([
            'api-inference.huggingface.co/*' => Http::response([], 200),
        ]);

        $driver = $this->app->make(HuggingFaceNerDriver::class);
        $driver->detect(self::TEXT);

        Http::assertSent(function (Request $request): bool {
            $this->assertSame(
                'https://api-inference.huggingface.co/models/configured/model',
                $request->url(),
            );
            $this->assertSame('Bearer config-key', $request->header('Authorization')[0] ?? null);

            return true;
        });
    }

    public function test_connection_exception_returns_empty_list(): void
    {
        // Fail open: a network-level failure (ConnectionException / transport
        // error / any Throwable) MUST NOT propagate — the driver returns [] so
        // deterministic detectors are never blocked.
        Http::fake(static function (): never {
            throw new \RuntimeException('Connection timed out');
        });

        $driver = new HuggingFaceNerDriver(apiKey: 'test-key');

        $this->assertSame([], $driver->detect(self::TEXT));
    }

    public function test_runtime_exception_on_transport_returns_empty_list(): void
    {
        // Covers non-ConnectionException transport failures (e.g. RequestException,
        // Curl\CurlException) — all Throwable sub-types are caught and converted
        // to an empty list to honour the fail-open contract.
        Http::fake(static function (): never {
            throw new \RuntimeException('SSL handshake failed');
        });

        $driver = new HuggingFaceNerDriver(apiKey: 'test-key');

        $this->assertSame([], $driver->detect(self::TEXT));
    }

    public function test_multibyte_offsets_are_byte_based(): void
    {
        // HuggingFace returns Python character offsets. The Italian name
        // "Nicolò" starts at char offset 0 but "Rossi" starts at char offset 7
        // (one char per code point). In UTF-8, "ò" is 2 bytes, so "Rossi" is
        // at byte offset 8 — Detection must store byte offsets so the engine's
        // substr_replace() operates at the right position.
        $text = 'Nicolò Rossi'; // byte layout: N(1)i(1)c(1)o(1)l(1)ò(2)space(1)R(1)...
        // "Nicolò" = 6 chars, 7 bytes. "Rossi" starts at char 7, byte 8.

        Http::fake([
            'api-inference.huggingface.co/*' => Http::response([
                [
                    'entity_group' => 'PER',
                    'score' => 0.99,
                    'word' => 'Nicolò',
                    'start' => 0, // char offset
                    'end' => 6,   // char offset
                ],
                [
                    'entity_group' => 'PER',
                    'score' => 0.95,
                    'word' => 'Rossi',
                    'start' => 7, // char offset
                    'end' => 12,  // char offset
                ],
            ], 200),
        ]);

        $driver = new HuggingFaceNerDriver(apiKey: 'test-key');
        $detections = $driver->detect($text);

        $this->assertCount(2, $detections);

        // First detection: "Nicolò" — char offset 0 → byte offset 0, byte length 7
        $this->assertSame('Nicolò', $detections[0]->value);
        $this->assertSame(0, $detections[0]->offset);
        $this->assertSame(strlen('Nicolò'), $detections[0]->length);

        // Second detection: "Rossi" — char offset 7 → byte offset 8, byte length 5
        $this->assertSame('Rossi', $detections[1]->value);
        $this->assertSame(strlen('Nicolò '), $detections[1]->offset); // "Nicolò" is 7 bytes + 1 space = 8
        $this->assertSame(strlen('Rossi'), $detections[1]->length);

        // Confirm the engine can reconstruct the matched spans using byte offsets.
        foreach ($detections as $d) {
            $this->assertSame(
                $d->value,
                substr($text, $d->offset, $d->length),
                sprintf('substr() with byte offset must recover value "%s"', $d->value),
            );
        }
    }
}
