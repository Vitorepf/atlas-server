<?php

namespace App\Console\Commands;

use App\Models\AiCodebaseWorldModel;
use App\Services\Engineering\CodeGraph\CodeGraphEdgeBuilder;
use Illuminate\Console\Command;

/**
 * AP-811 promotion surface: populate REAL code-graph edges into the world-model
 * edge table from the Code Intelligence read-model, replacing the fixture edges.
 *
 * Gated by config('atlas.code_graph.real_edges') (env ATLAS_CODE_GRAPH_REAL_EDGES,
 * default false). With the flag off the builder reports 'disabled' and writes
 * nothing — so this command is safe to ship; activation is the operator's one-line
 * env flip (the human-reviewed promotion step, runtime_promotion_policy.v1).
 */
class AtlasCodeGraphBuildCommand extends Command
{
    protected $signature = 'atlas:code-graph:build
        {--world-model= : Specific world_model_id (defaults to the most recent built)}
        {--json : Output the build summary as JSON}';

    protected $description = 'Populate real AP-811 code-graph edges from Code Intelligence (gated by ATLAS_CODE_GRAPH_REAL_EDGES).';

    public function handle(CodeGraphEdgeBuilder $builder): int
    {
        $query = AiCodebaseWorldModel::query();
        $modelId = $this->option('world-model');
        $model = $modelId
            ? $query->where('model_id', $modelId)->orderByDesc('id')->first()
            : $query->orderByDesc('id')->first();

        if ($model === null) {
            $this->error('No world model found — build one first (autonomous engineering buildWorldModel).');

            return self::FAILURE;
        }

        $summary = $builder->build($model);

        if ($this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $status = (string) ($summary['status'] ?? 'ok');
        $this->info("code-graph build: {$status}");
        if ($status === 'disabled') {
            $this->warn('Flag off — set ATLAS_CODE_GRAPH_REAL_EDGES=true to populate real edges.');
        } else {
            $this->line('edges_written: '.(string) ($summary['edges_written'] ?? 0));
        }

        return self::SUCCESS;
    }
}
