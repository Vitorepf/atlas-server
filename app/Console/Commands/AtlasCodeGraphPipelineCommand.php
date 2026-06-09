<?php

namespace App\Console\Commands;

use App\Services\Engineering\CodeGraph\CodeGraphSymbolBuilder;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Illuminate\Console\Command;
use Throwable;

/**
 * AP-815 · W-4 — the per-workspace AWIS code-graph pipeline, as ONE governed flow.
 *
 * Runs, for a single workspace (default = primary atlas-server), the steps an
 * operator would otherwise chain by hand:
 *
 *   1. Resolve the stable workspace_id from --workspace (path OR id) via
 *      {@see CodeGraphWorkspaceIdentity} — default = the running app.
 *   2. Re-index the Code Intelligence read-model for that workspace
 *      ({@see EngineeringCodeIntelligenceService::index()} with workspace + prune).
 *   3. Build the symbol-level code graph for that workspace
 *      ({@see CodeGraphSymbolBuilder::build()}). This step is gated by
 *      config('atlas.code_graph.real_edges'); with the flag OFF the builder
 *      returns status 'disabled', which the pipeline reports gracefully — the
 *      index still ran, so a disabled symbol build is NOT a pipeline failure.
 *   4. Emit a compact JSON / table summary:
 *      {workspace_id, index:{module_count,symbol_count},
 *       symbols:{status,symbol_nodes,edges_written}}.
 *
 * Fail-safe + strictly additive: creates no new tables, never touches existing
 * classes, and surfaces any step error as a structured failure instead of a stack
 * trace. [php] by the runtime-language boundary (orchestration, not heavy data).
 */
class AtlasCodeGraphPipelineCommand extends Command
{
    protected $signature = 'atlas:code-graph:pipeline
        {--workspace= : Workspace path or id to run the pipeline for (defaults to the primary atlas-server)}
        {--prune : Archive read-model rows no longer present in the workspace}
        {--json : Output the pipeline summary as JSON}';

    protected $description = 'Run the per-workspace AWIS code-graph pipeline (index Code Intelligence -> build symbol graph) as one governed flow. Symbol build is gated by ATLAS_CODE_GRAPH_REAL_EDGES.';

    public function handle(EngineeringCodeIntelligenceService $codeIntelligence): int
    {
        $identity = app(CodeGraphWorkspaceIdentity::class);

        // (1) Resolve the stable workspace_id (path or id; default = primary).
        $workspaceOption = $this->option('workspace');
        $workspacePath = is_string($workspaceOption) && trim($workspaceOption) !== ''
            ? trim($workspaceOption)
            : base_path();
        $workspaceId = $identity->resolve($workspacePath);
        $prune = (bool) $this->option('prune');

        $summary = [
            'schema_version' => 'atlas.code_graph.pipeline.v1',
            'status' => 'ok',
            'workspace_id' => $workspaceId,
            'prune' => $prune,
            'index' => [
                'status' => 'pending',
                'module_count' => 0,
                'symbol_count' => 0,
            ],
            'symbols' => [
                'status' => 'pending',
                'symbol_nodes' => 0,
                'edges_written' => 0,
            ],
        ];

        // (2) Re-index the Code Intelligence read-model for this workspace.
        try {
            $index = $codeIntelligence->index([
                'workspace' => $workspacePath,
                'prune' => $prune,
            ]);

            $indexSummary = is_array($index['summary'] ?? null) ? $index['summary'] : [];
            $summary['index'] = [
                'status' => ($index['ok'] ?? false) ? 'ok' : 'failed',
                'module_count' => (int) ($indexSummary['module_count'] ?? 0),
                'symbol_count' => (int) ($indexSummary['symbol_count'] ?? (int) ($index['symbol_count'] ?? 0)),
            ];
        } catch (Throwable $e) {
            $summary['status'] = 'failed';
            $summary['index']['status'] = 'failed';
            $summary['index']['error'] = $e->getMessage();

            return $this->emit($summary, self::FAILURE);
        }

        // (3) Build the symbol-level graph for this workspace (gated; 'disabled' is OK).
        try {
            $symbols = app(CodeGraphSymbolBuilder::class)->build($workspaceId);
            $summary['symbols'] = [
                'status' => (string) ($symbols['status'] ?? 'unknown'),
                'symbol_nodes' => (int) ($symbols['symbol_nodes'] ?? 0),
                'edges_written' => (int) ($symbols['edges_written'] ?? 0),
            ];
            if (isset($symbols['world_model_id'])) {
                $summary['symbols']['world_model_id'] = $symbols['world_model_id'];
            }
        } catch (Throwable $e) {
            // A failed symbol build does not undo a successful index; report it, but
            // keep the index result and signal partial failure.
            $summary['status'] = 'failed';
            $summary['symbols']['status'] = 'failed';
            $summary['symbols']['error'] = $e->getMessage();

            return $this->emit($summary, self::FAILURE);
        }

        return $this->emit($summary, self::SUCCESS);
    }

    /**
     * @param  array<string,mixed>  $summary
     */
    private function emit(array $summary, int $exitCode): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exitCode;
        }

        $this->info("code-graph pipeline: {$summary['status']}  (workspace={$summary['workspace_id']})");

        $index = $summary['index'];
        $this->line("index: {$index['status']}  modules=".(int) $index['module_count'].'  symbols='.(int) $index['symbol_count']);

        $symbols = $summary['symbols'];
        if (($symbols['status'] ?? null) === 'disabled') {
            $this->warn('symbol graph: disabled — set ATLAS_CODE_GRAPH_REAL_EDGES=true to populate real edges.');
        } else {
            $this->line("symbols: {$symbols['status']}  nodes=".(int) $symbols['symbol_nodes'].'  edges='.(int) $symbols['edges_written']);
        }

        return $exitCode;
    }
}
