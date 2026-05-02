<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\Console;

use Illuminate\Console\Command;
use Padosoft\PiiRedactor\RedactorEngine;

/**
 * Scans a single file or stdin and prints a JSON DetectionReport.
 *
 *   php artisan pii:scan path/to/file.txt
 *   cat data.txt | php artisan pii:scan --from=stdin
 *
 * Exit codes:
 *   0  success (with or without detections)
 *   1  argument validation failure (missing file, conflicting flags)
 *   2  IO failure (file not readable)
 */
final class PiiScanCommand extends Command
{
    protected $signature = 'pii:scan
        {path? : Path to a UTF-8 text file to scan}
        {--from= : Use "stdin" to read from STDIN instead of a file}
        {--pretty : Pretty-print the JSON output}';

    protected $description = 'Scan a file or stdin and report PII detections as JSON.';

    public function handle(RedactorEngine $engine): int
    {
        $path = $this->argument('path');
        $from = $this->option('from');

        if ($from === 'stdin' && $path !== null) {
            $this->components->error('Pass either a path argument or --from=stdin, not both.');

            return 1;
        }

        if ($from !== 'stdin' && $path === null) {
            $this->components->error('Provide a file path or use --from=stdin.');

            return 1;
        }

        $text = $this->readInput($path, $from);
        if ($text === null) {
            return 2;
        }

        $report = $engine->scan($text);
        $payload = $report->toArray();

        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if ($this->option('pretty')) {
            $flags |= JSON_PRETTY_PRINT;
        }

        $this->line((string) json_encode($payload, $flags));

        return 0;
    }

    private function readInput(?string $path, ?string $from): ?string
    {
        if ($from === 'stdin') {
            $stdin = fopen('php://stdin', 'r');
            if ($stdin === false) {
                $this->components->error('Could not open STDIN.');

                return null;
            }
            $contents = stream_get_contents($stdin);
            fclose($stdin);

            return $contents === false ? '' : $contents;
        }

        if (! is_string($path) || $path === '') {
            $this->components->error('Provide a file path or use --from=stdin.');

            return null;
        }
        if (! is_file($path) || ! is_readable($path)) {
            $this->components->error(sprintf('File not readable: %s', $path));

            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->components->error(sprintf('Failed to read file: %s', $path));

            return null;
        }

        return $contents;
    }
}
