<?php

namespace App\Services\Engineering\CodeIntelligence;

use App\Models\AtlasEngineeringCodeModule;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * GOD-DEBULK FASE C - the drift detection + audit payloads family extracted VERBATIM from
 * EngineeringCodeIntelligenceService. Bodies are byte-identical; only cross-family
 * `$this->helper(` calls were redirected to injected collaborators.
 */
class DriftSection
{
    public function __construct(
        private readonly PersistenceSupport $support,
        private readonly ModuleExtractor $moduleExtractor,
        private readonly SymbolExtractor $symbolExtractor,
    ) {}


    /**
     * @param  array<int,array<string,mixed>>  $moduleRows
     * @return array<string,mixed>
     */
    public function moduleDrift(array $moduleRows, int $limit): array
    {
        $scanned = collect($moduleRows)->keyBy('slug');
        $persistedQuery = AtlasEngineeringCodeModule::query()->active();
        if ($this->support->workspaceKeyed('atlas_engineering_code_modules')) {
            $persistedQuery->where('workspace_id', $this->support->workspaceId);
        }

        $persisted = $persistedQuery
            ->get([
                'slug',
                'name',
                'layer',
                'root_path',
                'docs_status',
                'file_count',
                'symbol_count',
                'route_count',
                'command_count',
                'migration_count',
                'test_count',
                'source_hash',
                'indexed_at',
            ])
            ->keyBy('slug');
        $missingKeys = $scanned->keys()->diff($persisted->keys())->values();
        $removedKeys = $persisted->keys()->diff($scanned->keys())->values();
        $changed = $scanned
            ->keys()
            ->intersect($persisted->keys())
            ->map(fn (string $slug): ?array => $this->changedModulePayload($scanned->get($slug), $persisted->get($slug)))
            ->filter()
            ->values();

        return [
            'counts' => [
                'missing_in_index' => $missingKeys->count(),
                'removed_from_workspace' => $removedKeys->count(),
                'changed' => $changed->count(),
            ],
            'missing_in_index' => $missingKeys
                ->take($limit)
                ->map(fn (string $slug): array => $this->moduleAuditPayload($scanned->get($slug), 'missing_in_index'))
                ->values()
                ->all(),
            'removed_from_workspace' => $removedKeys
                ->take($limit)
                ->map(fn (string $slug): array => $this->persistedModuleAuditPayload($persisted->get($slug), 'removed_from_workspace'))
                ->values()
                ->all(),
            'changed' => $changed->take($limit)->values()->all(),
        ];
    }


    public function canTrustModuleHashesForSymbolFreshness(int $moduleDrift, int $scannedSymbolCount): bool
    {
        if ($moduleDrift !== 0) {
            return false;
        }

        return $this->persistedActiveSymbolCountForCurrentWorkspace() === $scannedSymbolCount;
    }


    public function shouldUseBoundedSymbolDrift(int $scannedSymbolCount): bool
    {
        return $scannedSymbolCount > EngineeringCodeIntelligenceService::DETAILED_SYMBOL_DRIFT_SCAN_LIMIT;
    }


    public function persistedActiveSymbolCountForCurrentWorkspace(): int
    {
        $query = DB::table('atlas_engineering_code_symbols')
            ->where('status', 'active')
            ->whereNull('archived_at');

        if ($this->support->workspaceKeyed('atlas_engineering_code_symbols')) {
            $query->where('workspace_id', $this->support->workspaceId);
        }

        return (int) $query->count();
    }


    /**
     * @return array<string,mixed>
     */
    public function boundedSymbolDrift(int $scannedSymbolCount): array
    {
        $persistedCount = $this->persistedActiveSymbolCountForCurrentWorkspace();

        return [
            'counts' => [
                'added' => max(0, $scannedSymbolCount - $persistedCount),
                'removed' => max(0, $persistedCount - $scannedSymbolCount),
            ],
            'by_type' => [
                'added' => [],
                'removed' => [],
            ],
            'added' => [],
            'removed' => [],
            'detail_limited' => true,
            'detail_limit_reason' => 'scan_symbol_count_exceeds_memory_safe_diff_limit',
            'scanned_symbol_count' => $scannedSymbolCount,
            'persisted_symbol_count' => $persistedCount,
            'detailed_scan_limit' => EngineeringCodeIntelligenceService::DETAILED_SYMBOL_DRIFT_SCAN_LIMIT,
        ];
    }


    /**
     * @return array<string,mixed>
     */
    public function emptySymbolDrift(): array
    {
        return [
            'counts' => [
                'added' => 0,
                'removed' => 0,
            ],
            'by_type' => [
                'added' => [],
                'removed' => [],
            ],
            'added' => [],
            'removed' => [],
        ];
    }


