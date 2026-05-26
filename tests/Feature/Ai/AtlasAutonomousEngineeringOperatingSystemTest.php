<?php

namespace Tests\Feature\Ai;

use App\Models\AiAutonomousEngineeringGoal;
use App\Models\AiAutonomousWorkStep;
use App\Models\AiCodebaseWorldModelNode;
use App\Models\AiMandatoryRagGate;
use App\Models\AiRepairLoop;
use App\Models\AiRivalsShadowRun;
use App\Models\PersistentAiExecutionPlan as AiExecutionPlan;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAutonomousEngineeringOperatingSystemTest extends TestCase
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

    public function test_command_runs_goal_end_to_end_and_certifies_runtime_evidence(): void
    {
        $readinessExit = Artisan::call('atlas:ai:autonomous-engineering', ['action' => 'readiness', '--json' => true]);
        $readiness = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $readinessExit);
        $this->assertSame('passed', $readiness['status']);
        $this->assertContains('atlas.ai.autonomous_engineering.goal.v1', $readiness['contracts']);

        $runExit = Artisan::call('atlas:ai:autonomous-engineering', [
            'action' => 'run',
            '--goal' => 'implemente um smoke autônomo de engenharia com evidência',
            '--json' => true,
        ]);
        $run = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $runExit);
        $this->assertSame('completed', $run['status']);
        $this->assertSame('passed', data_get($run, 'rag_gate.status'));
        $this->assertSame('ready', data_get($run, 'execution_plan.status'));
        $this->assertSame('passed', data_get($run, 'work_step.status'));
        $this->assertSame('passed', data_get($run, 'certification.status'));
        $this->assertNotEmpty(data_get($run, 'goal.evidence_refs'));
        $this->assertNotNull(data_get($run, 'goal.outcome_receipt_hash'));
        $this->assertNotNull(data_get($run, 'goal.certification_hash'));

        $controlExit = Artisan::call('atlas:ai:autonomous-engineering', ['action' => 'control-plane', '--json' => true]);
        $control = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $controlExit);
        $this->assertSame('ready', $control['status']);
        $this->assertGreaterThanOrEqual(1, data_get($control, 'counts.goals'));
        $this->assertGreaterThanOrEqual(1, data_get($control, 'counts.rag_gates'));
        $this->assertEquals(82.0, data_get($control, 'observability.average_context_sufficiency'));
        $this->assertSame(1, data_get($control, 'observability.goal_statuses.completed'));
        $this->assertSame(1, data_get($control, 'observability.false_claims_blocked'));

        $certifyExit = Artisan::call('atlas:ai:autonomous-engineering', ['action' => 'certify', '--json' => true]);
        $certification = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $certifyExit);
        $this->assertSame('passed', $certification['status']);
        $this->assertSame([], $certification['blockers']);
    }

    public function test_runtime_persists_required_artifacts_and_contracts(): void
    {
        Artisan::call('atlas:ai:autonomous-engineering', [
            'action' => 'run',
            '--goal' => 'corrija um bug médio usando router, rag, plano, teste e certificação',
            '--json' => true,
        ]);

        $this->assertTrue(AiAutonomousEngineeringGoal::query()->where('schema_version', 'atlas.ai.autonomous_engineering.goal.v1')->exists());
        $this->assertTrue(AiMandatoryRagGate::query()->where('schema_version', 'atlas.ai.autonomous_engineering.rag_gate.v1')->where('status', 'passed')->exists());
        $this->assertTrue(AiExecutionPlan::query()->where('schema_version', 'atlas.ai.autonomous_engineering.execution_plan.v1')->where('status', 'ready')->exists());
        $this->assertTrue(AiAutonomousWorkStep::query()->where('schema_version', 'atlas.ai.autonomous_engineering.work_step.v1')->whereNotNull('evidence_refs')->exists());
        $this->assertTrue(AiCodebaseWorldModelNode::query()->where('path', 'like', '%docs/engineering-knowledge-base%')->exists());
        $this->assertTrue(AiRivalsShadowRun::query()->where('schema_version', 'atlas.ai.autonomous_engineering.rivals_shadow_run.v1')->where('false_claim_blocked', true)->exists());
    }

    public function test_command_blocks_run_without_sufficient_rag_context_and_reports_real_certification_blockers(): void
    {
        $exit = Artisan::call('atlas:ai:autonomous-engineering', [
            'action' => 'run',
            '--goal' => 'execute trabalho sem contexto suficiente',
            '--context-sufficiency' => 20,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('accepted', data_get($payload, 'goal.status'));
        $this->assertSame('blocked', data_get($payload, 'rag_gate.status'));
        $this->assertContains('rag_gate_passed', data_get($payload, 'certification.blockers'));
        $this->assertTrue(AiRepairLoop::query()->where('failure_class', 'insufficient_context')->exists());
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
        (require database_path('migrations/2026_05_19_030000_strengthen_rag_feedback_and_create_learning_proposals.php'))->up();
    }

    private function dropCompoundingSchema(): void
    {
        foreach ([
            'ai_learning_proposals',
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
