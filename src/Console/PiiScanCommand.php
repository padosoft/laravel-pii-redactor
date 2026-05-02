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
 * Sample privacy: by default the JSON output masks each per-detector
 * sample as `[<detector>]` so running `pii:scan` against real data does
 * not write raw emails / IBANs / PANs into CI logs and shell history.
 * Pass `--show-samples` when the operator explicitly wants to see the
 * raw values (interactive forensics on a trusted terminal).
 *
 * Exit codes:
 *   0  success (with or without detections)
 *   1  argument validation failure (missing path, conflicting flags)
 *   2  IO failure (file not readable, STDIN read error)
 */
final class PiiScanCommand extends Command
{
    protected $signature = 'pii:scan
        {path? : Path to a UTF-8 text file to scan}
        {--from= : Use "stdin" to read from STDIN instead of a file}
        {--pretty : Pretty-print the JSON output}
        {--show-samples : Include raw sample values in the JSON output (default: masked)}';

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

        if (! $this->option('show-samples')) {
            $payload['samples'] = $this->maskSamples($payload['samples']);
        }

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
            if ($contents === false) {
                $this->components->error('Failed to read STDIN.');

                return null;
            }

            return $contents;
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

    /**
     * Replace each raw sample value with a placeholder of the form
     * `[<detector>]` so the CLI's default JSON output never echoes
     * customer data into a shell terminal or CI log.
     *
     * @param  array<string, list<string>>  $samples
     * @return array<string, list<string>>
     */
    private function maskSamples(array $samples): array
    {
        $out = [];
        foreach ($samples as $detector => $values) {
            $placeholder = '['.$detector.']';
            $out[$detector] = array_fill(0, count($values), $placeholder);
        }

        return $out;
    }
}
