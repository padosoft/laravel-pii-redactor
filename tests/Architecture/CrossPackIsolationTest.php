<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Generic v1.1+ cross-pack isolation invariant — auto-discovers every
 * `src/Packs/<Country>/` directory and asserts that no pack imports
 * symbols from any sibling pack.
 *
 * This is the scaling complement to `GermanyPackIsolationTest` and
 * `SpainPackIsolationTest`: when v1.2 adds `FrancePack` we do NOT need
 * to write a new dedicated test — this single test will already gate
 * the new pack with the same invariant.
 *
 * Composability via `DetectorPackRegistry` is the only sanctioned
 * cross-pack interaction. Shared detectors (`EmailDetector`,
 * `IbanDetector`, etc.) live under `src/Detectors/` and are imported
 * from there — packs MUST NOT import each other.
 */
final class CrossPackIsolationTest extends TestCase
{
    private const PACKS_ROOT = __DIR__.'/../../src/Packs';

    public function test_no_pack_imports_any_other_pack(): void
    {
        $packsRoot = realpath(self::PACKS_ROOT);
        if ($packsRoot === false || ! is_dir($packsRoot)) {
            $this->markTestSkipped('No src/Packs directory yet.');
        }

        $packDirs = $this->discoverPackDirectories($packsRoot);

        if (count($packDirs) < 2) {
            $this->assertTrue(
                true,
                sprintf('Only %d pack(s) discovered — cross-pack isolation is trivially satisfied.', count($packDirs)),
            );

            return;
        }

        $packNames = array_map(static fn (string $p): string => basename($p), $packDirs);

        $offenders = [];

        foreach ($packDirs as $packDir) {
            $packName = basename($packDir);
            $forbiddenPacks = array_values(array_filter(
                $packNames,
                static fn (string $other): bool => $other !== $packName,
            ));

            foreach ($this->phpFilesIn($packDir) as $file) {
                $contents = (string) file_get_contents($file);
                foreach ($forbiddenPacks as $forbidden) {
                    $needle = 'Padosoft\\PiiRedactor\\Packs\\'.$forbidden.'\\';
                    if (str_contains($contents, $needle)) {
                        $offenders[] = sprintf(
                            'pack [%s] file %s imports forbidden pack [%s]',
                            $packName,
                            basename($file),
                            $forbidden,
                        );
                    }
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "Cross-pack imports detected (R37 / cross-pack isolation broken):\n%s\nShared detectors live under src/Detectors/ — import them from there, never from another Packs/<Country>/ directory.",
            implode("\n", $offenders),
        ));
    }

    /**
     * Discover every direct sub-directory of `src/Packs/` — each one is
     * a country pack. Files at the root of `src/Packs/` (e.g.
     * `PackContract.php`, `DetectorPackRegistry.php`) are NOT packs and
     * are excluded.
     *
     * @return list<string>
     */
    private function discoverPackDirectories(string $packsRoot): array
    {
        $dirs = [];
        $entries = scandir($packsRoot);
        if ($entries === false) {
            return [];
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $packsRoot.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path)) {
                $dirs[] = $path;
            }
        }

        sort($dirs);

        return $dirs;
    }

    /** @return list<string> */
    private function phpFilesIn(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        /** @var SplFileInfo $info */
        foreach ($iterator as $info) {
            if ($info->isFile() && $info->getExtension() === 'php') {
                $files[] = $info->getPathname();
            }
        }

        return $files;
    }
}
