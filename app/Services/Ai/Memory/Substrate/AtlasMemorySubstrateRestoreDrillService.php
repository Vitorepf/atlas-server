<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory\Substrate;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class AtlasMemorySubstrateRestoreDrillService
{
    public const SCHEMA_VERSION = 'atlas.memory.substrate_restore_drill.v1';

    public function __construct(
        private readonly AtlasMemorySubstrateRestoreDrillRunner $runner,
        private readonly AtlasEvidenceLedger $ledger,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function run(?string $snapshotPath = null, bool $skipLedger = false): array
    {
        $checkedAt = CarbonImmutable::now('UTC')->toISOString();
        $snapshotDir = $snapshotPath !== null && trim($snapshotPath) !== ''
            ? rtrim(trim($snapshotPath), DIRECTORY_SEPARATOR)
            : $this->latestSnapshotDirectory();

        if ($snapshotDir === null) {
            return $this->recordAndReturn([
                'ok' => false,
                'schema_version' => self::SCHEMA_VERSION,
                'slice' => 'ELEV-17',
                'status' => 'live_drill_pending_window',
                'restored_ok' => false,
                'checked_at' => $checkedAt,
                'snapshot_path' => null,
                'diffs' => [[
                    'name' => 'snapshot_manifest',
                    'expected' => 'latest_SUB-01_manifest',
                    'actual' => 'missing',
                ]],
            ], $skipLedger);
        }

        $manifest = $this->readManifest($snapshotDir);
        $targetConnection = $this->targetConnection();
        $diffs = [];
        $runnerResult = [
            'ok' => false,
            'restored_counts' => [],
            'target' => $this->safeTarget($targetConnection),
            'reason' => 'not_run',
        ];

        if (! is_array($manifest)) {
            $diffs[] = ['name' => 'snapshot_manifest', 'expected' => 'valid_json', 'actual' => 'missing_or_invalid'];
        } elseif (($manifest['slice'] ?? null) !== 'SUB-01') {
            $diffs[] = ['name' => 'snapshot_slice', 'expected' => 'SUB-01', 'actual' => $manifest['slice'] ?? null];
        }

        if ($this->isCanonicalTarget($targetConnection)) {
            $diffs[] = [
                'name' => 'canonical_connection_guard',
                'expected' => 'non_canonical_target',
                'actual' => $this->safeTarget($targetConnection),
            ];
        }

        $dumpPath = $this->resolvedDumpPath($snapshotDir, $manifest);
        if ($dumpPath === null || ! is_file($dumpPath)) {
            $diffs[] = ['name' => 'dump_path', 'expected' => 'readable_memory_substrate_sql', 'actual' => $dumpPath];
        } else {
            $actualDumpHash = hash_file('sha256', $dumpPath);
            $expectedDumpHash = is_array($manifest) ? ($manifest['dump_hash_sha256'] ?? null) : null;
            if ($expectedDumpHash !== null && $actualDumpHash !== $expectedDumpHash) {
                $diffs[] = ['name' => 'dump_hash_sha256', 'expected' => $expectedDumpHash, 'actual' => $actualDumpHash];
            }
        }

        if (is_array($manifest)) {
            $diffs = array_merge($diffs, $this->jsonlDiffs($snapshotDir, $manifest));
        }

        $tableNames = $this->tableNames($manifest);
        if ($diffs === [] && $dumpPath !== null) {
            $runnerResult = $this->runner->restore($dumpPath, $tableNames, $targetConnection);
            if (! (bool) ($runnerResult['ok'] ?? false)) {
                $diffs[] = [
                    'name' => 'restore_runner',
                    'expected' => 'ok',
                    'actual' => $runnerResult['reason'] ?? 'failed',
                ];
            }

            $diffs = array_merge($diffs, $this->countDiffs($manifest, (array) ($runnerResult['restored_counts'] ?? [])));
        }

        $restoredOk = $diffs === [] && (bool) ($runnerResult['ok'] ?? false);
        $payload = [
            'ok' => $restoredOk,
            'schema_version' => self::SCHEMA_VERSION,
            'slice' => 'ELEV-17',
            'sub_slice' => 'SUB-01',
            'status' => $restoredOk ? 'restored_ok' : 'restore_failed',
            'restored_ok' => $restoredOk,
            'checked_at' => $checkedAt,
            'snapshot_path' => $snapshotDir,
            'manifest_path' => $snapshotDir.DIRECTORY_SEPARATOR.'manifest.json',
            'target' => $this->safeTarget($targetConnection),
            'diffs' => $diffs,
            'restored_counts' => (array) ($runnerResult['restored_counts'] ?? []),
            'manifest_counts' => is_array($manifest) ? (array) ($manifest['live_counts'] ?? []) : [],
            'runner_reason' => $runnerResult['reason'] ?? null,
        ];

        return $this->recordAndReturn($payload, $skipLedger);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readManifest(string $snapshotDir): ?array
    {
        $path = $snapshotDir.DIRECTORY_SEPARATOR.'manifest.json';
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function latestSnapshotDirectory(): ?string
    {
        $root = rtrim((string) config('atlas.cognition.substrate_snapshot.destination_root'), DIRECTORY_SEPARATOR);
        if ($root === '' || ! is_dir($root)) {
            return null;
        }

        $dirs = array_values(array_filter(
            glob($root.DIRECTORY_SEPARATOR.'*', GLOB_ONLYDIR) ?: [],
            fn (string $dir): bool => is_file($dir.DIRECTORY_SEPARATOR.'manifest.json'),
        ));
        rsort($dirs, SORT_STRING);

        foreach ($dirs as $dir) {
            $manifest = $this->readManifest($dir);
            if (is_array($manifest) && ($manifest['slice'] ?? null) === 'SUB-01') {
                return $dir;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>|null  $manifest
     */
    private function resolvedDumpPath(string $snapshotDir, ?array $manifest): ?string
    {
        $candidate = is_array($manifest) ? (string) ($manifest['dump_path'] ?? '') : '';
        if ($candidate !== '' && is_file($candidate)) {
            return $candidate;
        }

        $fallback = $snapshotDir.DIRECTORY_SEPARATOR.'memory-substrate.sql';

        return is_file($fallback) ? $fallback : ($candidate !== '' ? $candidate : $fallback);
    }

    /**
     * @param  array<string,mixed>|null  $manifest
     * @return list<string>
     */
    private function tableNames(?array $manifest): array
    {
        $counts = is_array($manifest) ? (array) ($manifest['live_counts'] ?? []) : [];
        $fromManifest = array_values(array_filter(array_map('strval', array_keys($counts))));
        if ($fromManifest !== []) {
            return $fromManifest;
        }

        return array_values(array_filter(array_map(
            'strval',
            (array) config('atlas.cognition.substrate_snapshot.tables', []),
        )));
    }

    /**
     * @param  array<string,mixed>|null  $manifest
     * @param  array<string,int>  $restoredCounts
     * @return list<array<string,mixed>>
     */
    private function countDiffs(?array $manifest, array $restoredCounts): array
    {
        if (! is_array($manifest)) {
            return [];
        }

        $diffs = [];
        foreach ((array) ($manifest['live_counts'] ?? []) as $table => $expected) {
            $actual = $restoredCounts[(string) $table] ?? null;
            if ($actual !== (int) $expected) {
                $diffs[] = [
                    'name' => 'table_count',
                    'table' => (string) $table,
                    'expected' => (int) $expected,
                    'actual' => $actual,
                ];
            }
        }

        return $diffs;
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return list<array<string,mixed>>
     */
    private function jsonlDiffs(string $snapshotDir, array $manifest): array
    {
        $diffs = [];
        foreach ((array) ($manifest['jsonl_copies'] ?? []) as $copy) {
            if (! is_array($copy) || ! (bool) ($copy['present'] ?? false)) {
                continue;
            }

            $target = (string) ($copy['target'] ?? '');
            if ($target === '' || ! is_file($target)) {
                $basename = basename($target);
                $fallback = $snapshotDir.DIRECTORY_SEPARATOR.'series-jsonl'.DIRECTORY_SEPARATOR.$basename;
                $target = is_file($fallback) ? $fallback : $target;
            }

            if ($target === '' || ! is_file($target)) {
                $diffs[] = ['name' => 'jsonl_copy', 'target' => $copy['target'] ?? null, 'expected' => 'present', 'actual' => 'missing'];

                continue;
            }

            $expectedHash = $copy['sha256'] ?? null;
            $actualHash = hash_file('sha256', $target);
            if ($expectedHash !== null && $actualHash !== $expectedHash) {
                $diffs[] = [
                    'name' => 'jsonl_hash_sha256',
                    'target' => $target,
                    'expected' => $expectedHash,
                    'actual' => $actualHash,
                ];
            }
        }

        return $diffs;
    }

    /**
     * @return array<string,mixed>
     */
    private function targetConnection(): array
    {
        return (array) config('atlas.cognition.substrate_restore_drill.target', []);
    }

    /** @param array<string,mixed> $targetConnection */
    private function isCanonicalTarget(array $targetConnection): bool
    {
        $canonical = (array) config('atlas.cognition.substrate_restore_drill.canonical', []);
        $targetPort = (int) ($targetConnection['port'] ?? 0);
        $canonicalPort = (int) ($canonical['port'] ?? 5433);

        if ($targetPort !== 0 && $targetPort === $canonicalPort) {
            return true;
        }

        $targetDatabase = (string) ($targetConnection['database'] ?? '');
        $canonicalDatabase = (string) ($canonical['database'] ?? '');
        $targetHost = (string) ($targetConnection['host'] ?? '');
        $canonicalHost = (string) ($canonical['host'] ?? '');

        return $canonicalDatabase !== ''
            && $targetDatabase === $canonicalDatabase
            && ($canonicalHost === '' || $targetHost === $canonicalHost);
    }

    /**
     * @param  array<string,mixed>  $targetConnection
     * @return array<string,mixed>
     */
    private function safeTarget(array $targetConnection): array
    {
        return [
            'host' => (string) ($targetConnection['host'] ?? ''),
            'port' => (int) ($targetConnection['port'] ?? 0),
            'database' => (string) ($targetConnection['database'] ?? ''),
            'username' => (string) ($targetConnection['username'] ?? ''),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function recordAndReturn(array $payload, bool $skipLedger): array
    {
        $receiptPath = (string) config(
            'atlas.cognition.substrate_restore_drill.receipt_path',
            storage_path('app/atlas/evidence/substrate-restore-drills.jsonl'),
        );
        (new JsonlReceiptStore($receiptPath))->append($payload);
        $payload['receipt_path'] = $receiptPath;
        $payload['ledger_event_id'] = null;

        if ($skipLedger) {
            return $payload;
        }

        try {
            if (DatabaseTableAvailability::has('atlas_ledger_events')) {
                $event = $this->ledger->record(LedgerEventType::MemorySubstrateRestoreDrillRecorded, $payload, [
                    'envelope_id' => 'memory:substrate_restore_drill:'.CarbonImmutable::now('UTC')->format('Y-m-d'),
                    'correlation_id' => 'memory:substrate_restore_drill:'.Str::ulid(),
                    'scope_type' => 'memory_substrate',
                    'scope_id' => 'ELEV-17',
                    'emitter_stage' => 'atlas.memory',
                    'emitter_version' => self::SCHEMA_VERSION,
                ]);
                if ($event instanceof AtlasLedgerEvent) {
                    $payload['ledger_event_id'] = $event->event_id;
                }
            }
        } catch (\Throwable) {
            // JSONL receipt above is the fail-open evidence path.
        }

        return $payload;
    }
}
