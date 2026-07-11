<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory\Substrate;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ACOS SUB-01 — verified snapshot/backup of memory substrate tables + series JSONLs.
 *
 * Read-only against live data; restore proof uses an ephemeral schema only.
 */
final class AtlasMemorySubstrateSnapshotService
{
    public const SCHEMA_VERSION = 'atlas.memory.substrate_snapshot.v1';

    public const JSONL_RELATIVE_PATH = 'app/atlas/evidence/memory-substrate-snapshot.jsonl';

    public function __construct(
        private readonly AtlasMemorySubstrateDumpRunner $dumpRunner,
        private readonly AtlasMemorySubstrateRestoreProofRunner $restoreProofRunner,
        private readonly AtlasEvidenceLedger $ledger,
        private readonly AtlasDecideLiveOutcomeFeedbackService $liveOutcomes,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function run(
        ?string $destinationRoot = null,
        bool $skipRestoreProof = false,
        bool $skipLedger = false,
    ): array {
        $tables = $this->configuredTables();
        $liveCounts = $this->liveTableCounts($tables);
        $snapshotDir = $this->snapshotDirectory($destinationRoot);
        $dumpPath = $snapshotDir.DIRECTORY_SEPARATOR.'memory-substrate.sql';
        $manifestPath = $snapshotDir.DIRECTORY_SEPARATOR.'manifest.json';

        $dump = $this->dumpRunner->dump($tables, $dumpPath);
        $jsonlCopies = $this->copySeriesJsonls($snapshotDir);
        $dumpHash = is_file($dumpPath) ? hash_file('sha256', $dumpPath) : null;

        $restoreProof = ['ok' => true, 'skipped' => true];
        if (! $skipRestoreProof) {
            if ($dump['ok'] ?? false) {
                $restoreProof = $this->restoreProofRunner->prove($dumpPath, $tables, $liveCounts);
                $restoreProof['skipped'] = false;
            } else {
                $restoreProof = [
                    'ok' => false,
                    'skipped' => false,
                    'reason' => 'dump_failed:'.(string) ($dump['reason'] ?? 'unknown'),
                    'ephemeral_schema' => '',
                    'restored_counts' => [],
                    'live_counts' => $liveCounts,
                ];
            }
        }

        $manifest = [
            'schema_version' => self::SCHEMA_VERSION,
            'recorded_at' => now()->toIso8601String(),
            'slice' => 'SUB-01',
            'destination' => $snapshotDir,
            'dump_path' => $dumpPath,
            'dump_hash_sha256' => $dumpHash,
            'dump_ok' => (bool) ($dump['ok'] ?? false),
            'dump_reason' => $dump['reason'] ?? null,
            'live_counts' => $liveCounts,
            'jsonl_copies' => $jsonlCopies,
            'restore_proof' => $restoreProof,
        ];

        @file_put_contents(
            $manifestPath,
            (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $ledgerEvent = null;
        $storage = null;
        if (! $skipLedger && $dumpHash !== null) {
            [$ledgerEvent, $storage] = $this->recordReceipt($manifest, $dumpHash);
        }

        $accepted = ($dump['ok'] ?? false)
            && (($restoreProof['ok'] ?? false) || ($restoreProof['skipped'] ?? false))
            && $dumpHash !== null
            && ($skipLedger || $ledgerEvent instanceof AtlasLedgerEvent || $storage === 'jsonl');

        return [
            'ok' => $accepted,
            'schema_version' => self::SCHEMA_VERSION,
            'slice' => 'SUB-01',
            'destination' => $snapshotDir,
            'dump_hash_sha256' => $dumpHash,
            'dump_ok' => (bool) ($dump['ok'] ?? false),
            'restore_proof_ok' => (bool) ($restoreProof['ok'] ?? false),
            'restore_proof_skipped' => (bool) ($restoreProof['skipped'] ?? false),
            'live_counts' => $liveCounts,
            'jsonl_copies' => $jsonlCopies,
            'manifest_path' => $manifestPath,
            'event_id' => $ledgerEvent?->event_id,
            'ledger_storage' => $storage,
        ];
    }

    /**
     * @return list<string>
     */
    public function configuredTables(): array
    {
        /** @var list<string> $tables */
        $tables = (array) config('atlas.cognition.substrate_snapshot.tables', []);

        return array_values(array_filter(array_map('strval', $tables)));
    }

    /**
     * @return list<string>
     */
    public function configuredSeriesPaths(): array
    {
        $paths = (array) config('atlas.cognition.substrate_snapshot.series_jsonls', []);
        $resolved = [];

        foreach ($paths as $path) {
            $candidate = (string) $path;
            if ($candidate === '') {
                continue;
            }

            if (str_starts_with($candidate, 'live_outcomes:')) {
                $resolved[] = $this->liveOutcomes->logPath();

                continue;
            }

            $resolved[] = $candidate;
        }

        return array_values(array_unique($resolved));
    }

    /**
     * @param  list<string>  $tables
     * @return array<string,int>
     */
    public function liveTableCounts(array $tables): array
    {
        $counts = [];
        foreach ($tables as $table) {
            if (! DatabaseTableAvailability::has($table)) {
                continue;
            }

            $counts[$table] = (int) DB::table($table)->count();
        }

        return $counts;
    }

    private function snapshotDirectory(?string $overrideRoot): string
    {
        $root = $overrideRoot !== null && trim($overrideRoot) !== ''
            ? rtrim(trim($overrideRoot), DIRECTORY_SEPARATOR)
            : rtrim((string) config('atlas.cognition.substrate_snapshot.destination_root'), DIRECTORY_SEPARATOR);

        $dir = $root.DIRECTORY_SEPARATOR.now()->format('Y-m-d\TH-i-s-u');

        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new \RuntimeException('Unable to create substrate snapshot directory: '.$dir);
        }

        return $dir;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function copySeriesJsonls(string $snapshotDir): array
    {
        $jsonlDir = $snapshotDir.DIRECTORY_SEPARATOR.'series-jsonl';
        if (! is_dir($jsonlDir) && ! @mkdir($jsonlDir, 0775, true) && ! is_dir($jsonlDir)) {
            throw new \RuntimeException('Unable to create series-jsonl directory: '.$jsonlDir);
        }

        $copies = [];
        foreach ($this->configuredSeriesPaths() as $sourcePath) {
            $basename = basename($sourcePath);
            $targetPath = $jsonlDir.DIRECTORY_SEPARATOR.$basename;
            $exists = is_file($sourcePath);

            if ($exists) {
                copy($sourcePath, $targetPath);
            }

            $copies[] = [
                'source' => $sourcePath,
                'target' => $targetPath,
                'present' => $exists,
                'sha256' => $exists ? hash_file('sha256', $sourcePath) : null,
                'line_count' => $exists ? $this->jsonlLineCount($sourcePath) : 0,
            ];
        }

        return $copies;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array{0:?AtlasLedgerEvent,1:?string}
     */
    private function recordReceipt(array $manifest, string $dumpHash): array
    {
        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'slice' => 'SUB-01',
            'dump_hash_sha256' => $dumpHash,
            'destination' => $manifest['destination'] ?? null,
            'live_counts' => $manifest['live_counts'] ?? [],
            'restore_proof_ok' => (bool) data_get($manifest, 'restore_proof.ok'),
            'jsonl_copies' => array_map(
                static fn (array $copy): array => [
                    'source' => $copy['source'] ?? null,
                    'present' => (bool) ($copy['present'] ?? false),
                    'sha256' => $copy['sha256'] ?? null,
                ],
                (array) ($manifest['jsonl_copies'] ?? []),
            ),
            'recorded_at' => now()->toIso8601String(),
        ];

        $event = null;
        $storage = 'atlas_ledger_events';

        if (DatabaseTableAvailability::has('atlas_ledger_events')) {
            $event = $this->ledger->record(LedgerEventType::MemorySubstrateSnapshotRecorded, $payload, [
                'envelope_id' => 'memory:substrate_snapshot:'.now()->format('Y-m-d'),
                'correlation_id' => 'memory:substrate_snapshot:'.Str::ulid(),
                'scope_type' => 'memory_substrate',
                'scope_id' => 'SUB-01',
                'emitter_stage' => 'atlas.memory',
                'emitter_version' => self::SCHEMA_VERSION,
            ]);
        }

        if (! $event instanceof AtlasLedgerEvent) {
            $storage = 'jsonl';
            (new JsonlReceiptStore(storage_path(self::JSONL_RELATIVE_PATH)))->append($payload);
        }

        return [$event, $storage];
    }

    private function jsonlLineCount(string $path): int
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return 0;
        }

        $count = 0;
        while (($line = fgets($handle)) !== false) {
            if (trim($line) !== '') {
                $count++;
            }
        }
        fclose($handle);

        return $count;
    }
}
