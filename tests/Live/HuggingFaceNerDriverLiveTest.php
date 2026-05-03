<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Live;

use Padosoft\PiiRedactor\Detectors\Detection;
use Padosoft\PiiRedactor\Ner\HuggingFaceNerDriver;
use Padosoft\PiiRedactor\Tests\TestCase;

/**
 * Live smoke test against the real HuggingFace Inference API.
 *
 * Skipped by default. Enable with both env vars set:
 *   PII_REDACTOR_LIVE=1
 *   PII_REDACTOR_HUGGINGFACE_API_KEY=hf_xxx
 *
 * Never runs in CI. The default model is multilingual; cold starts can take
 * 20+ seconds because of `wait_for_model: true` — the timeout is bumped to
 * 60s for that reason.
 */
final class HuggingFaceNerDriverLiveTest extends TestCase
{
    public function test_detects_entities_against_real_api(): void
    {
        if (getenv('PII_REDACTOR_LIVE') !== '1') {
            $this->markTestSkipped('Live tests are opt-in. Set PII_REDACTOR_LIVE=1 to enable.');
        }

        $apiKey = (string) getenv('PII_REDACTOR_HUGGINGFACE_API_KEY');
        if ($apiKey === '') {
            $this->markTestSkipped('PII_REDACTOR_HUGGINGFACE_API_KEY is required for live HuggingFace tests.');
        }

        $driver = new HuggingFaceNerDriver(
            apiKey: $apiKey,
            timeoutSeconds: 60,
        );

        $detections = $driver->detect('Mario Rossi lives in Milan and works at Padosoft.');

        $this->assertNotEmpty($detections, 'Live API returned zero detections — model may have changed.');

        foreach ($detections as $d) {
            $this->assertInstanceOf(Detection::class, $d);
        }

        $detectorNames = array_map(static fn (Detection $d): string => $d->detector, $detections);
        $this->assertContains(
            'person',
            $detectorNames,
            'Expected at least one person detection (Mario Rossi).',
        );
    }
}
