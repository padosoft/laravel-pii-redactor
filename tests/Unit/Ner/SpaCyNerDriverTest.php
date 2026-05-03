<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Ner;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;
use Padosoft\PiiRedactor\Detectors\Detection;
use Padosoft\PiiRedactor\Exceptions\StrategyException;
use Padosoft\PiiRedactor\Ner\SpaCyNerDriver;
use Padosoft\PiiRedactor\PiiRedactorServiceProvider;

final class SpaCyNerDriverTest extends TestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PiiRedactorServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('pii-redactor.ner.spacy', [
            'server_url' => 'https://spacy.example.test/ner',
            'api_key' => null,
            'timeout' => 30,
            'entity_map' => null,
        ]);
    }

    public function test_name_returns_spacy(): void
    {
        $driver = new SpaCyNerDriver;

        $this->assertSame('spacy', $driver->name());
    }

    public function test_constructor_throws_on_empty_server_url(): void
    {
        config(['pii-redactor.ner.spacy.server_url' => '']);

        $this->expectException(StrategyException::class);
        $this->expectExceptionMessage('SpaCyNerDriver requires a non-empty server URL');

        new SpaCyNerDriver;
    }

    public function test_constructor_explicit_args_override_config(): void
    {
        // Even with an empty config server_url, the explicit constructor arg wins.
        config(['pii-redactor.ner.spacy.server_url' => '']);

        $driver = new SpaCyNerDriver(serverUrl: 'https://explicit.example/ner');

        $this->assertSame('spacy', $driver->name());
    }

    public function test_empty_input_returns_no_detections_without_http_call(): void
    {
        Http::fake();

        $driver = new SpaCyNerDriver;

        $this->assertSame([], $driver->detect(''));

        Http::assertNothingSent();
    }

    public function test_maps_a_single_person_entity(): void
    {
        Http::fake([
            'spacy.example.test/*' => Http::response([
                'entities' => [
                    [
                        'label' => 'PERSON',
                        'start_char' => 0,
                        'end_char' => 11,
                        'text' => 'Mario Rossi',
                    ],
                ],
            ], 200),
        ]);

        $driver = new SpaCyNerDriver;

        $detections = $driver->detect('Mario Rossi works in Milan.');

        $this->assertCount(1, $detections);
        /** @var Detection $first */
        $first = $detections[0];
        $this->assertInstanceOf(Detection::class, $first);
        $this->assertSame('person', $first->detector);
        $this->assertSame('Mario Rossi', $first->value);
        $this->assertSame(0, $first->offset);
        $this->assertSame(11, $first->length);
    }

    public function test_falls_back_to_substring_when_text_field_missing(): void
    {
        Http::fake([
            'spacy.example.test/*' => Http::response([
                'entities' => [
                    [
                        'label' => 'GPE',
                        'start_char' => 21,
                        'end_char' => 26,
                    ],
                ],
            ], 200),
        ]);

        $driver = new SpaCyNerDriver;

        $detections = $driver->detect('Mario Rossi works in Milan.');

        $this->assertCount(1, $detections);
        $this->assertSame('location', $detections[0]->detector);
        $this->assertSame('Milan', $detections[0]->value);
        $this->assertSame(21, $detections[0]->offset);
        $this->assertSame(5, $detections[0]->length);
    }

    public function test_returns_empty_on_http_500(): void
    {
        Http::fake([
            'spacy.example.test/*' => Http::response('boom', 500),
        ]);

        $driver = new SpaCyNerDriver;

        $this->assertSame([], $driver->detect('Mario Rossi works in Milan.'));
    }

    public function test_returns_empty_on_malformed_body_without_entities(): void
    {
        Http::fake([
            'spacy.example.test/*' => Http::response(['unexpected' => 'shape'], 200),
        ]);

        $driver = new SpaCyNerDriver;

        $this->assertSame([], $driver->detect('Mario Rossi works in Milan.'));
    }

    public function test_returns_empty_when_body_is_not_an_array(): void
    {
        Http::fake([
            'spacy.example.test/*' => Http::response('"a json string but not an array"', 200, [
                'Content-Type' => 'application/json',
            ]),
        ]);

        $driver = new SpaCyNerDriver;

        $this->assertSame([], $driver->detect('Mario Rossi works in Milan.'));
    }

    public function test_skips_unknown_label_and_keeps_known_ones(): void
    {
        Http::fake([
            'spacy.example.test/*' => Http::response([
                'entities' => [
                    [
                        'label' => 'PERSON',
                        'start_char' => 0,
                        'end_char' => 11,
                        'text' => 'Mario Rossi',
                    ],
                    // Unknown label — must be silently dropped.
                    [
                        'label' => 'PRODUCT',
                        'start_char' => 12,
                        'end_char' => 19,
                        'text' => 'iPhone7',
                    ],
                    [
                        'label' => 'ORG',
                        'start_char' => 40,
                        'end_char' => 48,
                        'text' => 'Padosoft',
                    ],
                ],
            ], 200),
        ]);

        $driver = new SpaCyNerDriver;

        $detections = $driver->detect('Mario Rossi iPhone7 lives somewhere and works at Padosoft.');

        $this->assertCount(2, $detections);
        $names = array_map(static fn (Detection $d): string => $d->detector, $detections);
        $this->assertSame(['person', 'organisation'], $names);
    }

    public function test_skips_malformed_entities_missing_required_fields(): void
    {
        Http::fake([
            'spacy.example.test/*' => Http::response([
                'entities' => [
                    ['label' => 'PERSON', 'start_char' => 0], // missing end_char
                    ['start_char' => 0, 'end_char' => 5],     // missing label
                    ['label' => 'PERSON', 'start_char' => 5, 'end_char' => 5], // zero length
                    ['label' => 'PERSON', 'start_char' => -1, 'end_char' => 3], // negative start
                    'not an array',                            // not even an array
                    [
                        'label' => 'PERSON',
                        'start_char' => 0,
                        'end_char' => 11,
                        'text' => 'Mario Rossi',
                    ],
                ],
            ], 200),
        ]);

        $driver = new SpaCyNerDriver;

        $detections = $driver->detect('Mario Rossi works in Milan.');

        $this->assertCount(1, $detections);
        $this->assertSame('person', $detections[0]->detector);
    }

    public function test_label_match_is_case_insensitive(): void
    {
        Http::fake([
            'spacy.example.test/*' => Http::response([
                'entities' => [
                    [
                        'label' => 'person',
                        'start_char' => 0,
                        'end_char' => 11,
                        'text' => 'Mario Rossi',
                    ],
                ],
            ], 200),
        ]);

        $driver = new SpaCyNerDriver;

        $detections = $driver->detect('Mario Rossi works in Milan.');

        $this->assertCount(1, $detections);
        $this->assertSame('person', $detections[0]->detector);
    }

    public function test_sends_authorization_header_when_api_key_configured(): void
    {
        config(['pii-redactor.ner.spacy.api_key' => 'test-key']);
        Http::fake([
            'spacy.example.test/*' => Http::response(['entities' => []], 200),
        ]);

        $driver = new SpaCyNerDriver;
        $driver->detect('Mario Rossi works in Milan.');

        Http::assertSent(static function ($request): bool {
            return $request->hasHeader('Authorization', 'Bearer test-key')
                && $request->method() === 'POST'
                && $request->url() === 'https://spacy.example.test/ner';
        });
    }

    public function test_omits_authorization_header_when_api_key_null(): void
    {
        config(['pii-redactor.ner.spacy.api_key' => null]);
        Http::fake([
            'spacy.example.test/*' => Http::response(['entities' => []], 200),
        ]);

        $driver = new SpaCyNerDriver;
        $driver->detect('Mario Rossi works in Milan.');

        Http::assertSent(static function ($request): bool {
            return ! $request->hasHeader('Authorization');
        });
    }

    public function test_explicit_entity_map_overrides_default(): void
    {
        Http::fake([
            'spacy.example.test/*' => Http::response([
                'entities' => [
                    [
                        'label' => 'CUSTOM',
                        'start_char' => 0,
                        'end_char' => 6,
                        'text' => 'custom',
                    ],
                    // This was a default mapping — gone after override.
                    [
                        'label' => 'PERSON',
                        'start_char' => 7,
                        'end_char' => 18,
                        'text' => 'Mario Rossi',
                    ],
                ],
            ], 200),
        ]);

        $driver = new SpaCyNerDriver(entityMap: ['CUSTOM' => 'misc']);

        $detections = $driver->detect('custom Mario Rossi.');

        $this->assertCount(1, $detections);
        $this->assertSame('misc', $detections[0]->detector);
    }

    public function test_request_payload_is_text_keyed(): void
    {
        Http::fake([
            'spacy.example.test/*' => Http::response(['entities' => []], 200),
        ]);

        $driver = new SpaCyNerDriver;
        $driver->detect('Mario Rossi works in Milan.');

        Http::assertSent(static function ($request): bool {
            $body = $request->data();

            return is_array($body)
                && isset($body['text'])
                && $body['text'] === 'Mario Rossi works in Milan.';
        });
    }

    public function test_connection_exception_returns_empty_list(): void
    {
        // Fail open: a network-level failure (timeout, DNS, connection refused)
        // MUST NOT throw — the driver must return [] so deterministic detectors
        // are not blocked.
        Http::fake(static function (): never {
            throw new ConnectionException('Connection timed out');
        });

        $driver = new SpaCyNerDriver;

        $this->assertSame([], $driver->detect('Mario Rossi works in Milan.'));
    }
}
