<?php

namespace App\Services\Engineering\CodeIntelligence;

use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GOD-DEBULK FASE C - the file-snapshot cache family extracted VERBATIM from
 * EngineeringCodeIntelligenceService. Bodies are byte-identical; only cross-family
 * `$this->helper(` calls were redirected to injected collaborators.
 */
class SnapshotSection
{
    public function __construct(
        private readonly PersistenceSupport $support,
        private readonly SymbolExtractor $symbolExtractor,
    ) {}


    /**
     * @return array<string,mixed>
     */
    public function loadFileSnapshots(bool $withData = false): array
    {
        if (! $this->fileSnapshotsTableExists()) {
            return [];
        }

        // Keep snapshot payloads raw in memory. The full Atlas graph can hold >100k
        // symbols; decoding every snapshot up front duplicates most of that graph before
        // the scan has a chance to stream file-by-file through the cache.
        $hasMtime = $this->snapshotSupportsMtime();
        $columns = ['file_path', 'source_hash'];
        if ($withData) {
            $columns = array_merge($columns, ['file_size', 'symbols_json', 'relations_json']);
            if ($hasMtime) {
                $columns[] = 'mtime';
                $columns[] = 'file_hash';
            }
        }

        $snapshotQuery = DB::table('atlas_engineering_code_file_snapshots')
            ->select($columns)
            ->where('status', 'active')
            ->whereNull('archived_at');
        if ($this->support->workspaceKeyed('atlas_engineering_code_file_snapshots')) {
            $snapshotQuery->where('workspace_id', $this->support->workspaceId);
        }

        $snapshots = [];
        foreach ($snapshotQuery->cursor() as $row) {
            if (! $withData) {
                $snapshots[(string) $row->file_path] = (string) $row->source_hash;

                continue;
            }

            $entry = [
                'source_hash' => (string) $row->source_hash,
                'file_size' => (int) ($row->file_size ?? 0),
                'symbols_json' => $row->symbols_json ?? '[]',
                'relations_json' => $row->relations_json ?? '{}',
            ];
            if ($hasMtime) {
                $entry['mtime'] = $row->mtime !== null ? (int) $row->mtime : null;
                $entry['file_hash'] = $row->file_hash !== null ? (string) $row->file_hash : null;
            }

            $snapshots[(string) $row->file_path] = $entry;
        }

        return $snapshots;
    }


    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<int,array<string,mixed>>
     */
    public function snapshotSymbols(array $snapshot): array
    {
        return $this->decodedJsonArray($snapshot['symbols_json'] ?? ($snapshot['symbols'] ?? []));
    }


    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    public function snapshotRelations(array $snapshot): array
    {
        return $this->decodedJsonArray($snapshot['relations_json'] ?? ($snapshot['relations'] ?? []));
    }


    /**
     * @param  array<int,array<string,mixed>>  $symbols
     * @return array<int,array<string,mixed>>
     */
    public function normalizeCachedSymbols(array $symbols, string $moduleSlug): array
    {
        return collect($symbols)
            ->filter(fn (mixed $symbol): bool => is_array($symbol))
            ->map(function (array $symbol) use ($moduleSlug): array {
                $symbol['module_slug'] = $moduleSlug;

                return $this->symbolExtractor->symbol($symbol);
            })
            ->values()
            ->all();
    }


    /**
     * @param  array<string,mixed>  $relations
     * @return array{dependencies:array<int,array<string,mixed>>,symbol_references:array<int,array<string,mixed>>,test_targets:array<int,array<string,mixed>>}
     */
    public function normalizeCachedRelations(array $relations, string $moduleSlug, string $relativePath): array
    {
        $normalizeRows = function (mixed $rows) use ($moduleSlug, $relativePath): array {
            return collect(is_array($rows) ? $rows : [])
                ->filter(fn (mixed $row): bool => is_array($row))
                ->map(function (array $row) use ($moduleSlug, $relativePath): array {
                    if (array_key_exists('from_module', $row)) {
                        $row['from_module'] = $moduleSlug;
                    }

                    if (! array_key_exists('file_path', $row) || blank($row['file_path'])) {
                        $row['file_path'] = $relativePath;
                    }

                    return $row;
                })
                ->values()
                ->all();
        };

        return [
            'dependencies' => $normalizeRows($relations['dependencies'] ?? []),
            'symbol_references' => $normalizeRows($relations['symbol_references'] ?? []),
            'test_targets' => $normalizeRows($relations['test_targets'] ?? []),
        ];
    }


    /**
     * Cache key for a file snapshot: the file content hash salted with the
     * current EXTRACTOR_VERSION. Stored in the snapshot source_hash column, which
     * is used ONLY as the snapshot cache key (module/symbol drift use their own
     * content-derived hashes). Salting with the version means a parser change
     * invalidates every key, forcing a fresh parse instead of replaying symbols
     * minted by a superseded parser.
     */
    public function fileSnapshotCacheKey(string $fileHash): string
    {
        return hash('sha256', EngineeringCodeIntelligenceService::EXTRACTOR_VERSION.'|'.$fileHash);
    }


