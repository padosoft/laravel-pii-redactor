<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Admin;

use Padosoft\PiiRedactor\Admin\RedactorAdminInspector;
use Padosoft\PiiRedactor\Packs\Germany\GermanyPack;
use Padosoft\PiiRedactor\Tests\TestCase;

final class RedactorAdminInspectorTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('pii-redactor.salt', 'super-secret-salt');
        $app['config']->set('pii-redactor.audit_trail.enabled', true);
        $app['config']->set('pii-redactor.packs', [GermanyPack::class]);
    }

    public function test_snapshot_lists_enabled_strategy_detectors_and_packs(): void
    {
        $snapshot = $this->app->make(RedactorAdminInspector::class)->snapshot();

        $this->assertTrue($snapshot['enabled']);
        $this->assertSame('mask', $snapshot['default_strategy']);
        $this->assertTrue($snapshot['audit_trail_enabled']);
        $this->assertSame('memory', $snapshot['token_store']['driver']);
        $this->assertNotEmpty($snapshot['detectors']);
        $this->assertSame('germany', $snapshot['packs'][0]['name']);
        $this->assertSame('DE', $snapshot['packs'][0]['country_code']);
    }

    public function test_snapshot_never_contains_salt_or_api_keys(): void
    {
        $this->app['config']->set('pii-redactor.ner.enabled', true);
        $this->app['config']->set('pii-redactor.ner.driver', 'huggingface');
        $this->app['config']->set('pii-redactor.ner.huggingface.api_key', 'hf-secret-token');

        $snapshot = $this->app->make(RedactorAdminInspector::class)->snapshot();
        $json = (string) json_encode($snapshot);

        $this->assertStringNotContainsString('super-secret-salt', $json);
        $this->assertStringNotContainsString('hf-secret-token', $json);
        $this->assertStringNotContainsString('api_key', $json);
        $this->assertStringNotContainsString('salt', $json);
    }

    public function test_snapshot_reports_ner_configured_false_when_required_endpoint_or_key_is_missing(): void
    {
        $this->app['config']->set('pii-redactor.ner.enabled', true);
        $this->app['config']->set('pii-redactor.ner.driver', 'spacy');
        $this->app['config']->set('pii-redactor.ner.spacy.server_url', '');

        $snapshot = $this->app->make(RedactorAdminInspector::class)->snapshot();

        $this->assertTrue($snapshot['ner']['enabled']);
        $this->assertSame('spacy', $snapshot['ner']['driver']);
        $this->assertFalse($snapshot['ner']['configured']);
    }
}
