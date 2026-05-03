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
                $detections[] = new Detection(
                    detector: $this->packName,
                    value: $value,
                    offset: (int) $m[1],
                    length: strlen($value),
                );
            }
        }

        return $detections;
    }
}
