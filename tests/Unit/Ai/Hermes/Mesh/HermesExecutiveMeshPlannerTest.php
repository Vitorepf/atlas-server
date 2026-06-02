<?php

namespace Tests\Unit\Ai\Hermes\Mesh;

use App\Models\AiJob;
use App\Services\Ai\Hermes\Mesh\HermesExecutiveMeshPlanner;
use Illuminate\Support\Str;
use Tests\TestCase;

class HermesExecutiveMeshPlannerTest extends TestCase
{
    private function planner(): HermesExecutiveMeshPlanner
    {
        return app(HermesExecutiveMeshPlanner::class);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function job(?string $traceId = null, array $payload = []): AiJob
    {
        return new AiJob([
            'trace_id' => $traceId ?? (string) Str::uuid(),
            'payload' => $payload,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function mission(): array
    {
        return ['mission_id' => 'mission-1', 'mission_hash' => 'mh-1'];
    }

    /**
     * @return array<string,mixed>
     */
    private function enabledPolicy(): array
    {
        return ['enabled' => true, 'policy' => 'atlas_adapter'];
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function assertGovernanceInvariants(array $receipt): void
    {
        $this->assertSame('atlas.hermes.mesh_plan.v1', data_get($receipt, 'schema_version'));
        $this->assertSame('atlas', data_get($receipt, 'authority'));
        $this->assertSame('atlas', data_get($receipt, 'mesh_authority'));
        $this->assertFalse((bool) data_get($receipt, 'hermes_mesh_can_decide'));
        $this->assertTrue((bool) data_get($receipt, 'fail_closed'));
        // Sealed last, always present.
        $this->assertIsString(data_get($receipt, 'receipt_hash'));
        $this->assertNotEmpty(data_get($receipt, 'receipt_hash'));
    }

    public function test_approved_path_enables_mesh_and_dispatch(): void
    {
        $receipt = $this->planner()->plan(
            $this->job(),
            $this->mission(),
            [
                ['objective' => 'build the api', 'role' => 'backend', 'worktree' => true, 'success_criteria' => ['tests green']],
                ['objective' => 'build the ui', 'role' => 'frontend'],
            ],
            $this->enabledPolicy(),
        );

        $this->assertGovernanceInvariants($receipt);
        $this->assertTrue((bool) data_get($receipt, 'mesh_enabled'));
        $this->assertTrue((bool) data_get($receipt, 'dispatch_allowed_now'));
        $this->assertNull(data_get($receipt, 'blocked_reason'));
        $this->assertSame('mesh_planned', data_get($receipt, 'status'));
        $this->assertCount(2, data_get($receipt, 'children'));
        $this->assertSame(2, data_get($receipt, 'max_parallel_workers'));

        $first = data_get($receipt, 'children.0');
        $this->assertSame(0, data_get($first, 'index'));
        $this->assertSame('backend', data_get($first, 'role'));
        $this->assertTrue((bool) data_get($first, 'assigned_worktree'));
        $this->assertTrue((bool) data_get($first, 'checkpoints'));
        $this->assertSame(1, data_get($first, 'success_criteria_count'));
    }

    public function test_objective_is_hashed_never_raw(): void
    {
        $objective = 'super secret objective text that must never leak';
        $receipt = $this->planner()->plan(
            $this->job(),
            $this->mission(),
            [['objective' => $objective, 'role' => 'worker']],
            $this->enabledPolicy(),
        );

        $encoded = json_encode($receipt);
        $this->assertStringNotContainsString('super secret objective', (string) $encoded);

        $hash = data_get($receipt, 'children.0.objective_hash');
        $this->assertIsString($hash);
        $this->assertSame(64, strlen($hash));
    }

    public function test_default_off_policy_fails_closed(): void
    {
        $receipt = $this->planner()->plan(
            $this->job(),
            $this->mission(),
            [['objective' => 'do work', 'role' => 'worker']],
            // Off / missing policy.
            [],
        );

        $this->assertGovernanceInvariants($receipt);
        $this->assertFalse((bool) data_get($receipt, 'mesh_enabled'));
        $this->assertFalse((bool) data_get($receipt, 'dispatch_allowed_now'));
        $this->assertSame('mesh_policy_not_atlas_adapter', data_get($receipt, 'blocked_reason'));
        $this->assertSame(0, data_get($receipt, 'max_parallel_workers'));
    }

    public function test_empty_subtasks_blocked_with_no_subtasks_reason(): void
    {
        $receipt = $this->planner()->plan(
            $this->job(),
            $this->mission(),
            [],
            $this->enabledPolicy(),
        );

        $this->assertFalse((bool) data_get($receipt, 'mesh_enabled'));
        $this->assertFalse((bool) data_get($receipt, 'dispatch_allowed_now'));
        $this->assertSame('no_subtasks', data_get($receipt, 'blocked_reason'));
        $this->assertSame(0, data_get($receipt, 'child_count'));
    }

    public function test_missing_objective_in_a_child_fails_closed(): void
    {
        $receipt = $this->planner()->plan(
            $this->job(),
            $this->mission(),
            [
                ['objective' => 'valid', 'role' => 'a'],
                ['role' => 'b'], // no objective
            ],
            $this->enabledPolicy(),
        );

        $this->assertFalse((bool) data_get($receipt, 'mesh_enabled'));
        $this->assertFalse((bool) data_get($receipt, 'dispatch_allowed_now'));
        $this->assertSame('subtask_objective_missing', data_get($receipt, 'blocked_reason'));
        $this->assertFalse((bool) data_get($receipt, 'children.1.objective_present'));
        $this->assertNull(data_get($receipt, 'children.1.objective_hash'));
    }

    public function test_concurrency_clamped_to_worker_ceiling(): void
    {
        $subtasks = [];
        for ($i = 0; $i < 20; $i++) {
            $subtasks[] = ['objective' => 'task '.$i, 'role' => 'worker'];
        }

        $receipt = $this->planner()->plan($this->job(), $this->mission(), $subtasks, $this->enabledPolicy());

        $this->assertTrue((bool) data_get($receipt, 'mesh_enabled'));
        // Default ceiling = 8.
        $this->assertSame(8, data_get($receipt, 'max_parallel_workers'));
        $this->assertTrue((bool) data_get($receipt, 'concurrency_clamped'));
        $this->assertSame(20, data_get($receipt, 'concurrency_clamp_detail.requested'));
        $this->assertSame(8, data_get($receipt, 'concurrency_clamp_detail.applied'));
        $this->assertSame(20, data_get($receipt, 'child_count'));
    }

    public function test_children_capped_to_max_children_and_truncation_recorded(): void
    {
        config()->set('atlas.ai.providers.hermes_cli.mesh.max_children', 3);

        $subtasks = [];
        for ($i = 0; $i < 10; $i++) {
            $subtasks[] = ['objective' => 'task '.$i, 'role' => 'worker'];
        }

        $receipt = $this->planner()->plan($this->job(), $this->mission(), $subtasks, $this->enabledPolicy());

        $this->assertCount(3, data_get($receipt, 'children'));
        $this->assertSame(3, data_get($receipt, 'child_count'));
        $this->assertSame(10, data_get($receipt, 'requested_subtask_count'));
        $this->assertTrue((bool) data_get($receipt, 'truncated'));
        $this->assertSame(7, data_get($receipt, 'truncated_count'));
        $this->assertSame('mesh_planned_truncated', data_get($receipt, 'status'));
    }

    public function test_leaf_blocked_toolsets_are_stripped(): void
    {
        $receipt = $this->planner()->plan(
            $this->job(),
            $this->mission(),
            [['objective' => 'work', 'role' => 'w', 'toolsets' => ['code_read', 'delegation', 'memory', 'shell']]],
            $this->enabledPolicy(),
        );

        $toolsets = data_get($receipt, 'children.0.requested_toolsets');
        $this->assertContains('code_read', $toolsets);
        $this->assertContains('shell', $toolsets);
        $this->assertNotContains('delegation', $toolsets);
        $this->assertNotContains('memory', $toolsets);
    }

    public function test_receipt_hash_is_deterministic(): void
    {
        $args = [
            $this->job('fixed-trace'),
            $this->mission(),
            [['objective' => 'same', 'role' => 'w']],
            $this->enabledPolicy(),
        ];

        $a = $this->planner()->plan(...$args);
        $b = $this->planner()->plan(...$args);

        $this->assertSame(data_get($a, 'receipt_hash'), data_get($b, 'receipt_hash'));
    }

    public function test_invalid_ceiling_config_falls_back_safely(): void
    {
        config()->set('atlas.ai.providers.hermes_cli.mesh.max_parallel_workers', 'not-a-number');
        config()->set('atlas.ai.providers.hermes_cli.mesh.max_children', -5);

        $receipt = $this->planner()->plan(
            $this->job(),
            $this->mission(),
            [['objective' => 'work', 'role' => 'w']],
            $this->enabledPolicy(),
        );

        // worker ceiling falls back to default 8; clamped to >=1 for max_children.
        $this->assertSame(8, data_get($receipt, 'worker_ceiling'));
        $this->assertGreaterThanOrEqual(1, data_get($receipt, 'max_children_ceiling'));
        $this->assertTrue((bool) data_get($receipt, 'mesh_enabled'));
    }
}
