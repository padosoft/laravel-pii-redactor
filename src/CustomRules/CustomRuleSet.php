<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\CustomRules;

use Padosoft\PiiRedactor\Exceptions\CustomRuleException;

/**
 * Typed, immutable collection of CustomRule entries.
 *
 * Built via the fromArray() factory which validates each row against the
 * minimal contract (`name` + `pattern` are non-empty strings; `flags`
 * defaults to `u`). PCRE compilation is deferred to CustomRule::compiledPattern()
 * so a single invalid rule cannot block the whole set from loading.
 */
final class CustomRuleSet
{
    /**
     * @param  list<CustomRule>  $rules
     */
    public function __construct(public readonly array $rules) {}

    /**
     * Build a CustomRuleSet from a YAML-decoded `rules:` list.
     *
     * @param  array<int, mixed>  $payload
     *
     * @throws CustomRuleException when a row is malformed.
     */
    public static function fromArray(array $payload): self
    {
        $rules = [];
        foreach ($payload as $i => $row) {
            if (! is_array($row)) {
                throw new CustomRuleException(sprintf(
                    'CustomRuleSet entry #%d is not an associative array.',
                    $i,
                ));
            }

            if (! isset($row['name']) || ! is_string($row['name']) || $row['name'] === '') {
                throw new CustomRuleException(sprintf(
                    'CustomRuleSet entry #%d is missing a non-empty `name` field.',
                    $i,
                ));
            }

            if (! isset($row['pattern']) || ! is_string($row['pattern']) || $row['pattern'] === '') {
                throw new CustomRuleException(sprintf(
                    'CustomRuleSet entry #%d (%s) is missing a non-empty `pattern` field.',
                    $i,
                    (string) $row['name'],
                ));
            }

            $rules[] = new CustomRule(
                name: (string) $row['name'],
                pattern: (string) $row['pattern'],
                flags: isset($row['flags']) ? (string) $row['flags'] : 'u',
            );
        }

        return new self($rules);
    }

    public function isEmpty(): bool
    {
        return $this->rules === [];
    }

    public function count(): int
    {
        return count($this->rules);
    }
}
