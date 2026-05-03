<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Ner;

/**
 * No-op NER driver. Default. Returns no detections — used to keep the v0.2
 * surface stable while real drivers (HuggingFace + spaCy) develop in v0.3.
 *
 * Selecting "stub" via PII_REDACTOR_NER_DRIVER preserves v0.1 behavior
 * (regex + checksum only). Real drivers in v0.3 will plug into the same
 * interface without touching the engine or the public facade.
 */
final class StubNerDriver implements NerDriver
{
    public function name(): string
    {
        return 'stub';
    }

    public function detect(string $text): array
    {
        return [];
    }
}
