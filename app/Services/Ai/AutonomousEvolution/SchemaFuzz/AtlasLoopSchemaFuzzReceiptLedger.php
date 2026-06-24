<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SchemaFuzz;

use RuntimeException;

final class AtlasLoopSchemaFuzzReceiptLedger
{
    public function __construct(
        private readonly string $basePath = '',
    ) {}

    /**
     * @param  array{
     *   run_id:string,
     *   seed:int|string,
     *   generator_version:string,
     *   reporter_version:string,
     *   schema_versions:list<string>,
     *   started_at_utc:string,
     *   finished_at_utc:string
     * }  $run
     * @param  list<array<string,mixed>>  $rows
     * @return array{
     *   run_id:string,
     *   seed:string,
     *   generator_version:string,
     *   reporter_version:string,
     *   schema_versions:list<string>,
     *   payload_count:int,
     *   started_at_utc:string,
     *   finished_at_utc:string,
     *   per_row_digest_root:string,
     *   raw_rows_path:string
     * }
     */
    public function append(array $run, array $rows): array
    {
        $receipt = $this->normalizeReceipt($run, $rows);
        if ($this->queryByRunId($receipt['run_id']) !== null) {
            throw new RuntimeException('append_only_violation_run_id_exists');
        }

        $this->ensureDirectory($this->rowsDirectory());
        $this->ensureDirectory(dirname($this->ledgerPath()));

        $rowLines = $this->canonicalRowLines($rows);
        $receipt['per_row_digest_root'] = $this->merkleRoot($this->rowDigests($rows));
        $receipt['raw_rows_path'] = $this->rawRowsPath($receipt['run_id'], $receipt['per_row_digest_root']);

        if (is_file($receipt['raw_rows_path'])) {
            throw new RuntimeException('append_only_violation_raw_rows_exists');
        }

        foreach ($rowLines as $line) {
            file_put_contents($receipt['raw_rows_path'], $line."\n", FILE_APPEND | LOCK_EX);
        }

        file_put_contents(
            $this->ledgerPath(),
            $this->canonicalJson($receipt)."\n",
            FILE_APPEND | LOCK_EX,
        );

        return $receipt;
    }

    /**
     * @return array{
     *   run_id:string,
     *   seed:string,
     *   generator_version:string,
     *   reporter_version:string,
     *   schema_versions:list<string>,
     *   payload_count:int,
     *   started_at_utc:string,
     *   finished_at_utc:string,
     *   per_row_digest_root:string,
     *   raw_rows_path:string
     * }|null
     */
    public function queryByRunId(string $runId): ?array
    {
        foreach ($this->readReceipts() as $receipt) {
            if ($receipt['run_id'] === trim($runId)) {
                return $receipt;
            }
        }

        return null;
    }

    /**
     * @return list<array{
     *   run_id:string,
     *   seed:string,
     *   generator_version:string,
     *   reporter_version:string,
     *   schema_versions:list<string>,
     *   payload_count:int,
     *   started_at_utc:string,
     *   finished_at_utc:string,
     *   per_row_digest_root:string,
     *   raw_rows_path:string
     * }>
     */
    public function queryBySeed(string $seed): array
    {
        return array_values(array_filter(
            $this->readReceipts(),
            static fn (array $receipt): bool => $receipt['seed'] === trim($seed),
        ));
    }

    public function verifyRun(string $runId): bool
    {
        $receipt = $this->queryByRunId($runId);
        if ($receipt === null) {
            return false;
        }

        $recomputed = $this->recomputeDigestForPath($receipt['raw_rows_path']);

        return $recomputed !== null && hash_equals($receipt['per_row_digest_root'], $recomputed);
    }

    public function recomputePerRowDigestRoot(string $runId): ?string
    {
        $receipt = $this->queryByRunId($runId);

        return $receipt === null ? null : $this->recomputeDigestForPath($receipt['raw_rows_path']);
    }

