<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AiCodebaseWorldModelNode;
use App\Services\Ai\Context\AtlasGraphRetrievalNetworkService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class GraphRetrievalNetworkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootWorldModelSchema();
    }

    protected function tearDown(): void
    {
        $this->dropWorldModelSchema();

        parent::tearDown();
    }

    public function test_bounded_graph_retrieval_returns_evidence_without_exposing_raw_query(): void
    {
        $modelId = $this->seedRouterGraph();

        $payload = app(AtlasGraphRetrievalNetworkService::class)->retrieve([
            'query' => 'debug router failure with tests',
            'task_type' => 'debug',
            'risk_level' => 'medium',
            'target_files' => ['app/services/ai/router/routerservice.php'],
            'target_flows' => ['atlas_router'],
            'world_model_id' => $modelId,
            'max_results' => 6,
        ]);

        $this->assertSame(AtlasGraphRetrievalNetworkService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(AtlasGraphRetrievalNetworkService::GRAPH_QUERY_SCHEMA, data_get($payload, 'graph_query.schema_version'));
        $this->assertSame(AtlasGraphRetrievalNetworkService::GRAPH_EVIDENCE_SET_SCHEMA, data_get($payload, 'graph_evidence_set.schema_version'));
        $this->assertSame(AtlasGraphRetrievalNetworkService::GRAPH_TRAVERSAL_RECEIPT_SCHEMA, data_get($payload, 'graph_traversal_receipt.schema_version'));
        $this->assertGreaterThanOrEqual(2, data_get($payload, 'graph_evidence_set.evidence_count'));
        $this->assertSame('passed', data_get($payload, 'graph_traversal_receipt.status'));
        $this->assertTrue(data_get($payload, 'policy.bounded_traversal_only'));
        $this->assertFalse(data_get($payload, 'policy.global_graph_retrieval_active'));
        $this->assertFalse(data_get($payload, 'policy.providers_invoked'));
        $this->assertFalse(data_get($payload, 'policy.writes'));
        $this->assertFalse(data_get($payload, 'claims.global_graph_rag_ready'));
        $this->assertFalse(data_get($payload, 'claims.benchmark_run'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['graph_retrieval_hash']);

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('debug router failure with tests', $json);
        $this->assertStringNotContainsString('service:router', $json);
        $this->assertStringContainsString('app/Services/Ai/Router/RouterService.php', $json);

        $relations = collect(data_get($payload, 'graph_evidence_set.evidence', []))
            ->pluck('relation_path')
            ->flatten(1)
            ->all();
        $this->assertNotEmpty($relations);
    }

    public function test_high_risk_missing_world_model_blocks_fail_closed(): void
    {
        $payload = app(AtlasGraphRetrievalNetworkService::class)->retrieve([
            'query' => 'risky migration relation audit',
            'risk_level' => 'high',
            'world_model_id' => 'missing-model',
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('blocked', data_get($payload, 'graph_traversal_receipt.status'));
        $this->assertSame('world_model_not_found', data_get($payload, 'graph_traversal_receipt.fallback_reason'));
        $this->assertFalse(data_get($payload, 'claims.bounded_world_model_retrieval_ready'));
    }

    public function test_low_risk_missing_world_model_degrades_without_provider_or_write(): void
    {
        $payload = app(AtlasGraphRetrievalNetworkService::class)->retrieve([
            'query' => 'simple relation lookup',
            'risk_level' => 'low',
            'world_model_id' => 'missing-model',
        ]);

        $this->assertSame('degraded', $payload['status']);
        $this->assertSame('blocked', data_get($payload, 'graph_traversal_receipt.status'));
        $this->assertFalse(data_get($payload, 'policy.providers_invoked'));
        $this->assertFalse(data_get($payload, 'policy.writes'));
    }

    public function test_command_emits_canonical_json(): void
    {
        $modelId = $this->seedRouterGraph();

        $exitCode = Artisan::call('atlas:context:graph-retrieval', [
            '--query' => 'router tests',
            '--task-type' => 'debug',
            '--risk' => 'medium',
            '--target-file' => ['app/services/ai/router/routerservice.php'],
            '--world-model-id' => $modelId,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(AtlasGraphRetrievalNetworkService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('codebase_world_model_bounded', data_get($payload, 'graph_query.graph_scope'));
    }

    private function seedRouterGraph(): string
    {
        $modelId = 'aucri_agrn_router';
        $this->model($modelId);
        $this->node($modelId, 'service:router', 'service', 'app/Services/Ai/Router/RouterService.php', 'atlas_router', ['routing', 'debug']);
        $this->node($modelId, 'doc:router', 'doc', 'docs/engineering-knowledge-base/atlas-router.md', 'atlas_router', ['routing']);
        $this->node($modelId, 'test:router', 'test', 'tests/Feature/Ai/RouterRuntimeTest.php', 'atlas_router', ['routing', 'test']);
        $this->edge($modelId, 'doc:router', 'service:router', 'documents');
        $this->edge($modelId, 'test:router', 'service:router', 'tests');

        return $modelId;
    }

    private function model(string $modelId): void
    {
        AiCodebaseWorldModel::query()->create([
            'model_id' => $modelId,
            'scope' => 'atlas-server',
            'status' => 'built',
            'capabilities' => ['routing', 'debug'],
            'risks' => ['context_drift'],
            'receipt' => ['schema_version' => 'atlas.ai.autonomous_engineering.codebase_world_model.v1'],
            'model_hash' => hash('sha256', $modelId),
        ]);
    }

    /**
     * @param  array<int,string>  $capabilities
     */
    private function node(
        string $modelId,
        string $nodeId,
        string $nodeType,
        string $path,
        string $flowId,
        array $capabilities,
    ): void {
        $model = AiCodebaseWorldModel::query()->where('model_id', $modelId)->firstOrFail();
        AiCodebaseWorldModelNode::query()->create([
            'world_model_id' => $model->id,
            'node_id' => $nodeId,
            'node_type' => $nodeType,
            'path' => $path,
            'flow_id' => $flowId,
            'capabilities' => $capabilities,
            'risks' => [],
            'metadata' => ['seeded_by' => 'GraphRetrievalNetworkTest'],
        ]);
    }

    private function edge(string $modelId, string $from, string $to, string $type): void
    {
        $model = AiCodebaseWorldModel::query()->where('model_id', $modelId)->firstOrFail();
        AiCodebaseWorldModelEdge::query()->create([
            'world_model_id' => $model->id,
            'from_node_id' => $from,
            'to_node_id' => $to,
            'edge_type' => $type,
            'metadata' => ['seeded_by' => 'GraphRetrievalNetworkTest'],
        ]);
    }

    private function bootWorldModelSchema(): void
    {
        $this->dropWorldModelSchema();
        (require database_path('migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php'))->up();
    }

    private function dropWorldModelSchema(): void
    {
        foreach ([
            'ai_autonomous_engineering_certifications',
            'ai_rivals_shadow_runs',
            'ai_engineering_control_plane_events',
            'ai_repair_loops',
            'ai_execution_plans',
            'ai_mandatory_rag_gates',
            'ai_codebase_world_model_edges',
            'ai_codebase_world_model_nodes',
            'ai_codebase_world_models',
            'ai_autonomous_work_steps',
            'ai_autonomous_work_cycles',
            'ai_autonomous_engineering_goals',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
