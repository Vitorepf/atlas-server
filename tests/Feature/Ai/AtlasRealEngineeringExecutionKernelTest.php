<?php

namespace Tests\Feature\Ai;

use App\Models\AiRealExecutionDeliveryPack;
use App\Models\AiRealExecutionPatchRun;
use App\Models\AiRealExecutionRivalsBenchmark;
use App\Models\AiRealExecutionTestRun;
use App\Models\AiRealExecutionWorktree;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasRealEngineeringExecutionKernelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $this->bootCompoundingSchema();
        $this->bootAutonomousSchema();
        $this->bootRealExecutionSchema();
    }

    protected function tearDown(): void
    {
        File::delete(storage_path('app/atlas-real-execution-test-evidence.json'));
        File::deleteDirectory(storage_path('app/atlas-real-execution'));
        $this->dropRealExecutionSchema();
        $this->dropAutonomousSchema();
        $this->dropCompoundingSchema();

        parent::tearDown();
    }

    public function test_command_runs_real_execution_kernel_end_to_end(): void
    {
        $readinessExit = Artisan::call('atlas:ai:real-engineering-kernel', ['action' => 'readiness', '--json' => true]);
        $readiness = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $readinessExit);
        $this->assertSame('passed', $readiness['status']);
        $this->assertContains('atlas.ai.real_execution.delivery_pack.v1', $readiness['contracts']);

        $runExit = Artisan::call('atlas:ai:real-engineering-kernel', [
            'action' => 'run',
            '--goal' => 'implemente um smoke real de engenharia com worktree, patch, teste e delivery pack',
            '--json' => true,
        ]);
        $run = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $runExit);
        $this->assertSame('completed', $run['status']);
        $this->assertSame('completed', data_get($run, 'autonomous_preflight.status'));
        $this->assertSame('ready', data_get($run, 'worktree.status'));
        $this->assertSame('applied', data_get($run, 'patch_run.status'));
        $this->assertSame('passed', data_get($run, 'test_run.status'));
        $this->assertSame('ready_for_internal_use', data_get($run, 'delivery_pack.status'));
        $this->assertSame('shadow_ready', data_get($run, 'rivals_benchmark.status'));
        $this->assertSame('passed', data_get($run, 'certification.status'));
        $this->assertNotEmpty(data_get($run, 'delivery_pack.evidence_refs'));

        $controlExit = Artisan::call('atlas:ai:real-engineering-kernel', ['action' => 'control-plane', '--json' => true]);
        $control = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $controlExit);
        $this->assertSame('ready', $control['status']);
        $this->assertSame(1, data_get($control, 'counts.worktrees'));
        $this->assertSame(1, data_get($control, 'observability.false_claims_blocked'));

        $certifyExit = Artisan::call('atlas:ai:real-engineering-kernel', ['action' => 'certify', '--json' => true]);
        $certification = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $certifyExit);
        $this->assertSame('passed', $certification['status']);
        $this->assertSame([], $certification['blockers']);

        $fullCertifyExit = Artisan::call('atlas:ai:real-engineering-kernel', ['action' => 'certify', '--scope' => 'full', '--json' => true]);
        $fullCertification = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $fullCertifyExit);
        $this->assertSame('blocked', $fullCertification['status']);
        $this->assertSame('full', $fullCertification['scope']);
        $this->assertContains('external_rivals_benchmark_executed', $fullCertification['blockers']);
    }

    public function test_runtime_persists_all_real_execution_contracts(): void
    {
        Artisan::call('atlas:ai:real-engineering-kernel', [
            'action' => 'run',
            '--goal' => 'implemente outro smoke real de engenharia',
            '--json' => true,
        ]);

        $this->assertTrue(AiRealExecutionWorktree::query()->where('schema_version', 'atlas.ai.real_execution.worktree.v1')->exists());
        $this->assertTrue(AiRealExecutionPatchRun::query()->where('schema_version', 'atlas.ai.real_execution.patch_run.v1')->where('status', 'applied')->exists());
        $this->assertTrue(AiRealExecutionTestRun::query()->where('schema_version', 'atlas.ai.real_execution.test_run.v1')->where('status', 'passed')->exists());
        $this->assertTrue(AiRealExecutionDeliveryPack::query()->where('schema_version', 'atlas.ai.real_execution.delivery_pack.v1')->where('status', 'ready_for_internal_use')->exists());
        $this->assertTrue(AiRealExecutionRivalsBenchmark::query()->where('schema_version', 'atlas.ai.real_execution.rivals_benchmark.v1')->where('false_claim_blocked', true)->exists());
    }

    public function test_command_imports_external_rivals_evidence_pack(): void
    {
        Artisan::call('atlas:ai:real-engineering-kernel', [
            'action' => 'run',
            '--goal' => 'implemente smoke real para importar evidencia externa',
            '--json' => true,
        ]);
        $path = storage_path('app/atlas-real-execution-test-evidence.json');
        File::put($path, json_encode([
            'run_id' => 'arena_real_command_001',
            'external_provider_call' => true,
            'provider_tokens_spent' => true,
            'rivals' => ['claude_code', 'codex_cli'],
            'arm_a' => ['arm_id' => 'claude_code'],
            'arm_b' => ['arm_id' => 'codex_cli'],
            'evidence_paths' => ['storage/app/forge-rivals/arena_real_command_001/scorecard.json'],
            'claim_allowed' => false,
        ], JSON_THROW_ON_ERROR));

        $exit = Artisan::call('atlas:ai:real-engineering-kernel', [
            'action' => 'import-external-benchmark',
            '--evidence-file' => $path,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('completed', $payload['status']);
        $this->assertSame('external_executed', data_get($payload, 'rivals_benchmark.status'));
        $this->assertSame('passed', data_get($payload, 'certification.status'));
        $this->assertSame('full', data_get($payload, 'certification.scope'));
    }

    private function bootRealExecutionSchema(): void
    {
        $this->dropRealExecutionSchema();
        (require database_path('migrations/2026_05_17_230000_create_ai_real_engineering_execution_kernel_tables.php'))->up();
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
