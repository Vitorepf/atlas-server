<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

final class AtlasCortexInsightReceiptLedger
{
    /**
     * @param  null|Closure(string, array<string,mixed>):void  $afterLockAcquired
     */
    public function __construct(
        private readonly ?string $ledgerRoot = null,
        private readonly ?Closure $afterLockAcquired = null,
    ) {}

    /**
     * @param  array<string,mixed>  $observation
     */
    public function append(string $snapshotId, array $observation): void
    {
        $snapshotId = trim($snapshotId);
        if ($snapshotId === '') {
            throw new RuntimeException('Snapshot id must not be empty.');
        }

        $row = $this->normalizeRow($snapshotId, $observation);
        $path = $this->pathForSnapshot($snapshotId);
        $directory = dirname($path);
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create ledger directory: '.$directory);
        }

        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open insight ledger path: '.$path);
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to acquire exclusive insight ledger lock.');
            }

            if ($this->afterLockAcquired instanceof Closure) {
                ($this->afterLockAcquired)($path, $row);
            }

            $existingRows = $this->readRowsFromHandle($handle);
            foreach ($existingRows as $existingRow) {
                if (
                    ($existingRow['snapshot_id'] ?? null) === $row['snapshot_id']
                    && ($existingRow['axis_id'] ?? null) === $row['axis_id']
                    && ($existingRow['witness_hash'] ?? null) === $row['witness_hash']
                ) {
                    return;
                }
            }

            fseek($handle, 0, SEEK_END);
            $encoded = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (! is_string($encoded) || fwrite($handle, $encoded.PHP_EOL) === false) {
                throw new RuntimeException('Unable to append insight ledger row.');
            }

            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function history(string $axisId, int $limit = 50): array
    {
        $axisId = trim($axisId);
        if ($axisId === '') {
            return [];
        }

        $rows = array_values(array_filter(
            $this->readAllRows(),
            static fn (array $row): bool => ($row['axis_id'] ?? null) === $axisId,
        ));
        $rows = $this->sortRows($rows);

        return array_slice($rows, -max(1, $limit));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function latestForSnapshot(string $snapshotId): array
    {
        $snapshotId = trim($snapshotId);
        if ($snapshotId === '') {
            return [];
        }

        return $this->sortRows($this->readRowsFromPath($this->pathForSnapshot($snapshotId)));
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function bySnapshotRange(string $from, string $to): array
    {
        $from = trim($from);
        $to = trim($to);
        if ($from === '' || $to === '') {
            return [];
        }

        $rows = [];
        foreach ($this->snapshotPaths() as $path) {
            $snapshotId = pathinfo($path, PATHINFO_FILENAME);
            if ($snapshotId < $from || $snapshotId > $to) {
                continue;
            }

            array_push($rows, ...$this->readRowsFromPath($path));
        }

        return $this->sortRows($rows);
    }

    /**
     * @param  array<string,mixed>  $observation
     * @return array{
     *     snapshot_id:string,
     *     axis_id:string,
     *     fact_keys_consumed:list<string>,
     *     witnesses:list<string>,
     *     emitted_at:string,
     *     witness_hash:string
     * }
     */
    private function normalizeRow(string $snapshotId, array $observation): array
    {
        $axisId = trim((string) ($observation['axis_id'] ?? ''));
        if ($axisId === '') {
            throw new RuntimeException('Observation axis_id must not be empty.');
        }

        $facts = is_array($observation['facts'] ?? null) ? $observation['facts'] : [];
        $factKeys = array_values(array_map(static fn (mixed $key): string => (string) $key, array_keys($facts)));
        sort($factKeys, SORT_STRING);

        $witnesses = $this->normalizeWitnesses((array) ($observation['witnesses'] ?? []));
        $emittedAt = $this->normalizeTimestamp(
            isset($observation['noticed_at']) ? (string) $observation['noticed_at'] : (isset($observation['emitted_at']) ? (string) $observation['emitted_at'] : null)
        );

        return [
            'snapshot_id' => $snapshotId,
            'axis_id' => $axisId,
            'fact_keys_consumed' => $factKeys,
            'witnesses' => $witnesses,
            'emitted_at' => $emittedAt,
            'witness_hash' => hash('sha256', json_encode([
                'snapshot_id' => $snapshotId,
                'axis_id' => $axisId,
                'witnesses' => $witnesses,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readAllRows(): array
    {
        $rows = [];
        foreach ($this->snapshotPaths() as $path) {
            array_push($rows, ...$this->readRowsFromPath($path));
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function snapshotPaths(): array
    {
        $paths = glob($this->root().DIRECTORY_SEPARATOR.'*.jsonl') ?: [];
        sort($paths, SORT_STRING);

        return array_values(array_filter($paths, static fn (mixed $path): bool => is_string($path) && $path !== ''));
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readRowsFromPath(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if (! is_string($contents) || $contents === '') {
            return [];
        }

        return $this->decodeJsonLines($contents);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readRowsFromHandle(mixed $handle): array
    {
        rewind($handle);
        $contents = stream_get_contents($handle);
        if (! is_string($contents) || $contents === '') {
            return [];
        }

        return $this->decodeJsonLines($contents);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function decodeJsonLines(string $contents): array
    {
        $rows = [];
        foreach (preg_split('/\R/', trim($contents)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /**
     * @param  list<mixed>  $witnesses
     * @return list<string>
     */
    private function normalizeWitnesses(array $witnesses): array
    {
        $witnesses = array_values(array_unique(array_filter(
            array_map(static fn (mixed $witness): string => trim((string) $witness), $witnesses),
            static fn (string $witness): bool => $witness !== '',
        )));
        sort($witnesses, SORT_STRING);

        return $witnesses;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array<string,mixed>>
     */
    private function sortRows(array $rows): array
    {
        usort($rows, static function (array $left, array $right): int {
            return [
                (string) ($left['snapshot_id'] ?? ''),
                (string) ($left['emitted_at'] ?? ''),
                (string) ($left['axis_id'] ?? ''),
                (string) ($left['witness_hash'] ?? ''),
            ] <=> [
                (string) ($right['snapshot_id'] ?? ''),
                (string) ($right['emitted_at'] ?? ''),
                (string) ($right['axis_id'] ?? ''),
                (string) ($right['witness_hash'] ?? ''),
            ];
        });

        return $rows;
    }

    private function normalizeTimestamp(?string $value): string
    {
        $timestamp = $value === null || trim($value) === ''
            ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : new DateTimeImmutable($value);

        return $timestamp
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    private function pathForSnapshot(string $snapshotId): string
    {
        return $this->root().DIRECTORY_SEPARATOR.$snapshotId.'.jsonl';
    }

    private function root(): string
    {
        return $this->ledgerRoot ?? storage_path('atlas/cortex/insights');
    }
}
