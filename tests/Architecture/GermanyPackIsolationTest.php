<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Enforces the v1.0+ cross-pack isolation invariant for `GermanyPack`.
 *
 * Every country pack MUST be self-contained:
 *
 * 1. Zero references to other country packs (`Italy\*`, `Spain\*`,
 *    future `France\*` / `Netherlands\*` / `Portugal\*` / `Iceland\*`).
 *    Composability via the `DetectorPackRegistry` is the only sanctioned
 *    cross-pack interaction — the packs themselves do NOT know about
 *    each other.
 * 2. Zero references to AskMyDocs / sister Padosoft packages — the
 *    R37 standalone-agnostic invariant inherited from the package level
 *    (mirrors `StandaloneAgnosticTest`).
 *
 * A pack that violates either property breaks the substitution principle
 * that makes packs interchangeable: adding a detector to `GermanyPack`
 * MUST NOT silently pull a Spanish or Italian detector class into the
 * loaded image. Shared detectors live under `src/Detectors/` and are
 * imported from there.
 *
 * The test is a no-op (passes trivially) until the GermanyPack source
 * directory exists — `feature/v1.1` agents create it in parallel.
 */
final class GermanyPackIsolationTest extends TestCase
{
    private const PACK_DIR = __DIR__.'/../../src/Packs/Germany';

    /**
     * @var list<string>
     */
    private const FORBIDDEN_PACK_NAMESPACES = [
        'Padosoft\\PiiRedactor\\Packs\\Italy\\',
        'Padosoft\\PiiRedactor\\Packs\\Spain\\',
        'Padosoft\\PiiRedactor\\Packs\\France\\',
        'Padosoft\\PiiRedactor\\Packs\\Netherlands\\',
        'Padosoft\\PiiRedactor\\Packs\\Portugal\\',
        'Padosoft\\PiiRedactor\\Packs\\Iceland\\',
    ];

    /**
     * @var list<string>
     */
    private const FORBIDDEN_SISTER_SYMBOLS = [
        // AskMyDocs / KB symbols.
        'KnowledgeDocument',
        'KbSearchService',
        'knowledge_documents',
        'knowledge_chunks',
        'kb_nodes',
        'kb_edges',
        'kb_canonical_audit',
        // Repo references.
        'lopadova/askmydocs',
        'lopadova\\AskMyDocs',
        'padosoft/askmydocs-pro',
        'padosoft/laravel-patent-box-tracker',
        'padosoft/laravel-flow',
        'padosoft/eval-harness',
        'padosoft/laravel-ai-regolo',
        // Sister-package class hints.
        'PatentBoxTracker',
        'AskMyDocs',
        'LaravelFlow',
        'EvalHarness',
        'Regolo',
    ];

    /** @return list<string> */
    private function packFiles(): array
    {
        if (! is_dir(self::PACK_DIR)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(self::PACK_DIR, RecursiveDirectoryIterator::SKIP_DOTS),
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

    public function test_germany_pack_does_not_import_other_country_packs(): void
    {
        $files = $this->packFiles();

        if ($files === []) {
            $this->assertTrue(true, 'GermanyPack source directory not present yet — invariant is trivially satisfied.');

            return;
        }

        $offenders = [];

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            foreach (self::FORBIDDEN_PACK_NAMESPACES as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = sprintf('%s -> %s', basename($file), $needle);
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "GermanyPack imports another country pack (cross-pack isolation broken):\n%s\nShared detectors live under src/Detectors/ — import them from there, never from another Packs/<Country>/ directory.",
            implode("\n", $offenders),
        ));
    }

    public function test_germany_pack_does_not_reference_sister_package_symbols(): void
    {
        $files = $this->packFiles();

        if ($files === []) {
            $this->assertTrue(true, 'GermanyPack source directory not present yet — invariant is trivially satisfied.');

            return;
        }

        $offenders = [];

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);
            foreach (self::FORBIDDEN_SISTER_SYMBOLS as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = sprintf('%s -> %s', basename($file), $needle);
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "GermanyPack violates standalone-agnostic invariant (R37):\n%s\nlaravel-pii-redactor MUST stay standalone — no AskMyDocs / sister-package leakage even inside a country pack.",
            implode("\n", $offenders),
        ));
    }
}
