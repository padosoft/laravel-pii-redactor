<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Ner;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Padosoft\PiiRedactor\Detectors\Detection;
use Padosoft\PiiRedactor\Exceptions\StrategyException;

/**
 * NER driver backed by a generic spaCy HTTP server.
 *
 * The driver assumes a thin JSON wrapper in front of any spaCy model — Flask,
 * FastAPI, Starlette, even an in-house gRPC bridge — that exchanges the
 * following contract:
 *
 *   Request : POST <server_url>  body { "text": "..." }
 *   Response: { "entities": [
 *                   { "label": "PERSON", "start_char": 0, "end_char": 11, "text": "Mario Rossi" },
 *                   ...
 *               ] }
 *
 * That is exactly the shape `spacy.tokens.Doc.to_json()` emits, so any
 * spaCy server returning a serialised Doc is compatible out of the box.
 *
 * Failure mode: the driver MUST fail OPEN. A NER outage cannot block the
 * deterministic detectors (regex, checksum) from redacting their matches.
 * Every recoverable error path returns an empty list and lets the engine
 * proceed with whatever the other layers produced.
 *
 * Constructor binding: the package's ServiceProvider resolves the configured
 * NerDriver via `$app->make($driverClass)` without driver-specific awareness.
 * To remain compatible with that contract, every constructor parameter is
 * optional and falls back to `config('pii-redactor.ner.spacy.*')` when the
 * container instantiates the driver with no arguments. Direct `new` callers
 * (live tests, custom hosts) keep full per-instance overrides.
 */
final class SpaCyNerDriver implements NerDriver
{
    private string $serverUrl;

    private ?string $apiKey;

    private int $timeoutSeconds;

    /** @var array<string, string> spaCy label → our detector name. */
    private array $entityMap;

    /**
     * @param  array<string, string>|null  $entityMap  spaCy label → detector name override.
     */
    public function __construct(
        ?string $serverUrl = null,
        ?string $apiKey = null,
        ?int $timeoutSeconds = null,
        ?array $entityMap = null,
    ) {
        $config = (array) config('pii-redactor.ner.spacy', []);

        $this->serverUrl = $serverUrl ?? (string) ($config['server_url'] ?? '');

        $configApiKey = $config['api_key'] ?? null;
        $this->apiKey = $apiKey ?? (is_string($configApiKey) ? $configApiKey : null);

        $this->timeoutSeconds = $timeoutSeconds ?? (int) ($config['timeout'] ?? 30);

        $configEntityMap = $config['entity_map'] ?? null;
        $this->entityMap = $entityMap
            ?? (is_array($configEntityMap) ? $configEntityMap : [])
            ?: [
                'PERSON' => 'person',
                'PER' => 'person',
                'ORG' => 'organisation',
                'GPE' => 'location',
                'LOC' => 'location',
                'NORP' => 'group',
                'FAC' => 'facility',
            ];

        if ($this->serverUrl === '') {
            throw new StrategyException(
                'SpaCyNerDriver requires a non-empty server URL (set PII_REDACTOR_SPACY_SERVER_URL).',
            );
        }
    }

    public function name(): string
    {
        return 'spacy';
    }

    /**
     * @return list<Detection>
     */
    public function detect(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $request = $this->buildRequest();

        try {
            $response = $request->post($this->serverUrl, ['text' => $text]);
        } catch (\Throwable) {
            // Fail open: ANY transport-level failure (ConnectionException,
            // RequestException, timeout, DNS, connection reset) MUST NOT block
            // redaction of the deterministic detectors.
            return [];
        }

        if (! $response->ok()) {
            // Fail open: a NER outage MUST NOT block redaction of the
            // deterministic detectors. Logging belongs to the host
            // application — this driver returns empty and lets the engine
            // proceed with whatever the other detectors produced.
            return [];
        }

        $body = $response->json();
        if (! is_array($body) || ! isset($body['entities']) || ! is_array($body['entities'])) {
            return [];
        }

        $detections = [];
        foreach ($body['entities'] as $entity) {
            $detection = $this->mapEntity($entity, $text);
            if ($detection !== null) {
                $detections[] = $detection;
            }
        }

        return $detections;
    }

    private function buildRequest(): PendingRequest
    {
        $request = Http::timeout($this->timeoutSeconds)->acceptJson();
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $request = $request->withToken($this->apiKey);
        }

        return $request;
    }

    /**
     * Translate a single spaCy entity row into a Detection or null when the
     * row is malformed or carries an unmapped label.
     *
     * spaCy (Python) returns Unicode character offsets (`start_char` /
     * `end_char`). PHP's `substr_replace()` / `substr()` are byte-based.
     * We use `mb_substr()` to extract the matched text and derive byte
     * offsets so `Detection.offset` and `Detection.length` are consistent
     * with the byte-based engine — critical for UTF-8 Italian text that
     * contains diacritics (Nicolò, Caffè, etc.).
     *
     * @param  mixed  $entity
     */
    private function mapEntity($entity, string $text): ?Detection
    {
        if (! is_array($entity)) {
            return null;
        }

        if (! isset($entity['label'], $entity['start_char'], $entity['end_char'])) {
            return null;
        }

        $label = strtoupper((string) $entity['label']);
        if (! isset($this->entityMap[$label])) {
            return null;
        }

        $charStart = (int) $entity['start_char'];
        $charEnd = (int) $entity['end_char'];
        $charLength = $charEnd - $charStart;
        if ($charLength <= 0 || $charStart < 0) {
            return null;
        }

        // When the server includes a pre-computed `text` field use it verbatim;
        // otherwise extract via mb_substr (character-safe). Either way, derive
        // byte-based offset/length for the Detection so the engine's
        // substr_replace() operates at the correct position.
        if (isset($entity['text']) && is_string($entity['text']) && $entity['text'] !== '') {
            $value = $entity['text'];
        } else {
            $value = mb_substr($text, $charStart, $charLength, 'UTF-8');
        }

        if ($value === '') {
            return null;
        }

        $byteOffset = strlen(mb_substr($text, 0, $charStart, 'UTF-8'));
        $byteLength = strlen($value);

        return new Detection(
            detector: $this->entityMap[$label],
            value: $value,
            offset: $byteOffset,
            length: $byteLength,
        );
    }
}
