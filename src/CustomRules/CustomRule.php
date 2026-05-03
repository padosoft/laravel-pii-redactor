<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\CustomRules;

use Padosoft\PiiRedactor\Exceptions\CustomRuleException;

/**
 * Immutable value object representing one tenant-defined PII rule.
 *
 * A rule is the (name, PCRE pattern, flags) triple persisted in a YAML pack.
 * The pattern is stored without delimiters; compiledPattern() wraps it in
 * `/<pattern>/<flags>` and validates the result via a no-op preg_match.
 * Validation is lazy: invalid patterns surface only at first scan, never at
 * construction — so a malformed rule at the bottom of a pack does not poison
 * loading of the rest.
 */
final class CustomRule
{
    public function __construct(
        public readonly string $name,
        public readonly string $pattern,
        public readonly string $flags = 'u',
    ) {}

    /**
     * Build the full PCRE delimited form: `/<pattern>/<flags>`.
     *
     * Forward slashes inside the pattern are escaped so the chosen `/`
     * delimiter never collides. Throws when the resulting pattern is not
     * a valid PCRE regex (preg_match returns false on bad pattern).
     */
    public function compiledPattern(): string
    {
        $delimited = '/'.str_replace('/', '\\/', $this->pattern).'/'.$this->flags;

        if (@preg_match($delimited, '') === false) {
            throw new CustomRuleException(sprintf(
                'CustomRule [%s] has invalid PCRE pattern: %s',
                $this->name,
                $delimited,
            ));
        }

        return $delimited;
    }
}
