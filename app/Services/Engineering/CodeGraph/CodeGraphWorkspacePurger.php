<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * AP-815 · G-9 — Right-to-forget executor for a single workspace's code graph.
 *
 * This is the EFFECT half of cross-project retention: where {@see CodeGraphRetentionPolicy}
 * (W-8) is the pure decision ("which workspace graphs are stale and eligible to reclaim"),
 * this purger is the executor that consumes that verdict and physically deletes a
 * workspace's ENTIRE graph from the code-intelligence read-model. It is also the operator's
 * direct sovereignty lever — "forget this project completely" — independent of staleness.
 *
 * A workspace's graph spans two storage families, BOTH keyed (or scoped) by workspace:
 *
 *   1. The relational read-model tables, each carrying a `workspace_id` column (added by
 *      the W-1 migration):
 *        - atlas_engineering_code_modules
 *        - atlas_engineering_code_symbols
 *        - atlas_engineering_doc_links
 *        - atlas_engineering_code_file_snapshots
 *      Rows WHERE workspace_id = <id> are deleted.
 *
 *   2. The codebase world models (the graph the runtime/MCP readers traverse). A single
 *      workspace owns TWO world-model scopes (see {@see CodeGraphWorkspaceModelResolver}):
 *        - "<workspace_id>"          — the MODULE-level graph
 *        - "<workspace_id>-symbols"  — the SYMBOL-level graph
 *      Both are reclaimed: the child nodes/edges (FK `world_model_id`) are deleted FIRST,
 *      then the parent model rows, so no orphaned nodes/edges are ever left behind.
 *
 * SOVEREIGNTY GUARD — the PRIMARY workspace (the running app, `default_workspace_id`,
 * default 'atlas-server') is REFUSED by default: purging the operator's own home graph is
 * the one irreversible mistake that must never happen by accident. A refused purge deletes
 * NOTHING and returns `protected => true` with an all-zero `deleted` block. The caller may
 * override only with an explicit `$opts['force'] === true`. The whole point of cross-project
 * retention is to prune *other* projects while the home graph stays untouchable unless
 * forced.
 *
 * Determinism & fail-safety (house contract):
 *   - Deterministic: deletion is a set operation keyed on workspace_id / scope; the
 *     returned counts are exact row counts. No clock, no random, no provider.
 *   - Transactional integrity: all deletes for one purge run inside a single DB
 *     transaction, so the graph is reclaimed atomically (a workspace is never left
 *     half-purged — either the whole graph goes or none of it does).
 *   - Each relational table is guarded with Schema::hasTable AND
 *     Schema::hasColumn('workspace_id') so the purger is safe to run before/after the W-1
 *     migration, or against a partial schema, without ever erroring on a missing table or
 *     a not-yet-keyed table. World-model tables are likewise table-guarded.
 *   - Never throws. Any unexpected failure rolls the transaction back (so a partial purge
 *     can never persist) and returns an all-zero `deleted` block — the fail-safe direction
 *     (we never report having deleted rows we did not actually delete, and a transient DB
 *     fault never propagates out of a forget operation).
 *   - Config is read with an inline default literal so it works without config edits.
 *
 * This is [php] by the runtime-language boundary: it ORCHESTRATES a governed deletion
 * (a sovereignty decision + a bounded set delete), it does not compute heavy graph data.
 */
class CodeGraphWorkspacePurger
{
    public const SCHEMA = 'atlas.code_graph.workspace_purger.v1';

    /**
     * Relational read-model tables that carry a per-workspace `workspace_id` column.
     * Each is purged WHERE workspace_id = <id>, guarded by hasTable + hasColumn.
     *
     * @var array<int,string>
     */
    private const KEYED_TABLES = [
        'atlas_engineering_code_modules',
        'atlas_engineering_code_symbols',
        'atlas_engineering_doc_links',
        'atlas_engineering_code_file_snapshots',
    ];

    /** Parent world-model table (keyed by `scope`). */
    private const WORLD_MODELS_TABLE = 'ai_codebase_world_models';

    /** World-model child tables (keyed by `world_model_id`), deleted before the parent. */
    private const WORLD_MODEL_NODES_TABLE = 'ai_codebase_world_model_nodes';

    private const WORLD_MODEL_EDGES_TABLE = 'ai_codebase_world_model_edges';

    /**
     * Delete a workspace's entire code graph.
     *
     * @param  string  $workspaceId  the workspace to forget. Trimmed; a blank id resolves
     *   to the primary workspace (and is therefore protected unless `force` is set, never
     *   silently wiping an arbitrary scope).
     * @param  array<string,mixed>  $opts  per-call overrides:
     *   - `force` (bool): when strictly true, allow purging the PRIMARY workspace.
     *     Default false → the primary workspace is refused.
     * @return array{
     *   workspace_id: string,
     *   deleted: array{
     *     modules:int, symbols:int, doc_links:int, file_snapshots:int, world_models:int
     *   },
     *   protected: bool
     * }
     *   `workspace_id` echoes the resolved (trimmed) id. `deleted` reports the exact number
     *   of rows removed per family (`world_models` counts parent model rows; their
     *   nodes/edges are deleted but not separately reported). `protected` is true ONLY when
     *   the purge was refused because it targeted the primary workspace without `force`
     *   (in which case every `deleted` count is 0 and nothing was touched).
     */
    public function purge(string $workspaceId, array $opts = []): array
    {
        $workspaceId = $this->resolveWorkspaceId($workspaceId);
        $force = ($opts['force'] ?? null) === true;

        // Sovereignty guard: never wipe the home graph by accident.
        if ($this->isPrimaryWorkspace($workspaceId) && ! $force) {
            return $this->result($workspaceId, $this->emptyDeleted(), true);
        }

        try {
            $deleted = DB::transaction(function () use ($workspaceId): array {
                $relational = $this->purgeRelationalTables($workspaceId);
                $worldModels = $this->purgeWorldModels($workspaceId);

                return [
                    'modules' => $relational['atlas_engineering_code_modules'] ?? 0,
                    'symbols' => $relational['atlas_engineering_code_symbols'] ?? 0,
                    'doc_links' => $relational['atlas_engineering_doc_links'] ?? 0,
                    'file_snapshots' => $relational['atlas_engineering_code_file_snapshots'] ?? 0,
                    'world_models' => $worldModels,
                ];
            });
        } catch (Throwable) {
            // Fail-safe: the transaction rolled back, so nothing persisted. Report an
            // all-zero deletion rather than propagating a fault out of a forget operation.
            return $this->result($workspaceId, $this->emptyDeleted(), false);
        }

        return $this->result($workspaceId, $deleted, false);
    }

