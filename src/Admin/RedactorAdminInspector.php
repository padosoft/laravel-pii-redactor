<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Admin;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Padosoft\PiiRedactor\Detectors\Detector;
use Padosoft\PiiRedactor\Packs\DetectorPackRegistry;
use Padosoft\PiiRedactor\TokenStore\CacheTokenStore;
use Padosoft\PiiRedactor\TokenStore\DatabaseTokenStore;
use Padosoft\PiiRedactor\TokenStore\InMemoryTokenStore;

final class RedactorAdminInspector
{
    public function __construct(
        private readonly Repository $config,
        private readonly Application $app,
        private readonly DetectorPackRegistry $packRegistry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $structuredAudit = (bool) $this->config->get('pii-redactor.audit_trail.enabled', false);
        $flatAudit = (bool) $this->config->get('pii-redactor.audit_trail_enabled', false);

        return [
            'enabled' => (bool) $this->config->get('pii-redactor.enabled', true),
            'default_strategy' => (string) $this->config->get('pii-redactor.strategy', 'mask'),
            'audit_trail_enabled' => $structuredAudit || $flatAudit,
            'token_store' => [
                'driver' => (string) $this->config->get('pii-redactor.token_store.driver', 'memory'),
                'class' => $this->tokenStoreClass(),
            ],
            'ner' => $this->nerSnapshot(),
            'detectors' => $this->detectorSnapshot(),
            'packs' => $this->packSnapshot(),
            'custom_rules' => [
                'auto_register' => (bool) $this->config->get('pii-redactor.custom_rules.auto_register', false),
                'configured_count' => count((array) $this->config->get('pii-redactor.custom_rules.packs', [])),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function nerSnapshot(): array
    {
        $enabled = (bool) $this->config->get('pii-redactor.ner.enabled', false);
        $driver = (string) $this->config->get('pii-redactor.ner.driver', 'stub');

        return [
            'enabled' => $enabled,
            'driver' => $driver,
            'configured' => $enabled && match ($driver) {
                'huggingface' => (string) $this->config->get('pii-redactor.ner.huggingface.api_key', '') !== '',
                'spacy' => (string) $this->config->get('pii-redactor.ner.spacy.server_url', '') !== '',
                'stub' => true,
                default => false,
            },
        ];
    }

    /**
     * @return list<array{name: string, class: class-string}>
     */
    private function detectorSnapshot(): array
    {
        $out = [];

        foreach ((array) $this->config->get('pii-redactor.detectors', []) as $detectorClass) {
            if (! is_string($detectorClass) || ! class_exists($detectorClass)) {
                continue;
            }
            $detector = $this->app->make($detectorClass);
            if (! $detector instanceof Detector) {
                continue;
            }
            $out[$detector->name()] = [
                'name' => $detector->name(),
                'class' => $detector::class,
            ];
        }

        foreach ($this->packRegistry->detectors() as $detector) {
            $out[$detector->name()] = [
                'name' => $detector->name(),
                'class' => $detector::class,
            ];
        }

        return array_values($out);
    }

    /**
     * @return list<array{name: string, country_code: string, description: string, class: class-string}>
     */
    private function packSnapshot(): array
    {
        $out = [];
        foreach ($this->packRegistry->packs() as $pack) {
            $out[] = [
                'name' => $pack->name(),
                'country_code' => $pack->countryCode(),
                'description' => $pack->description(),
                'class' => $pack::class,
            ];
        }

        return $out;
    }

    /**
     * @return class-string|string
     */
    private function tokenStoreClass(): string
    {
        return match ((string) $this->config->get('pii-redactor.token_store.driver', 'memory')) {
            'memory' => InMemoryTokenStore::class,
            'database' => DatabaseTokenStore::class,
            'cache' => CacheTokenStore::class,
            default => 'unknown',
        };
    }
}
