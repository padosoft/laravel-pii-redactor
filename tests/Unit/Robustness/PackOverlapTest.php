<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Robustness;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;
use Padosoft\PiiRedactor\Detectors\CodiceFiscaleDetector;
use Padosoft\PiiRedactor\Detectors\CreditCardDetector;
use Padosoft\PiiRedactor\Detectors\Detection;
use Padosoft\PiiRedactor\Detectors\Detector;
use Padosoft\PiiRedactor\Detectors\EmailDetector;
use Padosoft\PiiRedactor\Detectors\IbanDetector;
use Padosoft\PiiRedactor\Packs\DetectorPackRegistry;
use Padosoft\PiiRedactor\Packs\Italy\ItalyPack;
use Padosoft\PiiRedactor\Packs\PackContract;
use Padosoft\PiiRedactor\PiiRedactorServiceProvider;
use Padosoft\PiiRedactor\RedactorEngine;
use Padosoft\PiiRedactor\Strategies\MaskStrategy;

/**
 * Robustness tests covering pack-architecture interactions:
 *
 *  - A pack-registered detector colliding by name with a free-floating
 *    detector — the engine's `register()` is last-write-wins, so the
 *    explicitly-registered free-floating detector overrides the pack
 *    entry. Document the contract.
 *  - Empty pack list — only multi-country detectors (Email, IBAN,
 *    CreditCard) and any host-wired free-floating detectors run.
 *  - Two packs returning detectors with the same `name()` — registry
 *    concatenation does NOT dedupe; the engine's last-write-wins
 *    `register()` resolves the collision deterministically.
 *
 * These pin the layered defaults so a future refactor either preserves
 * them or changes them deliberately with an updated assertion.
 */
final class PackOverlapTest extends TestCase
{
    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [PiiRedactorServiceProvider::class];
    }

    /**
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('pii-redactor.strategy', 'mask');
        $app['config']->set('pii-redactor.salt', 'test-salt-do-not-use-in-prod');
    }

    /**
     * Catches: a regression where the engine silently dedupes detectors
     * by name (or, conversely, allows two same-name detectors to both
     * fire). The contract is "named registry, last write wins" — both
     * `register()` and `extend()` overwrite the previous binding for
     * the same name. A free-floating override of a pack detector keeps
     * the override behaviour.
     */
    public function test_free_floating_detector_overrides_same_named_pack_detector(): void
    {
        $engine = new RedactorEngine(new MaskStrategy('[X]'));

        // First: register the standard pack detectors (Italy includes
        // codice_fiscale).
        $registry = new DetectorPackRegistry($this->app, [ItalyPack::class]);
        foreach ($registry->detectors() as $detector) {
            $engine->register($detector);
        }
        $this->assertInstanceOf(CodiceFiscaleDetector::class, $engine->detectors()['codice_fiscale']);

        // Then: register a free-floating detector that takes the same
        // name. The named registry overwrites silently — that is the
        // documented "last write wins" contract.
        $override = new PackOverlapTestStubCodiceFiscaleDetector;
        $engine->register($override);

        $this->assertSame($override, $engine->detectors()['codice_fiscale']);

        // Behaviour proof: the override now drives detect() output. The
        // stub matches the literal "OVERRIDDEN" — the real
        // CodiceFiscaleDetector with its CIN checksum would never accept
        // that input.
        $report = $engine->scan('User OVERRIDDEN reported.');
        $this->assertSame(1, $report->total());
        $detections = $report->detections();
        $this->assertSame('codice_fiscale', $detections[0]->detector);
        $this->assertSame('OVERRIDDEN', $detections[0]->value);
    }

    /**
     * Catches: a regression where the engine's pack registration loop
     * silently fails when no packs are configured (e.g. throws
     * PackException on empty list, or refuses to boot). The contract is:
     * `pii-redactor.packs => []` is a valid configuration that yields
     * an engine with ONLY the free-floating detectors registered via
     * `pii-redactor.detectors` (multi-country) — no Italian detectors.
     */
    public function test_engine_with_no_packs_registers_only_multi_country_detectors(): void
    {
        $registry = new DetectorPackRegistry($this->app, []);
        $detectors = $registry->detectors();

        $this->assertSame([], $detectors);

        // Sanity: the multi-country detector list lives in the legacy
        // flat config key, NOT in any pack — so a host with zero packs
        // still has email/IBAN/credit-card coverage via that list. Pin
        // that the registry contract is purely additive (does NOT touch
        // the flat list).
        $flatDetectors = (array) $this->app['config']->get('pii-redactor.detectors', []);
        $this->assertContains(EmailDetector::class, $flatDetectors);
        $this->assertContains(IbanDetector::class, $flatDetectors);
        $this->assertContains(CreditCardDetector::class, $flatDetectors);

        // End-to-end regression-gate: resolve the engine with packs=[] and
        // confirm the Italian detectors are NOT registered. Concrete proof
        // that removing ItalyPack from the packs array actually disables
        // Italian-specific detection — the v1.0 promise the README makes.
        $this->app['config']->set('pii-redactor.packs', []);
        $this->app->forgetInstance(DetectorPackRegistry::class);
        $this->app->forgetInstance(RedactorEngine::class);

        /** @var RedactorEngine $engine */
        $engine = $this->app->make(RedactorEngine::class);
        $registered = $engine->detectors();

        $this->assertArrayHasKey('email', $registered);
        $this->assertArrayHasKey('iban', $registered);
        $this->assertArrayHasKey('credit_card', $registered);
        $this->assertArrayNotHasKey('codice_fiscale', $registered, 'ItalyPack disabled but codice_fiscale still leaked into the engine.');
        $this->assertArrayNotHasKey('p_iva', $registered);
        $this->assertArrayNotHasKey('phone_it', $registered);
        $this->assertArrayNotHasKey('address_it', $registered);
    }

    /**
     * Catches: a regression where the registry rejects two packs whose
     * detector lists carry the same name(). Pin the actual behaviour:
     * the registry concatenates detectors WITHOUT deduplication — the
     * engine's `register()` is then responsible for the last-write-wins
     * collision resolution. This split is intentional: registry stays
     * dumb (no policy), engine carries the policy.
     */
    public function test_two_packs_with_same_named_detector_engine_keeps_last_registered(): void
    {
        $registry = new DetectorPackRegistry($this->app, [
            PackOverlapTestStubPackA::class,
            PackOverlapTestStubPackB::class,
        ]);

        $detectors = $registry->detectors();

        // Both packs ship a detector named `clashing_detector`. The
        // registry keeps both in the returned list (count = 2) — it
        // does NOT collapse them.
        $this->assertCount(2, $detectors);
        $this->assertSame('clashing_detector', $detectors[0]->name());
        $this->assertSame('clashing_detector', $detectors[1]->name());

        // The engine's register() collapses by name — the SECOND pack's
        // detector wins because it was registered last.
        $engine = new RedactorEngine(new MaskStrategy('[X]'));
        foreach ($detectors as $detector) {
            $engine->register($detector);
        }

        // Single entry survives; the value points at PackB's instance.
        $registered = $engine->detectors();
        $this->assertCount(1, $registered);
        $this->assertInstanceOf(PackOverlapTestStubDetectorB::class, $registered['clashing_detector']);
    }
}

