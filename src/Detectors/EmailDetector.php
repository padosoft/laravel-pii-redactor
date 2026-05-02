<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Detectors;

/**
 * Detects RFC-5321-shaped email addresses.
 *
 * The pattern is intentionally pragmatic, not a full RFC parser: the local
 * part allows letters, digits, dot, hyphen, plus, underscore; the domain is
 * a label.label sequence with a 2..63-char TLD. False positives at the
 * boundary are accepted in exchange for predictable behaviour.
 */
final class EmailDetector implements Detector
{
    private const PATTERN = '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,63}/i';

    public function name(): string
    {
        return 'email';
    }

    public function detect(string $text): array
    {
        if ($text === '') {
            return [];
        }

        $matches = [];
        if (preg_match_all(self::PATTERN, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        $detections = [];
        foreach ($matches[0] as $match) {
            $value = (string) $match[0];
            $detections[] = new Detection(
                detector: $this->name(),
                value: $value,
                offset: (int) $match[1],
                length: strlen($value),
            );
        }

        return $detections;
    }
}
