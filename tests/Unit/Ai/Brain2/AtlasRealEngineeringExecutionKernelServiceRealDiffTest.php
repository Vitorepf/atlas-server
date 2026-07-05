<?php

namespace Tests\Unit\Ai\Brain2;

use App\Models\AiAutonomousEngineeringGoal;
use App\Models\AiRealExecutionPatchRun;
use App\Models\AiRealExecutionWorktree;
use App\Services\Ai\RealExecution\AtlasRealEngineeringExecutionKernelService;
use App\Services\Ai\RealExecution\RealExecutionHash;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasRealEngineeringExecutionKernelServiceRealDiffTest extends TestCase
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
        $this->dropRealExecutionSchema();
        $this->dropAutonomousSchema();
        $this->dropCompoundingSchema();

        parent::tearDown();
    }

    public function test_execute_patch_applies_real_autonomous_diff_instead_of_fixed_stub(): void
    {
        // Arrange: create a goal and worktree as preconditions for executePatch.
        $goal = AiAutonomousEngineeringGoal::query()->create([
            'goal_id' => 'aegoal_test_real_diff_001',
            'goal' => 'Add a new helper service class',
            'status' => 'completed',
            'intent_flow_id' => 'atlas_dev',
            'promotion_target' => 'atlas_dev',
            'evidence_refs' => [],
            'receipt' => [
                'schema_version' => 'atlas.ai.autonomous_engineering.goal.v1',
                'goal_id' => 'aegoal_test_real_diff_001',
                'goal' => 'Add a new helper service class',
                'status' => 'completed',
                'intent_flow_id' => 'atlas_dev',
                'promotion_target' => 'atlas_dev',
            ],
            'receipt_hash' => hash('sha256', 'aegoal_test_real_diff_001'),
        ]);

        $worktree = AiRealExecutionWorktree::query()->create([
            'goal_record_id' => $goal->id,
            'worktree_id' => 'aerewt_test_real_diff_001',
            'status' => 'ready',
            'base_path' => storage_path('app/atlas-real-execution/aerewt_test_real_diff_001'),
            'branch_name' => 'atlas-real-exec/aerewt_test_real_diff_001',
            'isolation_mode' => 'sandbox_worktree',
            'allowed_paths' => ['runtime'],
            'forbidden_paths' => ['.env', 'vendor', 'storage', 'database/production'],
            'evidence_refs' => ['goal:aegoal_test_real_diff_001'],
            'receipt' => ['schema_version' => 'atlas.ai.real_execution.worktree.v1', 'worktree_id' => 'aerewt_test_real_diff_001'],
            'receipt_hash' => hash('sha256', 'aerewt_test_real_diff_001'),
        ]);
        File::ensureDirectoryExists($worktree->base_path.'/runtime');

        // The real autonomous diff: two files with their new content.
        $realDiff = [
            'runtime/UserService.php' => "<?php\n\ndeclare(strict_types=1);\n\nclass UserService\n{\n    public function greet(): string\n    {\n        return 'Hello from the autonomous diff!';\n    }\n}\n",
            'runtime/helpers.php' => "<?php\n\nfunction uuid_v4(): string\n{\n    return bin2hex(random_bytes(16));\n}\n",
        ];
        $diffJson = json_encode($realDiff, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $diffHash = RealExecutionHash::make($diffJson);

        $autonomous = [
            'execution_plan' => [
                'plan_hash' => hash('sha256', 'test_plan_real_diff'),
                'diff' => $diffJson,
                'changed_files' => array_keys($realDiff),
            ],
        ];

        // Act
        $patch = app(AtlasRealEngineeringExecutionKernelService::class)
            ->executePatch($goal, $worktree, $autonomous);

        // Assert: receipt records the real diff hash and changed files
        $receipt = $patch->receipt;
        $this->assertSame('applied', $receipt['status']);
        $this->assertSame(['runtime/UserService.php', 'runtime/helpers.php'], $receipt['changed_files']);
        $this->assertSame($diffHash, $receipt['diff_hash']);

        // Assert: files are actually written to the worktree with correct content
        $this->assertFileExists($worktree->base_path.'/runtime/UserService.php');
        $this->assertFileExists($worktree->base_path.'/runtime/helpers.php');
        $this->assertStringContainsString('Hello from the autonomous diff!', File::get($worktree->base_path.'/runtime/UserService.php'));
        $this->assertStringContainsString('function uuid_v4', File::get($worktree->base_path.'/runtime/helpers.php'));

        // Assert: the fixed smoke stub is NOT present
        $this->assertFileDoesNotExist($worktree->base_path.'/runtime/atlas_real_execution_smoke.php');

        // Assert: the DB record carries the real diff data
        $this->assertSame($diffJson, $patch->diff_summary);
        $this->assertSame(['runtime/UserService.php', 'runtime/helpers.php'], $patch->changed_files);
        $this->assertSame($diffHash, $patch->receipt['diff_hash']);

        // Assert: persisted model exists
        $this->assertTrue(AiRealExecutionPatchRun::query()->where('patch_run_id', $patch->patch_run_id)->exists());
    }

    public function test_execute_patch_falls_back_to_fixed_stub_when_no_diff_provided(): void
    {
        // Arrange: goal + worktree with an empty autonomous plan (no diff).
        $goal = AiAutonomousEngineeringGoal::query()->create([
            'goal_id' => 'aegoal_test_real_diff_fallback',
            'goal' => 'Smoke test fallback',
            'status' => 'completed',
            'intent_flow_id' => 'atlas_dev',
            'promotion_target' => 'atlas_dev',
            'evidence_refs' => [],
            'receipt' => ['schema_version' => 'atlas.ai.autonomous_engineering.goal.v1', 'goal_id' => 'aegoal_test_real_diff_fallback'],
            'receipt_hash' => hash('sha256', 'aegoal_test_real_diff_fallback'),
        ]);

        $worktree = AiRealExecutionWorktree::query()->create([
            'goal_record_id' => $goal->id,
            'worktree_id' => 'aerewt_test_diff_fallback',
            'status' => 'ready',
            'base_path' => storage_path('app/atlas-real-execution/aerewt_test_diff_fallback'),
            'branch_name' => 'atlas-real-exec/aerewt_test_diff_fallback',
            'isolation_mode' => 'sandbox_worktree',
            'allowed_paths' => ['runtime'],
            'forbidden_paths' => ['.env', 'vendor', 'storage', 'database/production'],
            'evidence_refs' => ['goal:aegoal_test_real_diff_fallback'],
            'receipt' => ['schema_version' => 'atlas.ai.real_execution.worktree.v1', 'worktree_id' => 'aerewt_test_diff_fallback'],
            'receipt_hash' => hash('sha256', 'aerewt_test_diff_fallback'),
        ]);
        File::ensureDirectoryExists($worktree->base_path.'/runtime');

        $autonomous = [
            'execution_plan' => [
                'plan_hash' => hash('sha256', 'test_plan_fallback'),
                // no 'diff' and no 'changed_files' — triggers the fallback
            ],
        ];

        // Act
        $patch = app(AtlasRealEngineeringExecutionKernelService::class)
            ->executePatch($goal, $worktree, $autonomous);

        // Assert: the fixed smoke stub is written
        $this->assertFileExists($worktree->base_path.'/runtime/atlas_real_execution_smoke.php');
        $this->assertSame(['runtime/atlas_real_execution_smoke.php'], $patch->changed_files);
        $this->assertStringContainsString('smoke payload', $patch->diff_summary);
    }

    // ---------- Schema helpers ----------

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
