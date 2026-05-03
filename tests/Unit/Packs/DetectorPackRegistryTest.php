<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Unit\Packs;

use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase;
use Padosoft\PiiRedactor\Detectors\AddressItalianDetector;
use Padosoft\PiiRedactor\Detectors\CodiceFiscaleDetector;
use Padosoft\PiiRedactor\Detectors\Detection;
use Padosoft\PiiRedactor\Detectors\Detector;
use Padosoft\PiiRedactor\Detectors\PartitaIvaDetector;
use Padosoft\PiiRedactor\Detectors\PhoneItalianDetector;
use Padosoft\PiiRedactor\Exceptions\PackException;
use Padosoft\PiiRedactor\Packs\DetectorPackRegistry;
use Padosoft\PiiRedactor\Packs\Italy\ItalyPack;
use Padosoft\PiiRedactor\Packs\PackContract;
use Padosoft\PiiRedactor\PiiRedactorServiceProvider;

/**
 * Verifies that DetectorPackRegistry walks `config('pii-redactor.packs')`,
 * instantiates each FQCN via the container, validates the contract, and
 * concatenates the per-pack detectors in declaration order.
 *
 * The registry is the seam that the v1.0 SP uses to register pack-aggregated
 * detectors on top of the legacy flat list — misconfiguration must surface
 * as PackException at boot, never silently disable jurisdictional coverage.
 */
final class DetectorPackRegistryTest extends TestCase
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
        // Force a deterministic strategy + salt so the engine never trips
        // on the salt requirement when child tests resolve dependencies.
        $app['config']->set('pii-redactor.strategy', 'mask');
        $app['config']->set('pii-redactor.salt', 'test-salt-do-not-use-in-prod');
    }

    public function test_empty_pack_list_yields_empty_detector_list(): void
    {
        $registry = new DetectorPackRegistry($this->app, []);

        $this->assertSame([], $registry->detectors());
        $this->assertSame([], $registry->packs());
    }

    public function test_single_italy_pack_returns_four_italian_detectors(): void
    {
        $registry = new DetectorPackRegistry($this->app, [ItalyPack::class]);

        $detectors = $registry->detectors();

        $this->assertCount(4, $detectors);
        $this->assertInstanceOf(CodiceFiscaleDetector::class, $detectors[0]);
        $this->assertInstanceOf(PartitaIvaDetector::class, $detectors[1]);
        $this->assertInstanceOf(PhoneItalianDetector::class, $detectors[2]);
        $this->assertInstanceOf(AddressItalianDetector::class, $detectors[3]);

        $packs = $registry->packs();
        $this->assertCount(1, $packs);
        $this->assertInstanceOf(ItalyPack::class, $packs[0]);
        $this->assertSame('italy', $packs[0]->name());
        $this->assertSame('IT', $packs[0]->countryCode());
    }

    public function test_multiple_packs_concatenate_detectors_in_declaration_order(): void
    {
        $registry = new DetectorPackRegistry($this->app, [
            DetectorPackRegistryTestStubFakePack::class,
            ItalyPack::class,
        ]);

        $detectors = $registry->detectors();

        // Stub pack ships ONE detector ('stub_pack'), Italy ships FOUR.
        $this->assertCount(5, $detectors);
        $this->assertSame('stub_pack', $detectors[0]->name());
        $this->assertInstanceOf(CodiceFiscaleDetector::class, $detectors[1]);
        $this->assertInstanceOf(PartitaIvaDetector::class, $detectors[2]);
        $this->assertInstanceOf(PhoneItalianDetector::class, $detectors[3]);
        $this->assertInstanceOf(AddressItalianDetector::class, $detectors[4]);
    }

    public function test_unknown_class_fqcn_throws_pack_exception(): void
    {
        $registry = new DetectorPackRegistry($this->app, [
            'Padosoft\\PiiRedactor\\Packs\\Atlantis\\AtlantisPack',
        ]);

        $this->expectException(PackException::class);
        $this->expectExceptionMessage('does not exist');

        $registry->detectors();
    }

    public function test_class_not_implementing_pack_contract_throws_pack_exception(): void
    {
        $registry = new DetectorPackRegistry($this->app, [\stdClass::class]);

        $this->expectException(PackException::class);
        $this->expectExceptionMessage('must implement');

        $registry->detectors();
    }

    public function test_packs_method_resolves_one_to_one_with_config(): void
    {
        $registry = new DetectorPackRegistry($this->app, [
            ItalyPack::class,
            DetectorPackRegistryTestStubFakePack::class,
        ]);

        $packs = $registry->packs();

        $this->assertCount(2, $packs);
        $this->assertInstanceOf(ItalyPack::class, $packs[0]);
        $this->assertInstanceOf(DetectorPackRegistryTestStubFakePack::class, $packs[1]);
    }

    public function test_pack_returning_non_detector_entry_throws_pack_exception(): void
    {
        $registry = new DetectorPackRegistry($this->app, [
            DetectorPackRegistryTestBadPack::class,
        ]);

        $this->expectException(PackException::class);
        $this->expectExceptionMessage('does not implement');

        $registry->detectors();
    }

    public function test_empty_string_entry_throws_pack_exception(): void
    {
        $registry = new DetectorPackRegistry($this->app, ['']);

        $this->expectException(PackException::class);
        $this->expectExceptionMessage('does not exist');

        $registry->detectors();
    }
}

/**
 * Test-only pack with a single fake detector. Defined in the same file so
 * the autoloader picks it up via the test classmap without needing a
 * dedicated fixtures namespace.
 */
final class DetectorPackRegistryTestStubFakePack implements PackContract
{
    public function name(): string
    {
        return 'stub_pack';
    }

    public function countryCode(): string
    {
        return 'XX';
    }

    public function description(): string
    {
        return 'Test-only stub pack — registers a single fake detector.';
    }

    public function detectors(): array
    {
        return [new DetectorPackRegistryTestStubFakeDetector];
    }
}

final class DetectorPackRegistryTestStubFakeDetector implements Detector
{
    public function name(): string
    {
        return 'stub_pack';
    }

    public function detect(string $text): array
    {
        if ($text === '') {
            return [];
        }

        return [new Detection('stub_pack', 'stub', 0, 4)];
    }
}

/**
 * A pack whose detectors() lies about returning Detector instances. The
 * registry must catch this and surface PackException rather than crash
 * later when the engine tries to register the bogus entry.
 *
 * @phpstan-ignore-next-line
 */
final class DetectorPackRegistryTestBadPack implements PackContract
{
    public function name(): string
    {
        return 'bad_pack';
    }

    public function countryCode(): string
    {
        return 'XX';
    }

    public function description(): string
    {
        return 'Test-only pack returning a non-Detector entry.';
    }

    public function detectors(): array
    {
        // @phpstan-ignore-next-line — intentional contract violation for test.
        return [new \stdClass];
    }
}
