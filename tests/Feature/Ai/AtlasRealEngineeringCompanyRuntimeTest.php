<?php

namespace Tests\Feature\Ai;

use App\Models\AiEngineeringCompanyEngagement;
use App\Models\AiEngineeringCompanyRoleRun;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasRealEngineeringCompanyRuntimeTest extends TestCase
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

    public function test_command_runs_engineering_company_runtime_end_to_end(): void
    {
        $readinessExit = Artisan::call('atlas:ai:engineering-company', ['action' => 'readiness', '--json' => true]);
        $readiness = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $readinessExit);
        $this->assertSame('passed', $readiness['status']);
        $this->assertContains('atlas.ai.engineering_company.certification.v1', $readiness['contracts']);

        $runExit = Artisan::call('atlas:ai:engineering-company', [
            'action' => 'run',
            '--goal' => 'execute um smoke company runtime com equipe, review, qa e release',
            '--json' => true,
        ]);
        $run = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $runExit);
        $this->assertSame('completed', $run['status']);
        $this->assertCount(9, $run['roles']);
        $this->assertSame('completed', data_get($run, 'real_execution.status'));
        $this->assertSame('ready_for_internal_delivery', data_get($run, 'release_pack.status'));
        $this->assertSame('passed', data_get($run, 'certification.status'));

        $controlExit = Artisan::call('atlas:ai:engineering-company', ['action' => 'control-plane', '--json' => true]);
        $control = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $controlExit);
        $this->assertSame('ready', $control['status']);
        $this->assertSame(1, data_get($control, 'counts.engagements'));
        $this->assertSame(9, data_get($control, 'counts.role_runs'));

        $certifyExit = Artisan::call('atlas:ai:engineering-company', ['action' => 'certify', '--json' => true]);
        $certification = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $certifyExit);
        $this->assertSame('passed', $certification['status']);
        $this->assertSame([], $certification['blockers']);
    }

    public function test_runtime_persists_company_contracts(): void
    {
        Artisan::call('atlas:ai:engineering-company', [
            'action' => 'run',
            '--goal' => 'execute outro smoke company runtime',
            '--json' => true,
        ]);

        $this->assertTrue(AiEngineeringCompanyEngagement::query()->where('schema_version', 'atlas.ai.engineering_company.engagement.v1')->where('status', 'completed')->exists());
        $this->assertSame(9, AiEngineeringCompanyRoleRun::query()->where('schema_version', 'atlas.ai.engineering_company.role_run.v1')->distinct('role_id')->count('role_id'));
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
