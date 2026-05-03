<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Ner;

use Padosoft\PiiRedactor\Detectors\Detection;

/**
 * Pluggable named-entity recognition driver. v0.2 ships only the StubNerDriver.
 * v0.3 adds HuggingFaceNerDriver + SpaCyNerDriver behind opt-in env flags.
 *
 * Drivers return Detection objects shaped exactly like first-party detector
 * output; the engine merges them into the same overlap-resolution pipeline.
 */
interface NerDriver
{
    public function name(): string;

    /**
     * @return list<Detection>
     */
    public function detect(string $text): array;
}
