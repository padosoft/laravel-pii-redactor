<?php

declare(strict_types=1);

namespace Padosoft\PiiRedactor\TokenStore;

use Illuminate\Database\Eloquent\Builder;
use Padosoft\PiiRedactor\TokenStore\Eloquent\PiiTokenMap;

/**
 * Eloquent-backed TokenStore driver.
 *
 * Stores every token → original mapping in the `pii_token_maps` table
 * so the reverse map survives deploys, queue worker restarts, and
 * horizontal scale-out (every node hits the same row).
 *
 * The host application MUST run the package's migration before
 * registering this driver in production. The migration is published
 * under the `pii-redactor-migrations` tag from
 * `PiiRedactorServiceProvider::boot()`.
 *
 * Memory hygiene (CLAUDE.md R3): `dump()` uses `chunkById(500)` so a
 * full-table dump never materialises every row at once, and `load()`
 * batches `upsert()` in 500-row windows so a giant restore stays
 * memory-bounded.
 */
final class DatabaseTokenStore implements TokenStore
{
    public function __construct(
        private readonly ?string $connection = null,
        private readonly string $table = 'pii_token_maps',
    ) {}

    public function put(string $token, string $original): void
    {
        $this->newQuery()->updateOrCreate(
            ['token' => $token],
            [
                'original' => $original,
                'detector' => $this->parseDetector($token),
            ],
        );
    }

    public function get(string $token): ?string
    {
        $row = $this->newQuery()
            ->where('token', $token)
            ->value('original');

        return is_string($row) ? $row : null;
    }

    public function has(string $token): bool
    {
        return $this->newQuery()
            ->where('token', $token)
            ->exists();
    }

    public function clear(): void
    {
        // Operator-only path; never invoked from the hot redaction loop.
        $this->newQuery()->truncate();
    }

    /**
     * @return array<string, string>
     */
    public function dump(): array
    {
        $out = [];

        // chunkById streams the table without holding every row in
        // memory at once — see CLAUDE.md R3 (memory-safe bulk ops).
        // No explicit orderBy needed: chunkById() applies its own
        // ordering on the primary key via forPageAfterId() internally.
        $this->newQuery()
            ->chunkById(500, function ($rows) use (&$out): void {
                foreach ($rows as $row) {
                    $out[(string) $row->token] = (string) $row->original;
                }
            });

        return $out;
    }

    /**
     * @param  array<string, string>  $map
     */
    public function load(array $map): void
    {
        if ($map === []) {
            return;
        }

        $rows = [];
        foreach ($map as $token => $original) {
            $rows[] = [
                'token' => (string) $token,
                'original' => (string) $original,
                'detector' => $this->parseDetector((string) $token),
            ];
        }

        // Chunk the upsert so the generated SQL stays well below
        // any driver's max-bind / max-statement-size limit.
        foreach (array_chunk($rows, 500) as $batch) {
            $this->newQuery()->upsert(
                $batch,
                ['token'],
                ['original', 'detector'],
            );
        }
    }

    /**
     * Build a fresh Eloquent query against the configured connection + table.
     */
    private function newQuery(): Builder
    {
        $model = new PiiTokenMap;
        if ($this->connection !== null) {
            $model->setConnection($this->connection);
        }
        $model->setTable($this->table);

        return $model->newQuery();
    }

    /**
     * Parse the detector segment from `[tok:<detector>:<hex>]`. Falls
     * back to `'unknown'` so a malformed token still inserts cleanly
     * (the `detector` column is non-null) without losing the mapping.
     */
    private function parseDetector(string $token): string
    {
        if (preg_match('/^\[tok:([^:]+):[0-9a-f]+\]$/', $token, $m) === 1) {
            return $m[1];
        }

        return 'unknown';
    }
}
