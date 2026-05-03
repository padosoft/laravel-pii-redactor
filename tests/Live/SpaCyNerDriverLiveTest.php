<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Live;

use Orchestra\Testbench\TestCase;
use Padosoft\PiiRedactor\Ner\SpaCyNerDriver;
use Padosoft\PiiRedactor\PiiRedactorServiceProvider;

/**
 * Opt-in live test for the SpaCyNerDriver. Requires:
 *
 *   PII_REDACTOR_LIVE=1     (master opt-in for the Live testsuite)
 *   SPACY_SERVER_URL=https://your-spacy-server.example/ner
 *   SPACY_API_KEY=...       (optional — protocol allows anonymous servers)
 *
 * See tests/Live/README.md for the full convention.
 */
final class SpaCyNerDriverLiveTest extends TestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PiiRedactorServiceProvider::class];
    }

    public function test_detects_entities_against_real_spacy_server(): void
    {
        if (getenv('PII_REDACTOR_LIVE') !== '1') {
            $this->markTestSkipped('Live tests are opt-in. Set PII_REDACTOR_LIVE=1 to enable.');
        }

        $serverUrl = getenv('SPACY_SERVER_URL');
        if ($serverUrl === false || $serverUrl === '') {
            $this->markTestSkipped('SPACY_SERVER_URL is required for live spaCy tests.');
        }

        config(['pii-redactor.ner.spacy.server_url' => (string) $serverUrl]);

        $apiKey = getenv('SPACY_API_KEY');
        if ($apiKey !== false && $apiKey !== '') {
            config(['pii-redactor.ner.spacy.api_key' => (string) $apiKey]);
        }

        $driver = new SpaCyNerDriver;

        $detections = $driver->detect('Mario Rossi lives in Milan and works at Padosoft.');

        $this->assertNotEmpty($detections, 'Expected the live spaCy server to return at least one entity.');

        $detectorNames = array_map(static fn ($d) => $d->detector, $detections);
        $this->assertContains(
            'person',
            $detectorNames,
            'Expected at least one PERSON entity in the test sentence.',
        );
    }
}
