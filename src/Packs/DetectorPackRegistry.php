<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Packs;

use Illuminate\Contracts\Foundation\Application;
use Padosoft\PiiRedactor\Detectors\Detector;
use Padosoft\PiiRedactor\Exceptions\PackException;

/**
 * Resolves the configured pack list into a flat detector list.
 *
 * Reads `config('pii-redactor.packs')` (a list of FQCNs implementing
 * `PackContract`) and instantiates each via the container so packs can
 * accept their own constructor dependencies. Each pack's `detectors()`
 * is concatenated into a single ordered list returned by `detectors()`.
 *
 * Invalid entries (unknown class / not implementing `PackContract`)
 * throw `PackException` at boot — fail fast rather than ship a host
 * with silently-disabled jurisdictional coverage.
 *
 * Pack order is preserved. The engine's overlap resolver applies its
 * left-most + longer-on-tie rules over the concatenated list.
 *
 * Empty / missing config returns an empty detector list — the existing
 * `pii-redactor.detectors` array still drives detector registration in
 * that case (BC).
 */
final class DetectorPackRegistry
{
    /**
     * @param  list<class-string<PackContract>|string>  $packClasses
     */
    public function __construct(
        private readonly Application $app,
        private readonly array $packClasses,
    ) {}

    /**
     * @return list<Detector>
     */
    public function detectors(): array
    {
        $out = [];
        foreach ($this->packClasses as $packClass) {
            $pack = $this->resolvePack($packClass);
            foreach ($pack->detectors() as $detector) {
                if (! $detector instanceof Detector) {
                    throw new PackException(sprintf(
                        'Pack [%s::detectors()] returned an entry that does not implement %s.',
                        is_string($packClass) ? $packClass : get_debug_type($packClass),
                        Detector::class,
                    ));
                }
                $out[] = $detector;
            }
        }

        return $out;
    }

    /**
     * @return list<PackContract>
     */
    public function packs(): array
    {
        return array_values(array_map(
            fn (string $class): PackContract => $this->resolvePack($class),
            $this->packClasses,
        ));
    }

    private function resolvePack(mixed $packClass): PackContract
    {
        if (! is_string($packClass) || $packClass === '' || ! class_exists($packClass)) {
            throw new PackException(sprintf(
                'Pack class [%s] in config[pii-redactor.packs] does not exist.',
                is_string($packClass) ? $packClass : get_debug_type($packClass),
            ));
        }

        $pack = $this->app->make($packClass);
        if (! $pack instanceof PackContract) {
            throw new PackException(sprintf(
                'Pack class [%s] in config[pii-redactor.packs] must implement %s.',
                $packClass,
                PackContract::class,
            ));
        }

        return $pack;
    }
}
