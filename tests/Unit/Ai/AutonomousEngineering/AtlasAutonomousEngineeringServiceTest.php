<?php

namespace Tests\Unit\Ai\AutonomousEngineering;

use App\Models\AiAutonomousEngineeringGoal;
use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AiCodebaseWorldModelNode;
use App\Models\AiExecutionPlan;
use App\Models\AiMandatoryRagGate;
use App\Models\AiRepairLoop;
use App\Models\AiRivalsShadowRun;
use App\Services\Ai\AutonomousEngineering\AtlasAutonomousEngineeringService;
use App\Services\Ai\Compounding\AtlasCompoundingRuntimeService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAutonomousEngineeringServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $this->bootCompoundingSchema();
        $this->bootAutonomousSchema();
    }

    protected function tearDown(): void
    {
        $this->dropAutonomousSchema();
        $this->dropCompoundingSchema();

        parent::tearDown();
    }

    public function test_world_model_creates_queryable_nodes_and_edges(): void
    {
        $service = app(AtlasAutonomousEngineeringService::class);
        $result = $service->run('implemente um ajuste leve no router com evidência');

        $this->assertSame('completed', $result['status']);
        $this->assertGreaterThanOrEqual(3, AiCodebaseWorldModelNode::query()->count());
        $this->assertGreaterThanOrEqual(1, AiCodebaseWorldModelEdge::query()->count());
        $this->assertNotEmpty($service->queryWorldModel('flow', 'atlas_router'));
        $this->assertNotEmpty($service->queryWorldModel('flow', 'atlas_autonomous_engineering'));
        $this->assertNotEmpty($service->queryWorldModel('file', 'docs/engineering-knowledge-base'));
        $this->assertTrue(AiCodebaseWorldModelNode::query()->where('node_type', 'command')->exists());
        $this->assertTrue(AiCodebaseWorldModelNode::query()->where('node_type', 'migration')->exists());
        $this->assertTrue(AiCodebaseWorldModelNode::query()->where('node_type', 'service')->exists());
        $this->assertNotEmpty($service->queryWorldModel('capability', 'docs'));
    }

    public function test_rag_gate_blocks_execution_when_context_is_insufficient(): void
    {
        $result = app(AtlasAutonomousEngineeringService::class)->run('corrija o debug sem contexto suficiente', [
            'context_sufficiency' => 35,
        ]);

        $this->assertSame('accepted', data_get($result, 'goal.status'));
        $this->assertSame('blocked', data_get($result, 'rag_gate.status'));
        $this->assertNull($result['execution_plan']);
        $this->assertNull($result['work_step']);
        $this->assertSame('insufficient_context', data_get($result, 'repair_loop.failure_class'));
        $this->assertSame('blocked', data_get($result, 'certification.status'));
        $this->assertTrue(AiMandatoryRagGate::query()->where('status', 'blocked')->exists());
    }

    public function test_certification_blocks_completed_goal_without_evidence_or_outcome(): void
    {
        $goal = AiAutonomousEngineeringGoal::query()->create([
            'goal_id' => 'aegoal_missing_evidence',
            'goal' => 'meta inválida marcada como completa',
            'status' => 'completed',
            'intent_flow_id' => 'atlas_dev',
            'promotion_target' => 'atlas_dev',
            'evidence_refs' => [],
            'receipt' => ['schema_version' => AtlasAutonomousEngineeringService::GOAL_SCHEMA],
            'receipt_hash' => str_repeat('a', 64),
        ]);

        $certification = app(AtlasAutonomousEngineeringService::class)->certify($goal);

        $this->assertSame('blocked', $certification->status);
        $this->assertContains('completed_goal_missing_evidence_or_outcome', $certification->blockers);
    }

    public function test_execution_planner_promotes_large_enterprise_goal_to_forge(): void
    {
        $result = app(AtlasAutonomousEngineeringService::class)->run('refatore todo o subsistema enterprise em obra multi-ciclo com evidência');

        $this->assertSame('atlas_forge', data_get($result, 'goal.promotion_target'));
        $this->assertSame('atlas_forge', data_get($result, 'execution_plan.target_flow_id'));
        $this->assertTrue(data_get($result, 'cycle.objective.decomposition.forge_promotion_required'));
        $this->assertSame(3, data_get($result, 'cycle.objective.decomposition.cycle_count'));
        $this->assertTrue(AiExecutionPlan::query()->where('target_flow_id', 'atlas_forge')->exists());
    }

    public function test_repair_loop_records_failure_repair_step_and_outcome(): void
    {
        $result = app(AtlasAutonomousEngineeringService::class)->run('implemente um smoke autônomo que falha e repara', [
            'step_status' => 'failed',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('test_failure', data_get($result, 'repair_loop.failure_class'));
        $this->assertContains('create_repair_step', data_get($result, 'repair_loop.repair_steps'));
        $this->assertSame('failed', data_get($result, 'compounding.outcome.outcome_status'));
        $this->assertTrue(AiRepairLoop::query()->where('failure_class', 'test_failure')->exists());
    }

    public function test_rivals_shadow_mode_never_allows_false_claim(): void
    {
        $result = app(AtlasAutonomousEngineeringService::class)->run('implemente endpoint simples com comparação auditável');

        $this->assertTrue(data_get($result, 'rivals_shadow_run.false_claim_blocked'));
        $this->assertFalse(data_get($result, 'certification.claim_policy.ready_to_claim_100x_vs_claude_codex'));
        $this->assertTrue(AiRivalsShadowRun::query()->where('false_claim_blocked', true)->exists());
    }

    public function test_router_and_compounding_memory_influence_execution_plan(): void
    {
        app(AtlasCompoundingRuntimeService::class)->recordExecution([
            'run_id' => 'autonomous-memory-seed',
            'flow_id' => 'atlas_dev',
            'outcome_status' => 'passed',
            'flow_quality' => 86,
            'retrieval_quality' => 80,
            'execution_quality' => 82,
            'evidence_quality' => 90,
            'evidence_refs' => ['test:autonomous-memory-seed', 'receipt:autonomous-memory-seed'],
            'learning_signal' => [
                'claim' => 'Atlas Dev should reuse autonomous execution contracts for programming goals.',
                'memory_type' => 'routing_memory',
                'scope' => 'atlas-server',
                'confidence' => 88,
                'flow_id' => 'atlas_dev',
                'evidence_refs' => ['test:autonomous-memory-seed', 'receipt:autonomous-memory-seed'],
            ],
        ]);

        $result = app(AtlasAutonomousEngineeringService::class)->run('implemente uma melhoria de programação no endpoint');

        $this->assertSame('atlas_dev', data_get($result, 'execution_plan.target_flow_id'));
        $this->assertNotEmpty(data_get($result, 'execution_plan.compounding_memories'));
        $this->assertSame(
            'Atlas Dev should reuse autonomous execution contracts for programming goals.',
            data_get($result, 'execution_plan.compounding_memories.0.claim'),
        );
    }

    private function bootAutonomousSchema(): void
    {
        $this->dropAutonomousSchema();
        (require database_path('migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php'))->up();
    }

    private function dropAutonomousSchema(): void
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

    private function bootCompoundingSchema(): void
    {
        $this->dropCompoundingSchema();
        (require database_path('migrations/2026_05_17_180000_create_ai_compounding_engineering_intelligence_tables.php'))->up();
    }

    private function dropCompoundingSchema(): void
    {
        foreach ([
            'ai_temporal_certifications',
            'ai_benchmark_cases',
            'ai_rag_feedback_events',
            'ai_heuristic_updates',
            'ai_compounding_memories',
            'ai_learning_candidates',
            'ai_run_outcomes',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
