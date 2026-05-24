<?php

namespace Tests\Unit\Ai\RealExecution;

use App\Models\AiRealExecutionDeliveryPack;
use App\Models\AiRealExecutionForgeHandoff;
use App\Models\AiRealExecutionPatchRun;
use App\Models\AiRealExecutionRepairAttempt;
use App\Models\AiRealExecutionRivalsBenchmark;
use App\Models\AiRealExecutionTestRun;
use App\Models\AiRealExecutionWorktree;
use App\Services\Ai\RealExecution\AtlasRealEngineeringExecutionKernelService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasRealEngineeringExecutionKernelServiceTest extends TestCase
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
        File::deleteDirectory(storage_path('app/atlas-real-execution'));
        File::deleteDirectory(storage_path('app/atlas-real-execution-test-forge-pack'));
        $this->dropRealExecutionSchema();
        $this->dropAutonomousSchema();
        $this->dropCompoundingSchema();

        parent::tearDown();
    }

    public function test_kernel_creates_isolated_worktree_patch_test_delivery_and_certification(): void
    {
        $result = app(AtlasRealEngineeringExecutionKernelService::class)->run('implemente smoke real de engenharia com patch e teste');

        $this->assertSame('completed', $result['status']);
        $this->assertSame('ready', data_get($result, 'worktree.status'));
        $this->assertSame('sandbox_worktree', data_get($result, 'worktree.isolation_mode'));
        $this->assertFileExists(data_get($result, 'worktree.base_path').'/runtime/atlas_real_execution_smoke.php');
        $this->assertSame('applied', data_get($result, 'patch_run.status'));
        $this->assertSame('passed', data_get($result, 'patch_run.scope_guard.status'));
        $this->assertSame('passed', data_get($result, 'test_run.status'));
        $this->assertTrue(data_get($result, 'test_run.receipt.actual_gate_executed'));
        $this->assertStringContainsString('php -l', data_get($result, 'test_run.selected_tests.0'));
        $this->assertStringContainsString('No syntax errors', data_get($result, 'test_run.output_excerpt'));
        $this->assertSame('ready_for_internal_use', data_get($result, 'delivery_pack.status'));
        $this->assertSame('passed', data_get($result, 'certification.status'));
        $this->assertTrue(AiRealExecutionWorktree::query()->exists());
        $this->assertTrue(AiRealExecutionPatchRun::query()->exists());
        $this->assertTrue(AiRealExecutionTestRun::query()->where('status', 'passed')->exists());
        $this->assertTrue(AiRealExecutionDeliveryPack::query()->exists());
    }

    public function test_repair_autonomy_records_failure_repair_and_passed_rerun(): void
    {
        $result = app(AtlasRealEngineeringExecutionKernelService::class)->run('implemente smoke real com repair autonomo', [
            'test_status' => 'failed',
        ]);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('repaired', data_get($result, 'repair_attempt.status'));
        $this->assertSame('focused_test_failure', data_get($result, 'repair_attempt.failure_class'));
        $this->assertSame('passed', data_get($result, 'test_run.status'));
        $this->assertTrue(AiRealExecutionTestRun::query()->where('status', 'failed')->exists());
        $this->assertTrue(AiRealExecutionTestRun::query()->where('status', 'passed')->exists());
        $this->assertTrue(AiRealExecutionRepairAttempt::query()->where('status', 'repaired')->exists());
    }

    public function test_enterprise_goal_creates_forge_handoff_packet(): void
    {
        $result = app(AtlasRealEngineeringExecutionKernelService::class)->run('refatore todo o subsistema enterprise em obra multi-ciclo com worktree real');

        $this->assertSame('atlas_forge', data_get($result, 'goal.promotion_target'));
        $this->assertSame('ready_for_forge', data_get($result, 'forge_handoff.status'));
        $this->assertSame('continue_in_atlas_forge', data_get($result, 'next_action'));
        $this->assertSame('atlas_forge', data_get($result, 'forge_handoff.handoff_packet.target'));
        $this->assertTrue(AiRealExecutionForgeHandoff::query()->where('status', 'ready_for_forge')->exists());
    }

    public function test_rivals_benchmark_blocks_false_claim_against_claude_and_codex(): void
    {
        $result = app(AtlasRealEngineeringExecutionKernelService::class)->run('implemente smoke real com benchmark shadow');

        $this->assertTrue(data_get($result, 'rivals_benchmark.false_claim_blocked'));
        $this->assertFalse(data_get($result, 'rivals_benchmark.comparison_protocol.claim_allowed'));
        $this->assertSame('ok', data_get($result, 'rivals_benchmark.receipt.provider_arena_readiness.status'));
        $this->assertContains(data_get($result, 'rivals_benchmark.receipt.provider_arena_readiness.claude_codex_pair_status'), [
            'real_run_ready_after_confirmations',
            'plan_ready_evidence_disk_blocked',
        ]);
        $this->assertSame('blocked_pending_operator_confirmations', data_get($result, 'rivals_benchmark.receipt.external_benchmark_gate.status'));
        $this->assertFalse(data_get($result, 'rivals_benchmark.receipt.external_benchmark_gate.external_provider_call'));
        $this->assertFalse(data_get($result, 'certification.claim_policy.ready_to_claim_100x_vs_claude_codex'));
        $this->assertTrue(AiRealExecutionRivalsBenchmark::query()->where('false_claim_blocked', true)->exists());
    }

    public function test_full_certification_blocks_until_external_rivals_benchmark_is_executed(): void
    {
        app(AtlasRealEngineeringExecutionKernelService::class)->run('implemente smoke real com benchmark externo pendente');

        $certification = app(AtlasRealEngineeringExecutionKernelService::class)->certify(scope: 'full');

        $this->assertSame('blocked', $certification->status);
        $this->assertSame('full', $certification->scope);
        $this->assertContains('external_rivals_benchmark_executed', $certification->blockers);
        $this->assertFalse(data_get($certification, 'claim_policy.ready_to_claim_100x_vs_claude_codex'));
    }

    public function test_external_rivals_import_requires_real_provider_evidence(): void
    {
        app(AtlasRealEngineeringExecutionKernelService::class)->run('implemente smoke real com evidencia externa invalida');

        $result = app(AtlasRealEngineeringExecutionKernelService::class)->importExternalRivalsBenchmark([
            'run_id' => 'arena_fake',
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'rivals' => ['claude_code', 'codex_cli'],
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('external_provider_call_not_true', $result['blockers']);
        $this->assertContains('provider_tokens_spent_not_true', $result['blockers']);
        $this->assertContains('external_evidence_refs_missing', $result['blockers']);
    }

    public function test_external_rivals_blocker_attempt_is_persisted_without_unlocking_full_certification(): void
    {
        app(AtlasRealEngineeringExecutionKernelService::class)->run('implemente smoke real com benchmark externo bloqueado');

        $result = app(AtlasRealEngineeringExecutionKernelService::class)->importExternalRivalsBenchmark([
            'status' => 'blocked',
            'run_id' => 'arena_real_timeout_001',
            'external_provider_call' => true,
            'provider_tokens_spent' => false,
            'rivals' => ['claude_code', 'codex_cli'],
            'blockers' => ['provider_runtime_hung_no_artifacts'],
            'evidence_refs' => ['process:claude_code_started'],
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('external_blocked', data_get($result, 'rivals_benchmark.status'));
        $this->assertContains('provider_runtime_hung_no_artifacts', $result['blockers']);
        $this->assertSame('blocked', data_get($result, 'certification.status'));
        $this->assertContains('external_rivals_benchmark_executed', data_get($result, 'certification.blockers'));
    }

    public function test_external_rivals_import_unlocks_full_certification_without_unlocking_100x_claim_by_default(): void
    {
        app(AtlasRealEngineeringExecutionKernelService::class)->run('implemente smoke real com evidencia externa valida');

        $result = app(AtlasRealEngineeringExecutionKernelService::class)->importExternalRivalsBenchmark([
            'run_id' => 'arena_real_001',
            'external_provider_call' => true,
            'provider_tokens_spent' => true,
            'rivals' => ['claude_code', 'codex_cli'],
            'arm_a' => ['arm_id' => 'claude_code'],
            'arm_b' => ['arm_id' => 'codex_cli'],
            'evidence_refs' => ['scorecard:sha256-real-scorecard'],
            'claim_allowed' => false,
        ]);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('external_executed', data_get($result, 'rivals_benchmark.status'));
        $this->assertSame('passed', data_get($result, 'certification.status'));
        $this->assertSame('full', data_get($result, 'certification.scope'));
        $this->assertFalse(data_get($result, 'certification.claim_policy.ready_to_claim_100x_vs_claude_codex'));
    }

    public function test_external_rivals_import_accepts_native_forge_evidence_pack_shape(): void
    {
        app(AtlasRealEngineeringExecutionKernelService::class)->run('implemente smoke real com evidence pack nativo do forge');

        $root = storage_path('app/atlas-real-execution-test-forge-pack');
        File::ensureDirectoryExists($root);
        File::put($root.'/manifest.json', json_encode([
            'run_id' => 'arena_native_pack_001',
            'external_provider_call' => true,
            'provider_tokens_spent' => true,
            'atlas_model' => 'claude_opus',
            'rival_model' => 'codex',
        ], JSON_THROW_ON_ERROR));
        File::put($root.'/scorecard.json', json_encode([
            'claim_ready' => false,
        ], JSON_THROW_ON_ERROR));

        $result = app(AtlasRealEngineeringExecutionKernelService::class)->importExternalRivalsBenchmark([
            'schema_version' => 'atlas.forge.rivals.evidence_pack.v2',
            'artifacts' => [
                'manifest' => ['path' => $root.'/manifest.json', 'sha256' => hash_file('sha256', $root.'/manifest.json')],
                'scorecard' => ['path' => $root.'/scorecard.json', 'sha256' => hash_file('sha256', $root.'/scorecard.json')],
            ],
        ]);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('external_executed', data_get($result, 'rivals_benchmark.status'));
        $this->assertSame('passed', data_get($result, 'certification.status'));
    }

    public function test_certification_blocks_without_real_execution_evidence(): void
    {
        $certification = app(AtlasRealEngineeringExecutionKernelService::class)->certify();

        $this->assertSame('blocked', $certification->status);
        $this->assertContains('worktree_ready', $certification->blockers);
        $this->assertContains('patch_applied', $certification->blockers);
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