/**
 * Stub detector used only by the override test — answers "OVERRIDDEN"
 * regardless of input so we can prove which instance ran.
 */
final class PackOverlapTestStubCodiceFiscaleDetector implements Detector
{
    public function name(): string
    {
        return 'codice_fiscale';
    }

    public function detect(string $text): array
    {
        $offset = strpos($text, 'OVERRIDDEN');
        if ($offset === false) {
            return [];
        }

        return [new Detection('codice_fiscale', 'OVERRIDDEN', $offset, strlen('OVERRIDDEN'))];
    }
}

/**
 * Pack A — ships a single detector named `clashing_detector` whose
 * detect() emits a sentinel value.
 */
final class PackOverlapTestStubPackA implements PackContract
{
    public function name(): string
    {
        return 'pack_a';
    }

    public function countryCode(): string
    {
        return 'XX';
    }

    public function description(): string
    {
        return 'Test-only pack A — emits SENTINEL_A.';
    }

    public function detectors(): array
    {
        return [new PackOverlapTestStubDetectorA];
    }
}

/**
 * Pack B — same `clashing_detector` name, different sentinel.
 */
final class PackOverlapTestStubPackB implements PackContract
{
    public function name(): string
    {
        return 'pack_b';
    }

    public function countryCode(): string
    {
        return 'XX';
    }

    public function description(): string
    {
        return 'Test-only pack B — emits SENTINEL_B.';
    }

    public function detectors(): array
    {
        return [new PackOverlapTestStubDetectorB];
    }
}

final class PackOverlapTestStubDetectorA implements Detector
{
    public function name(): string
    {
        return 'clashing_detector';
    }

    public function detect(string $text): array
    {
        return [];
    }
}

final class PackOverlapTestStubDetectorB implements Detector
{
    public function name(): string
    {
        return 'clashing_detector';
    }

    public function detect(string $text): array
    {
        return [];
    }
}