    /**
     * @param  array{
     *   run_id:string,
     *   seed:int|string,
     *   generator_version:string,
     *   reporter_version:string,
     *   schema_versions:list<string>,
     *   started_at_utc:string,
     *   finished_at_utc:string
     * }  $run
     * @param  list<array<string,mixed>>  $rows
     * @return array{
     *   run_id:string,
     *   seed:string,
     *   generator_version:string,
     *   reporter_version:string,
     *   schema_versions:list<string>,
     *   payload_count:int,
     *   started_at_utc:string,
     *   finished_at_utc:string,
     *   per_row_digest_root:string,
     *   raw_rows_path:string
     * }
     */
    private function normalizeReceipt(array $run, array $rows): array
    {
        $schemaVersions = array_values(array_filter(
            is_array($run['schema_versions'] ?? null) ? $run['schema_versions'] : [],
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));
        sort($schemaVersions);

        return [
            'run_id' => trim((string) ($run['run_id'] ?? '')),
            'seed' => trim((string) ($run['seed'] ?? '')),
            'generator_version' => trim((string) ($run['generator_version'] ?? '')),
            'reporter_version' => trim((string) ($run['reporter_version'] ?? '')),
            'schema_versions' => $schemaVersions,
            'payload_count' => count($this->sortedRows($rows)),
            'started_at_utc' => trim((string) ($run['started_at_utc'] ?? '')),
            'finished_at_utc' => trim((string) ($run['finished_at_utc'] ?? '')),
            'per_row_digest_root' => '',
            'raw_rows_path' => '',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function sortedRows(array $rows): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $payloadId = trim((string) ($row['payload_id'] ?? ''));
            $schemaName = trim((string) ($row['schema_name'] ?? ''));
            if ($payloadId === '' || $schemaName === '') {
                continue;
            }

            $normalized[] = $this->sortRecursive($row);
        }

        usort($normalized, static function (array $left, array $right): int {
            $payloadOrder = strcmp((string) ($left['payload_id'] ?? ''), (string) ($right['payload_id'] ?? ''));
            if ($payloadOrder !== 0) {
                return $payloadOrder;
            }

            return strcmp((string) ($left['schema_name'] ?? ''), (string) ($right['schema_name'] ?? ''));
        });

        return $normalized;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<string>
     */
    private function canonicalRowLines(array $rows): array
    {
        return array_map(
            fn (array $row): string => $this->canonicalJson($row),
            $this->sortedRows($rows),
        );
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<string>
     */
    private function rowDigests(array $rows): array
    {
        return array_map(
            fn (string $line): string => hash('sha256', $line),
            $this->canonicalRowLines($rows),
        );
    }

    /**
     * @param  list<string>  $digests
     */
    private function merkleRoot(array $digests): string
    {
        if ($digests === []) {
            return hash('sha256', 'atlas.loop.schema_fuzz.empty');
        }

        $level = array_values($digests);
        while (count($level) > 1) {
            $next = [];
            $count = count($level);
            for ($index = 0; $index < $count; $index += 2) {
                $left = $level[$index];
                $right = $level[$index + 1] ?? $left;
                $next[] = hash('sha256', $left.'|'.$right);
            }
            $level = $next;
        }

        return $level[0];
    }

    /**
     * @return list<array{
     *   run_id:string,
     *   seed:string,
     *   generator_version:string,
     *   reporter_version:string,
     *   schema_versions:list<string>,
     *   payload_count:int,
     *   started_at_utc:string,
     *   finished_at_utc:string,
     *   per_row_digest_root:string,
     *   raw_rows_path:string
     * }>
     */
    private function readReceipts(): array
    {
        if (! is_file($this->ledgerPath())) {
            return [];
        }

        $rows = file($this->ledgerPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($rows === false) {
            return [];
        }

        $receipts = [];
        foreach ($rows as $line) {
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                continue;
            }

            $receipts[] = [
                'run_id' => trim((string) ($decoded['run_id'] ?? '')),
                'seed' => trim((string) ($decoded['seed'] ?? '')),
                'generator_version' => trim((string) ($decoded['generator_version'] ?? '')),
                'reporter_version' => trim((string) ($decoded['reporter_version'] ?? '')),
                'schema_versions' => array_values(is_array($decoded['schema_versions'] ?? null) ? $decoded['schema_versions'] : []),
                'payload_count' => (int) ($decoded['payload_count'] ?? 0),
                'started_at_utc' => trim((string) ($decoded['started_at_utc'] ?? '')),
                'finished_at_utc' => trim((string) ($decoded['finished_at_utc'] ?? '')),
                'per_row_digest_root' => trim((string) ($decoded['per_row_digest_root'] ?? '')),
                'raw_rows_path' => trim((string) ($decoded['raw_rows_path'] ?? '')),
            ];
        }

        return $receipts;
    }

    private function recomputeDigestForPath(string $path): ?string
    {
        if (! is_file($path)) {
            return null;
        }

        $rows = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                return null;
            }

            $rows[] = $decoded;
        }

        return $this->merkleRoot($this->rowDigests($rows));
    }

    private function ledgerPath(): string
    {
        return $this->basePath().'/receipts.jsonl';
    }

    private function rowsDirectory(): string
    {
        return $this->basePath().'/rows';
    }

    private function rawRowsPath(string $runId, string $root): string
    {
        $safeRunId = preg_replace('/[^A-Za-z0-9_-]/', '_', $runId);

        return $this->rowsDirectory().'/'.$safeRunId.'--'.substr($root, 0, 16).'.jsonl';
    }

    private function basePath(): string
    {
        return $this->basePath !== ''
            ? rtrim($this->basePath, '/')
            : storage_path('atlas/loop/schema-fuzz');
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create directory [{$directory}].");
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function canonicalJson(array $payload): string
    {
        return (string) json_encode(
            $this->sortRecursive($payload),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);
        }

        ksort($value, SORT_STRING);
        $sorted = [];
        foreach ($value as $key => $item) {
            $sorted[$key] = $this->sortRecursive($item);
        }

        return $sorted;
    }
}