    /**
     * Delete the workspace's rows from each keyed relational table.
     *
     * @return array<string,int> table => rows deleted (only tables that exist AND carry a
     *   workspace_id column appear; missing/unkeyed tables are silently skipped).
     */
    private function purgeRelationalTables(string $workspaceId): array
    {
        $deleted = [];

        foreach (self::KEYED_TABLES as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'workspace_id')) {
                // Table absent or not yet workspace-keyed (pre-W-1): nothing to purge here.
                continue;
            }

            $deleted[$table] = (int) DB::table($table)
                ->where('workspace_id', $workspaceId)
                ->delete();
        }

        return $deleted;
    }

    /**
     * Delete the workspace's world models (module scope "<ws>" + symbol scope "<ws>-symbols")
     * along with their child nodes and edges. Children are removed FIRST (by world_model_id)
     * so no orphaned nodes/edges survive, then the parent model rows.
     *
     * @return int the number of parent world-model rows deleted.
     */
    private function purgeWorldModels(string $workspaceId): int
    {
        if (! Schema::hasTable(self::WORLD_MODELS_TABLE)) {
            return 0;
        }

        $scopes = $this->worldModelScopes($workspaceId);

        // Collect the ids of every world model owned by this workspace's scopes.
        $modelIds = DB::table(self::WORLD_MODELS_TABLE)
            ->whereIn('scope', $scopes)
            ->pluck('id')
            ->all();

        if ($modelIds === []) {
            return 0;
        }

        // Children first (guarded), then the parent models — chunk the IN list so a very
        // large per-workspace graph never builds an unbounded single statement.
        foreach (array_chunk($modelIds, 500) as $chunk) {
            if (Schema::hasTable(self::WORLD_MODEL_NODES_TABLE)) {
                DB::table(self::WORLD_MODEL_NODES_TABLE)->whereIn('world_model_id', $chunk)->delete();
            }
            if (Schema::hasTable(self::WORLD_MODEL_EDGES_TABLE)) {
                DB::table(self::WORLD_MODEL_EDGES_TABLE)->whereIn('world_model_id', $chunk)->delete();
            }
        }

        $deleted = 0;
        foreach (array_chunk($modelIds, 500) as $chunk) {
            $deleted += (int) DB::table(self::WORLD_MODELS_TABLE)->whereIn('id', $chunk)->delete();
        }

        return $deleted;
    }

    /**
     * The two world-model scopes a workspace owns: the module-level scope ("<ws>") and the
     * symbol-level scope ("<ws>-symbols"). De-duplicated defensively.
     *
     * @return array<int,string>
     */
    private function worldModelScopes(string $workspaceId): array
    {
        return array_values(array_unique([
            $workspaceId,
            $workspaceId.'-symbols',
        ]));
    }

    /**
     * Whether the resolved id is the configured primary workspace. Falls back to
     * 'atlas-server' when the config value is blanked/garbled so the structural protection
     * on the home graph can never be lost to a bad config.
     */
    private function isPrimaryWorkspace(string $workspaceId): bool
    {
        $primary = config('atlas.code_graph.default_workspace_id', 'atlas-server');
        $primary = is_string($primary) && trim($primary) !== '' ? trim($primary) : 'atlas-server';

        return $workspaceId === $primary;
    }

    /**
     * Resolve the incoming id to a usable, trimmed workspace id. A blank/non-string id
     * resolves to the primary workspace — which the protection guard then refuses unless
     * forced, so an empty argument can never silently purge an arbitrary scope.
     */
    private function resolveWorkspaceId(string $workspaceId): string
    {
        $trimmed = trim($workspaceId);
        if ($trimmed !== '') {
            return $trimmed;
        }

        $primary = config('atlas.code_graph.default_workspace_id', 'atlas-server');

        return is_string($primary) && trim($primary) !== '' ? trim($primary) : 'atlas-server';
    }

    /**
     * @return array{modules:int,symbols:int,doc_links:int,file_snapshots:int,world_models:int}
     */
    private function emptyDeleted(): array
    {
        return [
            'modules' => 0,
            'symbols' => 0,
            'doc_links' => 0,
            'file_snapshots' => 0,
            'world_models' => 0,
        ];
    }

    /**
     * @param  array{modules:int,symbols:int,doc_links:int,file_snapshots:int,world_models:int}  $deleted
     * @return array{workspace_id:string,deleted:array{modules:int,symbols:int,doc_links:int,file_snapshots:int,world_models:int},protected:bool}
     */
    private function result(string $workspaceId, array $deleted, bool $protected): array
    {
        return [
            'workspace_id' => $workspaceId,
            'deleted' => $deleted,
            'protected' => $protected,
        ];
    }
}
