<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Ner;

use Illuminate\Support\Facades\Http;
use Padosoft\PiiRedactor\Detectors\Detection;
use Padosoft\PiiRedactor\Exceptions\StrategyException;
use Throwable;

/**
 * NER driver backed by the HuggingFace Inference API.
 *
 * The driver POSTs the input text to `/models/<model>` with the
 * `wait_for_model` option so cold models warm up transparently. Models that
 * expose an aggregation strategy return entries shaped
 * `{entity_group, score, word, start, end}` — that is the modern recommended
 * shape and what this driver consumes. Per-token output (`{entity, ...}`
 * without `entity_group`) is silently skipped because reconstructing aggregated
 * spans from BIO tags is the host model's responsibility, not this driver's.
 *
 * Failure mode: the driver MUST fail OPEN. A NER outage cannot block the
 * deterministic detectors (regex, checksum) from redacting their matches.
 * Every recoverable error path returns an empty list and lets the engine
 * proceed with whatever the other layers produced.
 *
 * Constructor binding: the package's ServiceProvider resolves the configured
 * NerDriver via `$app->make($driverClass)` without driver-specific awareness.
 * To remain compatible with that contract, every constructor parameter is
 * optional and falls back to `config('pii-redactor.ner.huggingface.*')` when
 * the container instantiates the driver with no arguments. Direct `new`
 * callers (live tests, custom hosts) keep full per-instance overrides.
 */
final class HuggingFaceNerDriver implements NerDriver
{
    private string $apiKey;

    private string $model;

    private string $baseUrl;

    private int $timeoutSeconds;

    /** @var array<string, string> */
    private array $entityMap;

    /**
     * @param  array<string, string>|null  $entityMap  HuggingFace entity_group → detector name.
     */
    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        ?string $baseUrl = null,
        ?int $timeoutSeconds = null,
        ?array $entityMap = null,
    ) {
        $config = (array) config('pii-redactor.ner.huggingface', []);

        $this->apiKey = $apiKey ?? (string) ($config['api_key'] ?? '');
        $this->model = $model ?? (string) ($config['model'] ?? 'Davlan/bert-base-multilingual-cased-ner-hrl');
        $this->baseUrl = $baseUrl ?? (string) ($config['base_url'] ?? 'https://api-inference.huggingface.co');
        $this->timeoutSeconds = $timeoutSeconds ?? (int) ($config['timeout'] ?? 30);
        $this->entityMap = $entityMap ?? [
            'PER' => 'person',
            'PERSON' => 'person',
            'ORG' => 'organisation',
            'LOC' => 'location',
            'MISC' => 'misc',
        ];

        if ($this->apiKey === '') {
            throw new StrategyException(
                'HuggingFaceNerDriver requires a non-empty API key (set PII_REDACTOR_HUGGINGFACE_API_KEY).',
            );
        }
    }

    public function name(): string
    {
        return 'huggingface';
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
        // block redaction of the deterministic detectors; logging belongs
        // to the host application.
        try {
            $response = Http::withToken($this->apiKey)
                ->timeout($this->timeoutSeconds)
                ->acceptJson()
                ->post($this->baseUrl.'/models/'.$this->model, [
                    'inputs' => $text,
                    'options' => ['wait_for_model' => true],
                ]);
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

        if (! is_array($body)) {
            return [];
        }

        $detections = [];
        foreach ($body as $entity) {
            $detection = $this->mapEntity($entity, $text);
            if ($detection !== null) {
                $detections[] = $detection;
            }
        }

        return $detections;
    }

    /**
     * Translate a single HuggingFace API row into a Detection or null when
     * the row is malformed / unmapped. Per-token output (entries with
     * `entity` instead of `entity_group`) is silently skipped — the modern
     * aggregated form is what we consume.
     *
     * HuggingFace (Python) returns Unicode character offsets; PHP's
     * substr_replace() / substr() are byte-based. We use mb_substr() to
     * extract the matched text and derive byte offsets so Detection.offset
     * and Detection.length are consistent with the byte-based engine.
     *
     * @param  mixed  $entity
     */
    private function mapEntity($entity, string $text): ?Detection
    {
        if (! is_array($entity)) {
            return null;
        }

        if (! isset($entity['entity_group'], $entity['start'], $entity['end'])) {
            return null;
        }

        $hfLabel = strtoupper((string) $entity['entity_group']);
        if (! isset($this->entityMap[$hfLabel])) {
            return null;
        }

        $charStart = (int) $entity['start'];
        $charEnd = (int) $entity['end'];
        $charLength = $charEnd - $charStart;
        if ($charLength <= 0 || $charStart < 0) {
            return null;
        }

        // HuggingFace returns character offsets (Python len()-semantics). Use
        // mb_substr() so that multibyte characters (Italian diacritics, etc.)
        // are handled correctly. Then derive byte offset/length via strlen()
        // so the Detection contract (byte offsets) is satisfied and the engine's
        // substr_replace() operates at the right position.
        $value = mb_substr($text, $charStart, $charLength, 'UTF-8');
        if ($value === '') {
            return null;
        }

        $byteOffset = strlen(mb_substr($text, 0, $charStart, 'UTF-8'));
        $byteLength = strlen($value);

        return new Detection(
            detector: $this->entityMap[$hfLabel],
            value: $value,
            offset: $byteOffset,
            length: $byteLength,
        );
    }
}
