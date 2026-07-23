<?php

namespace App\Services\Engineering\CodeIntelligence;

use App\Models\AtlasEngineeringCodeModule;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * GOD-DEBULK FASE C - the module/symbol persistence family extracted VERBATIM from
 * EngineeringCodeIntelligenceService. Bodies are byte-identical; only cross-family
 * `$this->helper(` calls were redirected to injected collaborators.
 */
class PersistSection
{
    public function __construct(
        private readonly PersistenceSupport $support,
    ) {}


    /**
     * @param  array<int,array<string,mixed>>  $moduleRows
     * @return array<string,string>
     */
    public function persistModules(array $moduleRows, bool $prune): array
    {
        $ids = [];
        $seen = [];
        $keyed = $this->support->workspaceKeyed('atlas_engineering_code_modules');
        foreach ($moduleRows as $row) {
            $seen[] = $row['slug'];
            if ($keyed) {
                $row['workspace_id'] = $this->support->workspaceId;
            }
            $match = $keyed
                ? ['workspace_id' => $this->support->workspaceId, 'slug' => $row['slug']]
                : ['slug' => $row['slug']];
            $module = AtlasEngineeringCodeModule::query()->updateOrCreate($match, $row);
            $ids[$row['slug']] = $module->id;
        }

        if ($prune && $seen !== []) {
            $pruneQuery = AtlasEngineeringCodeModule::query()
                ->whereNotIn('slug', $seen)
                ->where('status', '!=', 'archived');
            if ($keyed) {
                $pruneQuery->where('workspace_id', $this->support->workspaceId);
            }
            $pruneQuery->update(['status' => 'archived', 'archived_at' => now()]);
        }

        return $ids;
    }


    /**
     * @param  array<int,array<string,mixed>>  $symbolRows
     * @param  array<string,string>  $moduleIds
     */
    public function persistSymbols(array $symbolRows, array $moduleIds, bool $prune): int
    {
        $count = 0;
        $indexedAt = now()->startOfSecond();
        $now = now();
        $keyedSymbols = $this->support->workspaceKeyed('atlas_engineering_code_symbols');
        $rows = [];
        $rowBytes = 0;
        foreach ($symbolRows as $row) {
            $moduleSlug = (string) ($row['module_slug'] ?? '');
            unset($row['module_slug']);
            $row['module_id'] = $moduleIds[$moduleSlug] ?? null;
            $row['status'] = 'active';
            $row['archived_at'] = null;
            $row['indexed_at'] = $indexedAt;
            $row['id'] = (string) Str::uuid();
            if ($keyedSymbols) {
                $row['workspace_id'] = $this->support->workspaceId;
            }
            $row['metadata'] = $this->support->json((array) ($row['metadata'] ?? []));
            $row['related_doc_ids_json'] = $this->support->json((array) ($row['related_doc_ids_json'] ?? []));
            $row['created_at'] = $now;
            $row['updated_at'] = $now;

            $estimatedBytes = $this->support->estimatedRowBytes($row);
            if ($rows !== [] && (count($rows) >= EngineeringCodeIntelligenceService::SYMBOL_UPSERT_MAX_ROWS || $rowBytes + $estimatedBytes > EngineeringCodeIntelligenceService::DB_UPSERT_MAX_BYTES)) {
                $this->upsertSymbolRows($rows);
                $rows = [];
                $rowBytes = 0;
            }

            $rows[] = $row;
            $rowBytes += $estimatedBytes;
            $count++;
        }

        if ($rows !== []) {
            $this->upsertSymbolRows($rows);
        }

        if ($prune && $symbolRows !== []) {
            $this->archiveStaleSymbols($indexedAt);
        }

        return $count;
    }


    /**
     * @param  array<int,array<string,mixed>>  $rows
     */
    public function upsertSymbolRows(array $rows): void
    {
        DB::table('atlas_engineering_code_symbols')->upsert(
            $rows,
            $this->support->workspaceConflictKey('atlas_engineering_code_symbols', ['symbol_type', 'source_hash']),
            [
                'module_id',
                'symbol_name',
                'file_path',
                'line_start',
                'line_end',
                'language',
                'signature',
                'namespace',
                'parent_symbol',
                'visibility',
                'status',
                'metadata',
                'indexed_at',
                'archived_at',
                'updated_at',
            ],
        );
    }


    public function archiveStaleSymbols(Carbon $indexedAt): int
    {
        $archivedAt = now();
        $count = 0;

        $staleQuery = AtlasEngineeringCodeSymbol::query()
            ->where('status', '!=', 'archived');
        if ($this->support->workspaceKeyed('atlas_engineering_code_symbols')) {
            $staleQuery->where('workspace_id', $this->support->workspaceId);
        }
        $staleQuery
            ->where(function (Builder $query) use ($indexedAt): void {
                $query
                    ->whereNull('indexed_at')
                    ->orWhere('indexed_at', '<', $indexedAt);
            })
            ->select('id')
            ->chunkById(1000, function (Collection $symbols) use ($archivedAt, &$count): void {
                $ids = $symbols->pluck('id')->all();
                if ($ids === []) {
                    return;
                }

                AtlasEngineeringCodeSymbol::query()
                    ->whereIn('id', $ids)
                    ->update([
                        'status' => 'archived',
                        'archived_at' => $archivedAt,
                    ]);

                $count += count($ids);
            });

        return $count;
    }
}
