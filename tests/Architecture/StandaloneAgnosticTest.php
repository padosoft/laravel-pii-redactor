<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Production code under `src/` MUST stay independent of every sister
 * package and host application. Standalone-agnostic invariant.
 */
final class StandaloneAgnosticTest extends TestCase
{
    /**
     * @var list<string>
     */
    private const FORBIDDEN_SUBSTRINGS = [
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

    public function test_src_directory_contains_no_forbidden_substring(): void
    {
        $srcRoot = realpath(__DIR__.'/../../src');
        $this->assertNotFalse($srcRoot, 'src/ directory must exist');

        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($srcRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        $offenders = [];

        foreach ($iter as $file) {
            if (! $file->isFile()) {
                continue;
            }
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            foreach (self::FORBIDDEN_SUBSTRINGS as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = sprintf('%s -> %s', $file->getPathname(), $needle);
                }
            }
        }

        $this->assertSame([], $offenders, sprintf(
            "Forbidden substrings found in src/:\n%s\nlaravel-pii-redactor MUST stay standalone-agnostic — no AskMyDocs / sister-package leakage.",
            implode("\n", $offenders),
        ));
    }

    public function test_composer_json_does_not_require_a_sister_package(): void
    {
        $composer = json_decode(
            (string) file_get_contents(__DIR__.'/../../composer.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertIsArray($composer);

        $require = $composer['require'] ?? [];
        $this->assertIsArray($require);

        $forbidden = [
            'lopadova/askmydocs',
            'padosoft/askmydocs-pro',
            'padosoft/laravel-patent-box-tracker',
            'padosoft/laravel-flow',
            'padosoft/eval-harness',
            'padosoft/laravel-ai-regolo',
        ];

        foreach ($forbidden as $dep) {
            $this->assertArrayNotHasKey(
                $dep,
                $require,
                sprintf('laravel-pii-redactor must not require [%s] in production deps.', $dep),
            );
        }
    }
}