    /**
     * @param  array<int,array<string,mixed>>  $symbolRows
     * @return array<string,mixed>
     */
    public function symbolDrift(array $symbolRows, int $limit): array
    {
        $scannedTypesByKey = [];
        foreach ($symbolRows as $symbol) {
            $scannedTypesByKey[$this->symbolExtractor->symbolSourceKey($symbol)] = (string) ($symbol['symbol_type'] ?? 'unknown');
        }

        $persistedQuery = DB::table('atlas_engineering_code_symbols as symbols')
            ->leftJoin('atlas_engineering_code_modules as modules', 'modules.id', '=', 'symbols.module_id')
            ->where('symbols.status', 'active')
            ->whereNull('symbols.archived_at');

        if ($this->support->workspaceKeyed('atlas_engineering_code_symbols')) {
            $persistedQuery->where('symbols.workspace_id', $this->support->workspaceId);
        }

        $removedCount = 0;
        $removedTypeCounts = [];
        $removedSamples = [];

        foreach ($persistedQuery
            ->select([
                'symbols.id',
                'symbols.module_id',
                'modules.slug as module_slug',
                'symbols.symbol_type',
                'symbols.symbol_name',
                'symbols.file_path',
                'symbols.line_start',
                'symbols.language',
                'symbols.source_hash',
                'symbols.indexed_at',
            ])
            ->orderBy('symbols.id')
            ->cursor() as $symbol) {
            $payload = [
                'id' => (string) $symbol->id,
                'module_id' => $symbol->module_id,
                'module_slug' => $symbol->module_slug,
                'symbol_type' => (string) $symbol->symbol_type,
                'symbol_name' => (string) $symbol->symbol_name,
                'file_path' => (string) $symbol->file_path,
                'line_start' => $symbol->line_start,
                'language' => $symbol->language,
                'source_hash' => (string) $symbol->source_hash,
                'indexed_at' => $symbol->indexed_at,
            ];
            $key = $this->symbolExtractor->symbolSourceKey($payload);

            if (array_key_exists($key, $scannedTypesByKey)) {
                unset($scannedTypesByKey[$key]);

                continue;
            }

            $removedCount++;
            $symbolType = (string) ($payload['symbol_type'] ?? 'unknown');
            $removedTypeCounts[$symbolType] = ($removedTypeCounts[$symbolType] ?? 0) + 1;
            if (count($removedSamples) < $limit) {
                $removedSamples[] = $this->persistedSymbolAuditPayload($payload, 'removed');
            }
        }

        ksort($removedTypeCounts);

        $addedCount = count($scannedTypesByKey);
        $addedTypeCounts = array_count_values($scannedTypesByKey);
        ksort($addedTypeCounts);

        $addedSamples = [];
        if ($addedCount > 0 && $limit > 0) {
            foreach ($symbolRows as $symbol) {
                if (! array_key_exists($this->symbolExtractor->symbolSourceKey($symbol), $scannedTypesByKey)) {
                    continue;
                }

                $addedSamples[] = $this->symbolAuditPayload($symbol, 'added');
                if (count($addedSamples) >= $limit) {
                    break;
                }
            }
        }

        return [
            'counts' => [
                'added' => $addedCount,
                'removed' => $removedCount,
            ],
            'by_type' => [
                'added' => $addedTypeCounts,
                'removed' => $removedTypeCounts,
            ],
            'added' => $addedSamples,
            'removed' => $removedSamples,
        ];
    }


    /**
     * @return array<string,mixed>
     */
    public function docLinkHealth(string $workspace, int $limit): array
    {
        $linkQuery = DB::table('atlas_engineering_doc_links')
            ->whereNull('archived_at');
        if ($this->support->workspaceKeyed('atlas_engineering_doc_links')) {
            $linkQuery->where('workspace_id', $this->support->workspaceId);
        }

        $links = $linkQuery
            ->select(['id', 'status', 'canonical_path', 'target_path', 'target_hash', 'link_type', 'indexed_at'])
            ->orderBy('id')
            ->cursor();
        $missingTargets = [];
        $staleHashes = [];
        $currentCount = 0;
        $persistedMissingTargetCount = 0;
        $targetHashCache = [];

        foreach ($links as $link) {
            if ($link->status === 'current') {
                $currentCount++;
            }

            if ($link->status === 'missing_target') {
                $persistedMissingTargetCount++;
            }

            $targetPath = is_string($link->target_path) && trim($link->target_path) !== ''
                ? trim($link->target_path)
                : null;
            if ($targetPath === null) {
                continue;
            }

            $fullPath = $workspace.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $targetPath);
            if (! File::exists($fullPath)) {
                if (in_array($link->link_type, ['module_path', 'module_capability'], true)) {
                    continue;
                }

                if ($link->status !== 'missing_target') {
                    $missingTargets[] = $this->docLinkAuditPayload($link, 'missing_target_detected');
                }

                continue;
            }

            if (File::isFile($fullPath) && $link->target_hash) {
                if (! array_key_exists($fullPath, $targetHashCache)) {
                    $targetHashCache[$fullPath] = hash_file('sha256', $fullPath) ?: null;
                }

                if ($targetHashCache[$fullPath] !== $link->target_hash) {
                    $staleHashes[] = $this->docLinkAuditPayload($link, 'stale_target_hash');
                }
            }
        }

