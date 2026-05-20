<?php

namespace Tests\Unit\Ai\EngineeringCompany;

use App\Models\AiEngineeringCompanyBenchmark;
use App\Models\AiEngineeringCompanyCertification;
use App\Models\AiEngineeringCompanyEngagement;
use App\Models\AiEngineeringCompanyQaRun;
use App\Models\AiEngineeringCompanyReleasePack;
use App\Models\AiEngineeringCompanyReview;
use App\Models\AiEngineeringCompanyRoleRun;
use App\Services\Ai\EngineeringCompany\AtlasRealEngineeringCompanyRuntimeService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasRealEngineeringCompanyRuntimeServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $this->bootCompoundingSchema();
        $this->bootAutonomousSchema();
        $this->bootRealExecutionSchema();
        $this->bootCompanySchema();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/atlas-real-execution'));
        $this->dropCompanySchema();
        $this->dropRealExecutionSchema();
        $this->dropAutonomousSchema();
        $this->dropCompoundingSchema();

        parent::tearDown();
    }

    public function test_company_runtime_runs_roles_real_execution_review_qa_release_benchmark_and_certification(): void
    {
        $result = app(AtlasRealEngineeringCompanyRuntimeService::class)->run('execute smoke company runtime com equipe completa');

        $this->assertSame('completed', $result['status']);
        $this->assertCount(9, $result['roles']);
        $this->assertSame('completed', data_get($result, 'real_execution.status'));
        $this->assertSame('passed', data_get($result, 'review.status'));
        $this->assertSame('passed', data_get($result, 'qa_run.status'));
        $this->assertSame('ready_for_internal_delivery', data_get($result, 'release_pack.status'));
        $this->assertSame('recorded', data_get($result, 'benchmark.status'));
        $this->assertSame('passed', data_get($result, 'certification.status'));
        $this->assertSame('passed', collect(data_get($result, 'certification.checks'))->firstWhere('id', 'all_roles_have_agent_control_plane_task_packets')['status'] ?? null);
        $this->assertTrue((bool) data_get($result, 'certification.claim_policy.ready_to_claim_autonomous_software_company'));
        $this->assertFalse((bool) data_get($result, 'certification.claim_policy.ready_to_claim_external_superiority'));
        $this->assertFalse((bool) data_get($result, 'certification.claim_policy.external_benchmark_executed'));
        $this->assertFalse((bool) data_get($result, 'certification.claim_policy.rivals_provider_called'));
        $this->assertTrue(AiEngineeringCompanyEngagement::query()->where('status', 'completed')->exists());
        $this->assertSame(9, AiEngineeringCompanyRoleRun::query()->distinct('role_id')->count('role_id'));
        $this->assertTrue(AiEngineeringCompanyReview::query()->where('status', 'passed')->exists());
        $this->assertTrue(AiEngineeringCompanyQaRun::query()->where('status', 'passed')->exists());
        $this->assertTrue(AiEngineeringCompanyReleasePack::query()->where('status', 'ready_for_internal_delivery')->exists());
        $this->assertTrue(AiEngineeringCompanyBenchmark::query()->where('status', 'recorded')->exists());
        $this->assertTrue(AiEngineeringCompanyCertification::query()->where('status', 'passed')->exists());
    }

    public function test_independent_review_failure_blocks_company_delivery(): void
    {
        $result = app(AtlasRealEngineeringCompanyRuntimeService::class)->run('execute smoke company com review bloqueando', [
            'review_status' => 'failed',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('blocked', data_get($result, 'review.status'));
        $this->assertSame('blocked', data_get($result, 'release_pack.status'));
        $this->assertSame('blocked', data_get($result, 'certification.status'));
        $this->assertContains('independent_review_passed', data_get($result, 'certification.blockers'));
    }

    public function test_qa_failure_blocks_company_delivery(): void
    {
        $result = app(AtlasRealEngineeringCompanyRuntimeService::class)->run('execute smoke company com qa bloqueando', [
            'qa_status' => 'failed',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('blocked', data_get($result, 'qa_run.status'));
        $this->assertSame('blocked', data_get($result, 'release_pack.status'));
        $this->assertContains('qa_passed', data_get($result, 'certification.blockers'));
    }

    public function test_enterprise_goal_preserves_forge_promotion_from_real_execution_kernel(): void
    {
        $result = app(AtlasRealEngineeringCompanyRuntimeService::class)->run('refatore todo o subsistema enterprise em obra multi-ciclo pesada');

        $this->assertSame('completed', $result['status']);
        $this->assertSame('atlas_forge', data_get($result, 'real_execution.goal.promotion_target'));
        $this->assertSame('ready_for_forge', data_get($result, 'real_execution.forge_handoff.status'));
        $this->assertSame('ready_for_forge', data_get($result, 'release_pack.summary.forge_handoff'));
    }

    public function test_certification_blocks_without_company_runtime_evidence(): void
    {
        $certification = app(AtlasRealEngineeringCompanyRuntimeService::class)->certify();

        $this->assertSame('blocked', $certification->status);
        $this->assertContains('engagement_exists', $certification->blockers);
    }

    private function bootCompanySchema(): void
    {
        $this->dropCompanySchema();
        (require database_path('migrations/2026_05_17_232000_create_ai_engineering_company_runtime_tables.php'))->up();
    }

    private function dropCompanySchema(): void
    {
        foreach ([
            'ai_engineering_company_certifications',
            'ai_engineering_company_benchmarks',
            'ai_engineering_company_release_packs',
            'ai_engineering_company_qa_runs',
            'ai_engineering_company_reviews',
            'ai_engineering_company_role_runs',
            'ai_engineering_company_cycles',
            'ai_engineering_company_engagements',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function bootRealExecutionSchema(): void
    {
        $this->dropRealExecutionSchema();
        (require database_path('migrations/2026_05_17_230000_create_ai_real_engineering_execution_kernel_tables.php'))->up();
        (require database_path('migrations/2026_05_17_231000_add_scope_to_ai_real_execution_certifications.php'))->up();
    }

    private function dropRealExecutionSchema(): void
    {
        foreach ([
            'ai_real_execution_certifications',
            'ai_real_execution_rivals_benchmarks',
            'ai_real_execution_delivery_packs',
            'ai_real_execution_forge_handoffs',
            'ai_real_execution_repair_attempts',
            'ai_real_execution_test_runs',
            'ai_real_execution_patch_runs',
            'ai_real_execution_worktrees',
        ] as $table) {
            Schema::dropIfExists($table);
        }
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
