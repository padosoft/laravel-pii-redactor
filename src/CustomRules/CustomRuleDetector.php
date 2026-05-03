<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\CustomRules;

use Padosoft\PiiRedactor\Detectors\Detection;
use Padosoft\PiiRedactor\Detectors\Detector;
use Padosoft\PiiRedactor\Exceptions\CustomRuleException;

/**
 * Detector that wraps a CustomRuleSet and emits one Detection per match.
 *
 * The pack name (e.g. `custom_it_albo`) becomes the Detector::name() value
 * for every rule in the set. This is intentional: the strategy pipeline
 * keys off the detector name (mask_token / hash / tokenise), and grouping
 * rules under a single pack keeps reporting + replacement strategy
 * uniform across all rules from the same tenant pack.
 *
 * If finer-grained detector names are needed (one strategy per rule),
 * register multiple CustomRuleDetector instances — one per single-rule
 * pack — instead.
 */
final class CustomRuleDetector implements Detector
{
    public function __construct(
        private readonly string $packName,
        private readonly CustomRuleSet $rules,
    ) {
        if ($packName === '') {
            throw new CustomRuleException('CustomRuleDetector packName must not be empty.');
        }
    }

    public function name(): string
    {
        return $this->packName;
    }

    /**
     * @return list<Detection>
     */
    public function detect(string $text): array
    {
        if ($text === '' || $this->rules->isEmpty()) {
            return [];
        }

        $detections = [];
        foreach ($this->rules->rules as $rule) {
            $compiled = $rule->compiledPattern();
            $matches = [];
            if (preg_match_all($compiled, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
                continue;
            }

            foreach ($matches[0] as $m) {
                $value = (string) $m[0];
                $length = strlen($value);
                if ($length === 0) {
                    // Zero-length matches (e.g. `a*` against "hello") would
                    // emit one empty Detection per scan position — preg_match_all
                    // bumps the cursor by 1 on a zero-width match and yields a
                    // hit at every offset, polluting the detection report.
                    // The engine's substr_replace round-trip would handle
                    // length-0 cleanly (no characters consumed) but the
                    // reports + audit trail still count them, which is the
                    // wrong contract — a tenant pack with `a*` would inflate
                    // the redaction count by strlen(text)+1 per call.
                    // Skip silently here: pathological patterns degrade to
                    // "no detection" rather than "1000 detections of nothing".
                    continue;
                }
                $detections[] = new Detection(
                    detector: $this->packName,
                    value: $value,
                    offset: (int) $m[1],
                    length: $length,
                );
            }
        }

        return $detections;
    }
}