        return [
            'counts' => [
                'current' => $currentCount,
                'persisted_missing_target_status' => $persistedMissingTargetCount,
                'missing_targets' => count($missingTargets),
                'stale_target_hashes' => count($staleHashes),
            ],
            'missing_targets' => array_slice($missingTargets, 0, $limit),
            'stale_target_hashes' => array_slice($staleHashes, 0, $limit),
        ];
    }


    /**
     * @param  array<string,mixed>|null  $symbol
     * @return array<string,mixed>
     */
    public function persistedSymbolAuditPayload(?array $symbol, string $reason): array
    {
        return [
            'reason' => $reason,
            'module_slug' => $symbol['module_slug'] ?? null,
            'symbol_type' => (string) ($symbol['symbol_type'] ?? ''),
            'symbol_name' => (string) ($symbol['symbol_name'] ?? ''),
            'file_path' => (string) ($symbol['file_path'] ?? ''),
            'line_start' => $symbol['line_start'] ?? null,
            'language' => $symbol['language'] ?? null,
            'source_hash' => (string) ($symbol['source_hash'] ?? ''),
            'indexed_at' => $symbol['indexed_at'] ?? null,
        ];
    }


    /**
     * @return array<string,mixed>
     */
    public function docLinkAuditPayload(object $link, string $reason): array
    {
        return [
            'reason' => $reason,
            'id' => $link->id,
            'status' => $link->status,
            'link_type' => $link->link_type,
            'canonical_path' => $link->canonical_path,
            'target_path' => $link->target_path,
            'target_hash' => $link->target_hash,
            'indexed_at' => is_object($link->indexed_at) && method_exists($link->indexed_at, 'toJSON')
                ? $link->indexed_at->toJSON()
                : $link->indexed_at,
        ];
    }


    /**
     * @param  array<string,mixed>|null  $scanned
     * @return array<string,mixed>|null
     */
    public function changedModulePayload(?array $scanned, ?AtlasEngineeringCodeModule $persisted): ?array
    {
        if ($scanned === null || $persisted === null) {
            return null;
        }

        $fields = ['source_hash', 'file_count', 'symbol_count', 'route_count', 'command_count', 'migration_count', 'test_count'];
        $differences = [];
        foreach ($fields as $field) {
            $scannedValue = $scanned[$field] ?? null;
            $persistedValue = $persisted->{$field};
            if ((string) $scannedValue !== (string) $persistedValue) {
                $differences[$field] = [
                    'scanned' => $scannedValue,
                    'persisted' => $persistedValue,
                ];
            }
        }

        if ($differences === []) {
            return null;
        }

        return array_merge($this->moduleAuditPayload($scanned, 'changed'), [
            'persisted' => [
                'source_hash' => $persisted->source_hash,
                'file_count' => $persisted->file_count,
                'symbol_count' => $persisted->symbol_count,
                'route_count' => $persisted->route_count,
                'command_count' => $persisted->command_count,
                'migration_count' => $persisted->migration_count,
                'test_count' => $persisted->test_count,
                'indexed_at' => $persisted->indexed_at?->toJSON(),
            ],
            'differences' => $differences,
        ]);
    }


    /**
     * @param  array<string,mixed>|null  $module
     * @return array<string,mixed>
     */
    public function moduleAuditPayload(?array $module, string $reason): array
    {
        return $this->moduleExtractor->moduleAuditPayload($module, $reason);
    }


    /**
     * @return array<string,mixed>
     */
    public function persistedModuleAuditPayload(?AtlasEngineeringCodeModule $module, string $reason): array
    {
        return [
            'reason' => $reason,
            'slug' => (string) ($module?->slug ?? ''),
            'name' => (string) ($module?->name ?? ''),
            'layer' => (string) ($module?->layer ?? ''),
            'root_path' => $module?->root_path,
            'docs_status' => (string) ($module?->docs_status ?? ''),
            'source_hash' => (string) ($module?->source_hash ?? ''),
            'file_count' => (int) ($module?->file_count ?? 0),
            'symbol_count' => (int) ($module?->symbol_count ?? 0),
            'route_count' => (int) ($module?->route_count ?? 0),
            'command_count' => (int) ($module?->command_count ?? 0),
            'migration_count' => (int) ($module?->migration_count ?? 0),
            'test_count' => (int) ($module?->test_count ?? 0),
            'indexed_at' => $module?->indexed_at?->toJSON(),
        ];
    }


    /**
     * @param  array<string,mixed>|null  $symbol
     * @return array<string,mixed>
     */
    public function symbolAuditPayload(?array $symbol, string $reason): array
    {
        return $this->symbolExtractor->symbolAuditPayload($symbol, $reason);
    }
}
