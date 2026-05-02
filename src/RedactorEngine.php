<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor;

use Padosoft\PiiRedactor\Detectors\Detection;
use Padosoft\PiiRedactor\Detectors\Detector;
use Padosoft\PiiRedactor\Exceptions\DetectorException;
use Padosoft\PiiRedactor\Reports\DetectionReport;
use Padosoft\PiiRedactor\Strategies\RedactionStrategy;

/**
 * Core engine — orchestrates registered detectors against a strategy.
 *
 * The engine is stateful only with respect to the registered detector list
 * and the active default strategy. Calls to redact() / scan() are pure
 * functions of (text, registered detectors).
 *
 * Overlap policy: when two detectors match overlapping byte ranges, the
 * earlier (lower-offset) detection wins; ties are broken by longer match.
 * This is deterministic and predictable; callers can audit it via scan().
 */
final class RedactorEngine
{
    /**
     * @var array<string, Detector>
     */
    private array $detectors = [];

    public function __construct(
        private RedactionStrategy $strategy,
    ) {}

    public function register(Detector $detector): void
    {
        $name = $detector->name();
        if ($name === '') {
            throw new DetectorException('Detector name must not be empty.');
        }
        $this->detectors[$name] = $detector;
    }

    /**
     * Sugar for the public Facade — alias of register().
     */
    public function extend(string $alias, Detector $detector): void
    {
        if ($alias !== $detector->name()) {
            throw new DetectorException(sprintf(
                'Detector alias [%s] does not match Detector::name() [%s]. The alias is the canonical id.',
                $alias,
                $detector->name(),
            ));
        }
        $this->register($detector);
    }

    /**
     * @return array<string, Detector>
     */
    public function detectors(): array
    {
        return $this->detectors;
    }

    public function strategy(): RedactionStrategy
    {
        return $this->strategy;
    }

    public function withStrategy(RedactionStrategy $strategy): self
    {
        $clone = clone $this;
        $clone->strategy = $strategy;

        return $clone;
    }

    public function scan(string $text): DetectionReport
    {
        return new DetectionReport($this->collectDetections($text));
    }

    public function redact(string $text, ?RedactionStrategy $override = null): string
    {
        if ($text === '') {
            return $text;
        }

        $strategy = $override ?? $this->strategy;
        $detections = $this->collectDetections($text);
        if ($detections === []) {
            return $text;
        }

        // Apply replacements right-to-left so that earlier offsets remain valid.
        $output = $text;
        for ($i = count($detections) - 1; $i >= 0; $i--) {
            $d = $detections[$i];
            $replacement = $strategy->apply($d->value, $d->detector);
            $output = substr_replace($output, $replacement, $d->offset, $d->length);
        }

        return $output;
    }

    /**
     * @return list<Detection>
     */
    private function collectDetections(string $text): array
    {
        $all = [];
        foreach ($this->detectors as $detector) {
            foreach ($detector->detect($text) as $d) {
                $all[] = $d;
            }
        }

        return $this->resolveOverlaps($all);
    }

    /**
     * @param  list<Detection>  $detections
     * @return list<Detection>
     */
    private function resolveOverlaps(array $detections): array
    {
        if (count($detections) <= 1) {
            return $detections;
        }

        usort($detections, static function (Detection $a, Detection $b): int {
            if ($a->offset !== $b->offset) {
                return $a->offset <=> $b->offset;
            }

            // Tie on offset: longer match wins.
            return $b->length <=> $a->length;
        });

        $kept = [];
        $cursor = -1;
        foreach ($detections as $d) {
            if ($d->offset < $cursor) {
                continue; // overlaps with a previously-kept detection.
            }
            $kept[] = $d;
            $cursor = $d->endOffset();
        }

        return $kept;
    }
}
