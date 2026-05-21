<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Context\AtlasGraphRetrievalNetworkService;
use Illuminate\Console\Command;

final class AtlasGraphRetrievalNetworkCommand extends Command
{
    protected $signature = 'atlas:context:graph-retrieval
        {--query= : Objective/query for bounded graph retrieval}
        {--task-type=direct : Task type}
        {--domain=atlas : Domain}
        {--risk=low : Risk level}
        {--target-file=* : Target files for graph anchoring}
        {--target-flow=* : Target flows for graph anchoring}
        {--target-capability=* : Target capabilities for graph anchoring}
        {--target-risk=* : Target risks for graph anchoring}
        {--world-model-id= : Optional world model id}
        {--max-results=8 : Maximum graph evidence nodes}
        {--json : Emit canonical JSON}';

    protected $description = 'Run AUCRI AGRN bounded graph retrieval over the Codebase World Model.';

    public function handle(AtlasGraphRetrievalNetworkService $service): int
    {
        $payload = $service->retrieve([
            'objective' => (string) ($this->option('query') ?: 'atlas graph retrieval readiness'),
            'task_type' => (string) $this->option('task-type'),
            'domain' => (string) $this->option('domain'),
            'risk_level' => (string) $this->option('risk'),
            'target_files' => (array) $this->option('target-file'),
            'target_flows' => (array) $this->option('target-flow'),
            'target_capabilities' => (array) $this->option('target-capability'),
            'target_risks' => (array) $this->option('target-risk'),
            'world_model_id' => (string) ($this->option('world-model-id') ?: ''),
            'max_results' => (int) $this->option('max-results'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return (string) ($payload['status'] ?? '') === 'blocked' ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('Atlas Graph Retrieval Network', (string) $payload['schema_version']);
        $this->components->twoColumnDetail('Status', (string) $payload['status']);
        $this->components->twoColumnDetail('Evidence nodes', (string) data_get($payload, 'graph_evidence_set.evidence_count', 0));
        $this->components->twoColumnDetail('Traversal', (string) data_get($payload, 'graph_traversal_receipt.status', 'unknown'));
        $this->components->twoColumnDetail('Graph hash', (string) $payload['graph_retrieval_hash']);

        return (string) ($payload['status'] ?? '') === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
