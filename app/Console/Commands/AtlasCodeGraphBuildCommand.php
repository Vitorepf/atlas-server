<?php

namespace App\Console\Commands;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelNode;
use App\Models\AtlasEngineeringCodeModule;
use App\Services\Engineering\CodeGraph\CodeGraphEdgeBuilder;
use App\Services\Engineering\CodeGraph\CodeGraphSymbolBuilder;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceModelResolver;
use Illuminate\Console\Command;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

/**
 * AP-811: build the REAL code graph end-to-end and populate it into the
 * world-model edge table from the Code Intelligence read-model.
 *
 * Self-sufficient: if no world model exists (or --fresh), it seeds a code-graph
 * world model + one node per indexed module ("node:<root_path>") straight from
 * Code Intelligence, then resolves + persists the real confidence-graded edges.
 *
 * Gated by config('atlas.code_graph.real_edges') (env ATLAS_CODE_GRAPH_REAL_EDGES):
 * with the flag off the builder writes nothing (reports 'disabled').
 */
class AtlasCodeGraphBuildCommand extends Command
{
    protected $signature = 'atlas:code-graph:build
        {--world-model= : Specific world_model_id (defaults to the most recent)}
        {--fresh : Seed a new code-graph world model + module nodes from Code Intelligence}
        {--symbols : Build the SYMBOL-level (FQN class/interface/trait/enum) graph}
        {--workspace= : Workspace path or id to build for (AP-815 W-2; defaults to the primary atlas-server)}
        {--json : Output the build summary as JSON}';

    protected $description = 'Build & populate the real AP-811 code graph from Code Intelligence (gated by ATLAS_CODE_GRAPH_REAL_EDGES). --symbols for symbol-level granularity.';

    public function handle(CodeGraphEdgeBuilder $builder): int
    {
        if ($this->option('symbols')) {
            return $this->buildSymbolGraph();
        }

        $model = $this->resolveModel();

        if ($model === null) {
            $this->info('No world model found — seeding a code-graph world model + module nodes from Code Intelligence…');
            $model = $this->seedWorldModel();
            if ($model === null) {
                $this->error('Could not seed: no Code Intelligence modules with a root_path. Run `atlas:engineering:knowledge index-code` first.');

                return self::FAILURE;
            }
        }

        $summary = $builder->build($model);

        if ($this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $status = (string) ($summary['status'] ?? 'ok');
        $this->info("code-graph build: {$status}  (world_model={$summary['world_model_id']})");
        if ($status === 'disabled') {
            $this->warn('Flag off — set ATLAS_CODE_GRAPH_REAL_EDGES=true to populate real edges.');
        } else {
            $this->line('edges_written: '.(string) ($summary['edges_written'] ?? 0));
            $stats = $summary['stats'] ?? [];
            if ($stats !== []) {
                $this->line('resolver stats: '.(string) json_encode($stats, JSON_UNESCAPED_SLASHES));
            }
        }

        return self::SUCCESS;
    }

    private function buildSymbolGraph(): int
    {
        $summary = app(CodeGraphSymbolBuilder::class)->build($this->resolvedWorkspaceId());

        if ($this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $status = (string) ($summary['status'] ?? 'ok');
        $this->info("code-graph SYMBOL build: {$status}");
        if ($status === 'disabled') {
            $this->warn('Flag off — set ATLAS_CODE_GRAPH_REAL_EDGES=true to populate real edges.');
        } else {
            $this->line('symbol_nodes: '.(string) ($summary['symbol_nodes'] ?? 0).'  edges_written: '.(string) ($summary['edges_written'] ?? 0));
            $stats = $summary['stats'] ?? [];
            if ($stats !== []) {
                $this->line('resolver stats: '.(string) json_encode($stats, JSON_UNESCAPED_SLASHES));
            }
        }

        return self::SUCCESS;
    }

    /**
     * AP-815 W-2 — resolve the --workspace option (path or id) to a stable workspace_id.
     */
    private function resolvedWorkspaceId(): ?string
    {
        $ws = $this->option('workspace');
        if (! is_string($ws) || trim($ws) === '') {
            return null;
        }

        return app(CodeGraphWorkspaceIdentity::class)->resolve($ws);
    }

    private function resolveModel(): ?AiCodebaseWorldModel
    {
        if ($this->option('fresh')) {
            return null; // force the seed path
        }

        $modelId = $this->option('world-model');
        if ($modelId) {
            return AiCodebaseWorldModel::query()->where('model_id', $modelId)->orderByDesc('id')->first();
        }

        // AP-815 W-3: resolve the MODULE graph for THIS workspace (scope-aware), not the
        // global latest — otherwise a 2nd workspace / the cross-domain model shadows it.
        $workspaceId = $this->resolvedWorkspaceId() ?? app(CodeGraphWorkspaceIdentity::class)->default();

        return app(CodeGraphWorkspaceModelResolver::class)->moduleModel($workspaceId);
    }

    /**
     * Seed a code-graph world model with one node per indexed module
     * ("node:<root_path>") so the edge builder's existence gate can resolve real
     * module-to-module edges. Returns null when there is nothing to index.
     */
    private function seedWorldModel(): ?AiCodebaseWorldModel
    {
        $workspaceId = $this->resolvedWorkspaceId() ?? app(CodeGraphWorkspaceIdentity::class)->default();
        $modules = AtlasEngineeringCodeModule::query()
            ->whereNotNull('root_path')
            ->when(DatabaseTableAvailability::hasColumn('atlas_engineering_code_modules', 'workspace_id'), fn ($q) => $q->where('workspace_id', $workspaceId))
            ->get(['slug', 'root_path']);

        if ($modules->isEmpty()) {
            return null;
        }

        $token = (string) Str::uuid();
        $model = AiCodebaseWorldModel::query()->create([
            'goal_record_id' => null,
            'model_id' => 'code-graph-'.substr(hash('sha256', $token), 0, 24),
            'scope' => $workspaceId,
            'status' => 'built',
            'capabilities' => ['code_graph'],
            'risks' => [],
            'receipt' => ['source' => 'atlas:code-graph:build', 'modules' => $modules->count()],
            'model_hash' => hash('sha256', 'code-graph-model:'.$token),
        ]);

        $seen = [];
        foreach ($modules as $module) {
            $rootPath = (string) $module->root_path;
            $nodeId = 'node:'.$rootPath;
            if (isset($seen[$nodeId])) {
                continue;
            }
            $seen[$nodeId] = true;

            AiCodebaseWorldModelNode::query()->create([
                'world_model_id' => $model->id,
                'node_id' => $nodeId,
                'node_type' => 'module',
                'path' => $rootPath,
                'metadata' => ['slug' => (string) $module->slug, 'source' => 'code_graph_seed'],
            ]);
        }

        $this->info('seeded world model '.$model->model_id.' with '.count($seen).' module nodes.');

        return $model;
    }
}
