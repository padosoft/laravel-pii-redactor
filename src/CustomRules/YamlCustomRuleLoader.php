<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\CustomRules;

use Padosoft\PiiRedactor\Exceptions\CustomRuleException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Loads + validates a YAML file describing a custom-rule pack.
 *
 * Expected file shape:
 *
 *     rules:
 *       - name: iscrizione_albo
 *         pattern: 'ISCR-\d{6,}'
 *         flags: u
 *       - name: tessera_ordine
 *         pattern: '\bTess-[A-Z]{2}-\d{4,8}\b'
 *
 * Failure modes (all surface as CustomRuleException):
 *  - the path does not exist or is unreadable;
 *  - the file is malformed YAML;
 *  - the top-level `rules` key is present but not a list;
 *  - a rule entry lacks `name` or `pattern` (delegated to CustomRuleSet::fromArray()).
 *
 * An empty file (or one without a `rules:` key) yields an empty set — this
 * is intentional so a host can ship a stub YAML file in production before
 * any rules are authored.
 */
final class YamlCustomRuleLoader
{
    public function load(string $path): CustomRuleSet
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new CustomRuleException("Custom rule YAML file not found or unreadable: {$path}");
        }

        try {
            $parsed = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new CustomRuleException(
                "Custom rule YAML at {$path} is malformed: ".$e->getMessage(),
                previous: $e,
            );
        }

        if ($parsed === null || ! is_array($parsed)) {
            return new CustomRuleSet([]);
        }

        $rulesSection = $parsed['rules'] ?? [];
        if (! is_array($rulesSection)) {
            throw new CustomRuleException(
                "Custom rule YAML at {$path} has invalid `rules` section (must be a list).",
            );
        }

        return CustomRuleSet::fromArray($rulesSection);
    }
}
