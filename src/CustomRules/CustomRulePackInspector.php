<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\CustomRules;

use Illuminate\Contracts\Config\Repository;
use Throwable;

final class CustomRulePackInspector
{
    public function __construct(
        private readonly Repository $config,
        private readonly YamlCustomRuleLoader $loader = new YamlCustomRuleLoader,
    ) {}

    /**
     * @return list<array{
     *     name: string,
     *     path: string,
     *     exists: bool,
     *     readable: bool,
     *     rule_count: int,
     *     valid: bool,
     *     error: string|null,
     * }>
     */
    public function configuredPacks(): array
    {
        $packs = (array) $this->config->get('pii-redactor.custom_rules.packs', []);
        $out = [];

        foreach ($packs as $entry) {
            $name = is_array($entry) && isset($entry['name']) && is_string($entry['name']) ? $entry['name'] : '';
            $path = is_array($entry) && isset($entry['path']) && is_string($entry['path']) ? $entry['path'] : '';
            $exists = $path !== '' && is_file($path);
            $readable = $path !== '' && is_readable($path);
            $ruleCount = 0;
            $valid = false;
            $error = null;

            if (! is_array($entry) || $name === '' || $path === '') {
                $error = 'Pack entry must contain non-empty name and path.';
            } else {
                try {
                    $set = $this->loader->load($path);
                    $ruleCount = $set->count();
                    $valid = true;
                } catch (Throwable $e) {
                    $error = $e->getMessage();
                }
            }

            $out[] = [
                'name' => $name,
                'path' => $path,
                'exists' => $exists,
                'readable' => $readable,
                'rule_count' => $ruleCount,
                'valid' => $valid,
                'error' => $error,
            ];
        }

        return $out;
    }
}