    /**
     * @param  array<int,array<string,mixed>>  $symbols
     * @param  array{dependencies:array<int,array<string,mixed>>,symbol_references:array<int,array<string,mixed>>,test_targets:array<int,array<string,mixed>>}  $relations
     * @return array<string,mixed>
     */
    public function fileSnapshotRow(string $relativePath, string $moduleSlug, string $language, string $cacheKey, int $fileSize, string $fileHash, int $mtime, array $symbols, array $relations): array
    {
        $row = [
            'file_path' => $relativePath,
            'module_slug' => $moduleSlug,
            'language' => $language,
            'source_hash' => $cacheKey,
            'file_size' => $fileSize,
            'symbols_json' => $symbols,
            'relations_json' => $relations,
        ];

        // AP-815 C2: persist mtime + raw content hash only when the columns exist, so a
        // test booting the pre-C2 snapshot schema still writes cleanly.
        if ($this->snapshotSupportsMtime()) {
            $row['mtime'] = $mtime > 0 ? $mtime : null;
            $row['file_hash'] = $fileHash !== '' ? $fileHash : null;
        }

        return $row;
    }


    /** @var bool|null AP-815 C2: memoized whether the snapshot table carries mtime/file_hash. */
    private ?bool $snapshotMtimeSupported = null;

    public function snapshotSupportsMtime(): bool
    {
        return $this->snapshotMtimeSupported ??= (
            $this->fileSnapshotsTableExists()
            && DatabaseTableAvailability::hasColumn('atlas_engineering_code_file_snapshots', 'mtime')
            && DatabaseTableAvailability::hasColumn('atlas_engineering_code_file_snapshots', 'file_hash')
        );
    }


    /**
     * @param  array<int,array<string,mixed>>  $rows
     * @param  array<int,string>  $seenPaths
     */
    public function persistFileSnapshots(array $rows, bool $prune, array $seenPaths): void
    {
        if (! $this->fileSnapshotsTableExists()) {
            return;
        }

        $now = now();
        $indexedAt = now()->startOfSecond();
        $keyed = $this->support->workspaceKeyed('atlas_engineering_code_file_snapshots');
        $batch = [];
        $batchBytes = 0;
        foreach ($rows as $row) {
            $entry = [
                'id' => (string) Str::uuid(),
                'file_path' => (string) $row['file_path'],
                'module_slug' => (string) $row['module_slug'],
                'language' => $row['language'] ?? null,
                'source_hash' => (string) $row['source_hash'],
                'file_size' => (int) ($row['file_size'] ?? 0),
                'symbols_json' => $this->support->json((array) ($row['symbols_json'] ?? [])),
                'relations_json' => $this->support->json((array) ($row['relations_json'] ?? [])),
                'status' => 'active',
                'indexed_at' => $indexedAt,
                'archived_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            if ($this->snapshotSupportsMtime()) {
                // AP-815 C2: persist mtime + raw content hash for the next index's short-circuit.
                $entry['mtime'] = isset($row['mtime']) && $row['mtime'] !== null ? (int) $row['mtime'] : null;
                $entry['file_hash'] = isset($row['file_hash']) && $row['file_hash'] !== null ? (string) $row['file_hash'] : null;
            }
            if ($keyed) {
                $entry['workspace_id'] = $this->support->workspaceId;
            }

            $entryBytes = $this->support->estimatedRowBytes($entry);
            if ($batch !== [] && (count($batch) >= EngineeringCodeIntelligenceService::SNAPSHOT_UPSERT_MAX_ROWS || $batchBytes + $entryBytes > EngineeringCodeIntelligenceService::DB_UPSERT_MAX_BYTES)) {
                $this->upsertFileSnapshotRows($batch);
                $batch = [];
                $batchBytes = 0;
            }

            $batch[] = $entry;
            $batchBytes += $entryBytes;
        }

        if ($batch !== []) {
            $this->upsertFileSnapshotRows($batch);
        }

        if ($prune && $seenPaths !== []) {
            $pruneQuery = DB::table('atlas_engineering_code_file_snapshots')
                ->where('status', '!=', 'archived')
                ->whereNotIn('file_path', $seenPaths);
            if ($keyed) {
                $pruneQuery->where('workspace_id', $this->support->workspaceId);
            }
            $pruneQuery->update([
                'status' => 'archived',
                'archived_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }


    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    public function upsertFileSnapshotRows(array $rows): void
    {
        $update = [
            'module_slug',
            'language',
            'source_hash',
            'file_size',
            'symbols_json',
            'relations_json',
            'status',
            'indexed_at',
            'archived_at',
            'updated_at',
        ];

        // AP-815 C2: refresh mtime + file_hash on update too (when the columns exist).
        if ($this->snapshotSupportsMtime()) {
            $update[] = 'mtime';
            $update[] = 'file_hash';
        }

        DB::table('atlas_engineering_code_file_snapshots')->upsert(
            $rows,
            $this->support->workspaceConflictKey('atlas_engineering_code_file_snapshots', ['file_path']),
            $update,
        );
    }


    public function fileSnapshotsTableExists(): bool
    {
        return DatabaseTableAvailability::has('atlas_engineering_code_file_snapshots');
    }


    /**
     * @return array<string|int,mixed>
     */
    public function decodedJsonArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
