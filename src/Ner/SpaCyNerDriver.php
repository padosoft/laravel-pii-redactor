<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Ner;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Padosoft\PiiRedactor\Detectors\Detection;
use Padosoft\PiiRedactor\Exceptions\StrategyException;
use Throwable;

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

        // Fail-open contract: every recoverable error path returns []. The
        // try/catch covers ANY Throwable from the HTTP layer — Laravel's
        // HTTP client throws `ConnectionException` on transport failures
        // (timeout / DNS / TLS / refused connection) and `RequestException`
        // when `$response->throw()` is wired upstream; both descend from
        // Throwable, so a single catch block keeps the contract simple
        // and avoids PHPStan `catch.neverThrown` false positives caused by
        // the facade's untyped @throws annotation. A NER outage MUST NOT
        // block redaction of the deterministic detectors.
        try {
            $request = $this->buildRequest();
            $response = $request->post($this->serverUrl, ['text' => $text]);
        } catch (Throwable) {
            return [];
        }

        if (! $response->ok()) {
            return [];
        }

        try {
            $body = $response->json();
        } catch (Throwable) {
            return [];
        }

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
     * spaCy emits CHARACTER offsets (Python `str` indexing — Unicode
     * codepoint positions). The package's RedactorEngine, Detection, and
     * `substr` / `substr_replace` calls operate on BYTE offsets. For
     * non-ASCII UTF-8 text (Italian accents, Greek/Cyrillic, emoji), the
     * two scales diverge — `Antoñio` is 7 chars but 8 bytes. Without
     * conversion, every multibyte character before the entity shifts the
     * effective replacement window by one byte, corrupting the redacted
     * output and potentially breaking UTF-8 sequences mid-way.
     *
     * Fix: use `mb_substr` to slice the `[0, start_char)` and
     * `[0, end_char)` prefixes in CHARACTER units, then measure their byte
     * length via `strlen` to obtain the byte offset / byte end. The
     * resulting Detection offsets line up with what the engine expects.
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

        $startChar = (int) $entity['start_char'];
        $endChar = (int) $entity['end_char'];
        if ($endChar <= $startChar || $startChar < 0) {
            return null;
        }

        // Convert character offsets → byte offsets (UTF-8 aware).
        $byteOffset = strlen((string) mb_substr($text, 0, $startChar, 'UTF-8'));
        $byteEnd = strlen((string) mb_substr($text, 0, $endChar, 'UTF-8'));
        $byteLength = $byteEnd - $byteOffset;

        if ($byteLength <= 0) {
            return null;
        }

        $value = isset($entity['text']) && is_string($entity['text']) && $entity['text'] !== ''
            ? $entity['text']
            : substr($text, $byteOffset, $byteLength);

        if ($value === '') {
            return null;
        }

        return new Detection(
            detector: $this->entityMap[$label],
            value: $value,
            offset: $byteOffset,
            length: $byteLength,
        );
    }
}
