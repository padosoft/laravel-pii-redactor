<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Exceptions;

/**
 * Thrown when a custom-rule pack cannot be loaded or compiled.
 *
 * Surfaces three failure modes:
 *  - missing or unreadable YAML file;
 *  - malformed YAML / invalid `rules` section / missing required fields;
 *  - invalid PCRE pattern at compile time.
 *
 * Extends PiiRedactorException so callers can catch the package's umbrella
 * exception type without distinguishing between custom-rule and detector
 * failures.
 */
final class CustomRuleException extends PiiRedactorException {}
