<?php

namespace Tests\Unit\Ai\AutonomousEngineering\WorldModel;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AiCodebaseWorldModelNode;
use App\Services\Ai\AutonomousEngineering\WorldModel\WorldModelGraphRanker;
use App\Services\Ai\AutonomousEngineering\WorldModel\WorldModelRankingQuery;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorldModelGraphRankerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->bootSchema();
    }

    protected function tearDown(): void
    {
        $this->dropSchema();

        parent::tearDown();
    }

    public function test_graph_relations_override_pure_textual_top_pick(): void
    {
        // Two candidates with similar textual hit on "router".
        // - decoy: matches text but has zero edges into the seed.
        // - winner: matches text AND has docs/tests/dependency edges into seed.
        $modelId = $this->buildModel('aewm_text_vs_graph');

        $this->node($modelId, 'service:router', 'service', 'app/Services/Ai/Router/RouterService.php', 'atlas_router');
        $this->node($modelId, 'service:router_decoy', 'service', 'app/Services/Notes/RouterNoteService.php', 'atlas_dev');

        $this->node($modelId, 'doc:router-canon', 'doc', 'docs/engineering-knowledge-base/atlas-router.md', 'atlas_research');
        $this->node($modelId, 'test:router-cover', 'test', 'tests/Feature/Ai/RouterCoverageTest.php', 'atlas_router');

        $this->edge($modelId, 'doc:router-canon', 'service:router', 'documents');
        $this->edge($modelId, 'test:router-cover', 'service:router', 'tests');
        $this->edge($modelId, 'service:router', 'service:router_decoy', 'depends_on');

        $query = WorldModelRankingQuery::fromArray([
            'textual_seeds' => ['router'],
            'target_files' => ['app/services/ai/router/routerservice.php'],
            'target_flows' => ['atlas_router'],
        ]);

        $result = app(WorldModelGraphRanker::class)->rank($query);

        $top = collect($result['ranked_nodes']);
        $this->assertSame('service:router', $top->first()['node_id']);

        // The text-only path would pick whichever node_id sorts first for "router".
        $this->assertNotNull($result['metrics']['text_only_top_node']);
        $this->assertNotNull($result['metrics']['graph_top_node']);
        $this->assertSame('service:router', $result['metrics']['graph_top_node']);
        // The graph must contribute real relation evidence across the top:
        // the linked doc is rated as a `governing_doc_for_seed` and the
        // covering test as `test_covers_seed`.
        $allReasons = $top->pluck('reasons')->flatten()->all();
        $this->assertContains('governing_doc_for_seed', $allReasons);
        $this->assertContains('test_covers_seed', $allReasons);

        $serviceRouter = $top->firstWhere('node_id', 'service:router');
        $this->assertNotNull($serviceRouter);
        $this->assertContains('query_target_file_match', $serviceRouter['reasons']);
        $this->assertContains('query_target_flow_match', $serviceRouter['reasons']);
        // service:router has the doc/test edges pointing at it — so it must
        // carry incoming relation_path entries.
        $this->assertNotEmpty($serviceRouter['relation_path']);
    }

    public function test_test_linked_to_seed_file_gets_boost(): void
    {
        $modelId = $this->buildModel('aewm_test_boost');

        $this->node($modelId, 'service:auth', 'service', 'app/Services/Auth/AuthService.php', 'atlas_security');
        $this->node($modelId, 'test:auth-covering', 'test', 'tests/Unit/Auth/AuthServiceTest.php', 'atlas_security');
        $this->node($modelId, 'test:unrelated', 'test', 'tests/Unit/Other/UnrelatedTest.php', 'atlas_dev');

        $this->edge($modelId, 'test:auth-covering', 'service:auth', 'tests');

        $query = WorldModelRankingQuery::fromArray([
            'textual_seeds' => ['auth'],
            'target_files' => ['app/services/auth/authservice.php'],
            'target_flows' => ['atlas_security'],
        ]);

        $result = app(WorldModelGraphRanker::class)->rank($query);
        $byId = collect($result['ranked_nodes'])->keyBy('node_id');

        $this->assertGreaterThan(
            $byId['test:unrelated']['score'],
            $byId['test:auth-covering']['score'],
            'Test linked to the seed via a `tests` edge must outscore an unrelated test.',
        );
        $this->assertContains('test_covers_seed', $byId['test:auth-covering']['reasons']);
        $this->assertNotEmpty($byId['test:auth-covering']['relation_path']);
        $this->assertSame('tests', $byId['test:auth-covering']['relation_path'][0]['edge_type']);
    }

    public function test_governing_doc_outranks_unrelated_doc_for_target_module(): void
    {
        $modelId = $this->buildModel('aewm_doc_boost');

        $this->node($modelId, 'service:payments', 'service', 'app/Services/Payments/PaymentsService.php', 'atlas_dev');
        $this->node($modelId, 'doc:payments-canon', 'doc', 'docs/engineering-knowledge-base/atlas-payments.md', 'atlas_research');
        $this->node($modelId, 'doc:unrelated-canon', 'doc', 'docs/engineering-knowledge-base/atlas-other-topic.md', 'atlas_research');

        $this->edge($modelId, 'doc:payments-canon', 'service:payments', 'documents');

        $query = WorldModelRankingQuery::fromArray([
            'textual_seeds' => ['payments'],
            'target_files' => ['app/services/payments/paymentsservice.php'],
            'boost_docs' => true,
        ]);

        $result = app(WorldModelGraphRanker::class)->rank($query);
        $byId = collect($result['ranked_nodes'])->keyBy('node_id');

        $this->assertGreaterThan(
            $byId['doc:unrelated-canon']['score'],
            $byId['doc:payments-canon']['score'],
        );
        $this->assertContains('governing_doc_for_seed', $byId['doc:payments-canon']['reasons']);
        $this->assertContains('task_requests_doc_boost', $byId['doc:payments-canon']['reasons']);
    }

    public function test_risk_matching_node_gets_extra_boost_on_high_risk_task(): void
    {
        $modelId = $this->buildModel('aewm_risk_boost');

        $this->node($modelId, 'service:safe-utility', 'service', 'app/Services/Util/SafeUtility.php', 'atlas_dev', risks: []);
        $this->node(
            $modelId,
            'migration:risky',
            'migration',
            'database/migrations/2026_05_18_risky_migration.php',
            'atlas_dev',
            risks: ['persistence_change', 'large_scope_promotion'],
        );

        $lowRisk = app(WorldModelGraphRanker::class)->rank(WorldModelRankingQuery::fromArray([
            'textual_seeds' => ['migration'],
            'target_risks' => ['persistence_change'],
            'task_risk_level' => 'low',
        ]));
        $highRisk = app(WorldModelGraphRanker::class)->rank(WorldModelRankingQuery::fromArray([
            'textual_seeds' => ['migration'],
            'target_risks' => ['persistence_change'],
            'task_risk_level' => 'high',
        ]));

        $lowMigration = collect($lowRisk['ranked_nodes'])->firstWhere('node_id', 'migration:risky');
        $highMigration = collect($highRisk['ranked_nodes'])->firstWhere('node_id', 'migration:risky');

        $this->assertNotNull($lowMigration);
        $this->assertNotNull($highMigration);
        $this->assertGreaterThan($lowMigration['score'], $highMigration['score']);
        $this->assertContains('risk_match:persistence_change', $highMigration['reasons']);
    }

    public function test_flow_capability_queries_filter_world_model_meaningfully(): void
    {
        $modelId = $this->buildModel('aewm_flow_cap_query');

        $this->node($modelId, 'service:router-a', 'service', 'app/Services/Ai/Router/RouterService.php', 'atlas_router', capabilities: ['router', 'compounding']);
        $this->node($modelId, 'service:research-a', 'service', 'app/Services/Ai/Research/ResearchService.php', 'atlas_research', capabilities: ['router']);
        $this->node($modelId, 'service:noise', 'service', 'app/Services/Other/Noise.php', 'atlas_dev', capabilities: ['frontend']);

        $byFlow = app(WorldModelGraphRanker::class)->rank(WorldModelRankingQuery::fromArray([
            'target_flows' => ['atlas_router'],
        ]));
        $this->assertSame('service:router-a', $byFlow['ranked_nodes'][0]['node_id']);
        $this->assertContains('query_target_flow_match', $byFlow['ranked_nodes'][0]['reasons']);

        $byCapability = app(WorldModelGraphRanker::class)->rank(WorldModelRankingQuery::fromArray([
            'target_capabilities' => ['router'],
        ]));
        $topTwo = array_slice($byCapability['ranked_nodes'], 0, 2);
        $topIds = array_map(static fn (array $node): string => $node['node_id'], $topTwo);
        $this->assertContains('service:router-a', $topIds);
        $this->assertContains('service:research-a', $topIds);
        $this->assertNotContains('service:noise', $topIds);
    }

    public function test_output_is_json_stable_for_same_query_and_graph(): void
    {
        $modelId = $this->buildModel('aewm_json_stable');

        $this->node($modelId, 'service:router', 'service', 'app/Services/Ai/Router/RouterService.php', 'atlas_router');
        $this->node($modelId, 'doc:router-canon', 'doc', 'docs/engineering-knowledge-base/atlas-router.md', 'atlas_research');
        $this->edge($modelId, 'doc:router-canon', 'service:router', 'documents');

        $query = WorldModelRankingQuery::fromArray([
            'textual_seeds' => ['router'],
            'target_files' => ['app/services/ai/router/routerservice.php'],
            'target_flows' => ['atlas_router'],
            'boost_docs' => true,
            'world_model_id' => $modelId,
        ]);

        $first = app(WorldModelGraphRanker::class)->rank($query);
        $second = app(WorldModelGraphRanker::class)->rank($query);

        $this->assertSame($first['query_signature'], $second['query_signature']);
        $this->assertSame($first['graph_version'], $second['graph_version']);
        $this->assertSame($first['graph_hash'], $second['graph_hash']);
        $this->assertSame($first['result_hash'], $second['result_hash']);

        // Same content, same ordering → encoded JSON must be identical.
        unset($first['generated_at'], $second['generated_at']);
        $firstJson = json_encode($first, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $secondJson = json_encode($second, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertSame($firstJson, $secondJson);

        $top = $first['ranked_nodes'][0];
        $this->assertSame(WorldModelGraphRanker::SCHEMA, $first['schema_version']);
        $this->assertArrayHasKey('confidence', $top);
        $this->assertArrayHasKey('text_score', $top);
        $this->assertArrayHasKey('graph_score', $top);
        $this->assertArrayHasKey('reasons', $top);
        $this->assertArrayHasKey('relation_path', $top);
    }

    public function test_returns_empty_result_when_world_model_missing(): void
    {
        $query = WorldModelRankingQuery::fromArray([
            'textual_seeds' => ['router'],
            'world_model_id' => 'aewm_does_not_exist',
        ]);

        $result = app(WorldModelGraphRanker::class)->rank($query);

        $this->assertSame([], $result['ranked_nodes']);
        $this->assertSame('world_model_not_found', $result['metrics']['fallback_reason']);
        $this->assertNotEmpty($result['result_hash']);
    }

    private function buildModel(string $modelId): string
    {
        AiCodebaseWorldModel::query()->create([
            'model_id' => $modelId,
            'scope' => 'atlas-server',
            'status' => 'built',
            'capabilities' => ['router', 'compounding'],
            'risks' => ['autonomous_execution_requires_rag_gate'],
            'receipt' => ['schema_version' => 'atlas.ai.autonomous_engineering.codebase_world_model.v1'],
            'model_hash' => hash('sha256', $modelId),
        ]);

        return $modelId;
    }

    /**
     * @param  array<int,string>  $capabilities
     * @param  array<int,string>  $risks
     */
    private function node(
        string $modelId,
        string $nodeId,
        string $nodeType,
        string $path,
        ?string $flowId = null,
        array $capabilities = [],
        array $risks = [],
    ): void {
        $model = AiCodebaseWorldModel::query()->where('model_id', $modelId)->firstOrFail();
        AiCodebaseWorldModelNode::query()->create([
            'world_model_id' => $model->id,
            'node_id' => $nodeId,
            'node_type' => $nodeType,
            'path' => $path,
            'flow_id' => $flowId,
            'capabilities' => $capabilities,
            'risks' => $risks,
            'metadata' => ['seeded_by' => 'WorldModelGraphRankerTest'],
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
            'metadata' => ['seeded_by' => 'WorldModelGraphRankerTest'],
        ]);
    }

    private function bootSchema(): void
    {
        $this->dropSchema();
        (require database_path('migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php'))->up();
    }

    private function dropSchema(): void
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
