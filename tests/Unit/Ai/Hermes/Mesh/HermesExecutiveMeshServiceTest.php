<?php

namespace Tests\Unit\Ai\Hermes\Mesh;

use App\Models\AiJob;
use App\Services\Ai\Hermes\HermesDelegationAdapter;
use App\Services\Ai\Hermes\Mesh\HermesCheckpointPolicy;
use App\Services\Ai\Hermes\Mesh\HermesExecutiveMeshPlanner;
use App\Services\Ai\Hermes\Mesh\HermesExecutiveMeshService;
use App\Services\Ai\Hermes\Mesh\HermesMeshReconciler;
use App\Services\Ai\Hermes\Mesh\HermesProfileResolver;
use App\Services\Ai\Hermes\Mesh\MeshWorkerHandle;
use Tests\TestCase;

/**
 * Proves the sovereign orchestration brain of the Executive Mesh: composition,
 * BOUNDED CONCURRENCY (never more than max_parallel_workers run at once),
 * fail-closed gating, end-to-end reconciliation, and receipt sealing — all with
 * a fake worker so no model is ever launched.
 */
class HermesExecutiveMeshServiceTest extends TestCase
{
    private function service(): HermesExecutiveMeshService
    {
        return new HermesExecutiveMeshService(
            new HermesExecutiveMeshPlanner(),
            new HermesProfileResolver(),
            new HermesCheckpointPolicy(),
            new HermesMeshReconciler(),
            new HermesDelegationAdapter(),
        );
    }

    private function job(): AiJob
    {
        return new AiJob([
            'trace_id' => 'mesh-trace-1',
            'payload' => ['hermes' => []],
        ]);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function subtasks(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = [
                'objective' => "child objective number {$i} — do the thing",
                'role' => 'coder',
                'toolsets' => ['file', 'terminal'],
                'worktree' => true,
                'success_criteria' => ['tests green'],
            ];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $scope
     * @return array<string,mixed>
     */
    private function parentMission(array $scope = ['permission_mode' => 'write']): array
    {
        return [
            'schema_version' => 'atlas.hermes.executive_mission.v1',
            'mission_id' => 'mission-parent-1',
            'mission_hash' => str_repeat('a', 64),
            'scope' => $scope,
        ];
    }

    public function test_plan_composes_children_with_profile_and_checkpoint_and_seals(): void
    {
        config()->set('atlas.ai.providers.hermes_cli.mesh.max_parallel_workers', 3);
        config()->set('atlas.ai.providers.hermes_cli.mesh.checkpoint_policy', 'atlas_adapter');

        $plan = $this->service()->plan($this->job(), $this->parentMission(), $this->subtasks(4), ['enabled' => true]);

        $this->assertSame('atlas.hermes.mesh_composed_plan.v1', $plan['schema_version']);
        $this->assertSame('atlas', $plan['authority']);
        $this->assertFalse($plan['hermes_mesh_can_decide']);
        $this->assertTrue($plan['mesh_enabled']);
        $this->assertTrue($plan['dispatch_allowed_now']);
        $this->assertSame(4, $plan['child_count']);
        $this->assertSame(3, $plan['max_parallel_workers']); // min(4, ceiling 3)
        $this->assertArrayHasKey('receipt_hash', $plan);

        $child = $plan['children'][0];
        $this->assertArrayHasKey('profile', $child);
        $this->assertTrue($child['assigned_worktree']);
        // write mode + atlas_adapter checkpoint policy => checkpoint_before on.
        $this->assertTrue($child['checkpoint_before']);
        // Raw objective text NEVER appears in the sealed plan.
        $this->assertStringNotContainsString('do the thing', json_encode($plan));
    }

    public function test_fail_closed_when_policy_off_dispatches_nothing(): void
    {
        $service = $this->service();
        $plan = $service->plan($this->job(), $this->parentMission(), $this->subtasks(3), ['enabled' => false]);

        $this->assertFalse($plan['mesh_enabled']);
        $this->assertFalse($plan['dispatch_allowed_now']);
        $this->assertNotNull($plan['blocked_reason']);

        $started = 0;
        $packets = $service->dispatch($plan, function (array $child) use (&$started): MeshWorkerHandle {
            $started++;

            return new FakeMeshWorkerHandle((int) $child['index'], 1, true);
        });

        $this->assertSame(0, $started, 'no worker may start when dispatch is not allowed');
        $this->assertSame([], $packets);

        $reconciliation = $service->reconcile($plan, $packets);
        $this->assertSame('empty', $reconciliation['aggregate_status']);
        $this->assertFalse($reconciliation['reconciliation_allowed_now']);
    }

    public function test_dispatch_respects_bounded_concurrency_and_collects_all(): void
    {
        config()->set('atlas.ai.providers.hermes_cli.mesh.max_parallel_workers', 2);
        config()->set('atlas.ai.providers.hermes_cli.mesh.poll_interval_microseconds', 0);

        FakeMeshWorkerHandle::reset();

        $service = $this->service();
        $plan = $service->plan($this->job(), $this->parentMission(), $this->subtasks(6), ['enabled' => true]);
        $this->assertSame(2, $plan['max_parallel_workers']);

        $packets = $service->dispatch($plan, function (array $child): MeshWorkerHandle {
            // finishAfter=3 keeps each child "running" across polls so the pool
            // genuinely overlaps — proving the cap is enforced, not incidental.
            return new FakeMeshWorkerHandle((int) $child['index'], 3, true);
        });

        $this->assertLessThanOrEqual(2, FakeMeshWorkerHandle::$maxLive, 'never exceed max_parallel_workers');
        $this->assertSame(6, count($packets), 'every child is dispatched and collected');
        // Packets are returned in child-index order.
        $this->assertSame(0, $packets[0]['child_index']);
        $this->assertSame(5, $packets[5]['child_index']);
    }

    public function test_run_end_to_end_reconciles_all_completed(): void
    {
        config()->set('atlas.ai.providers.hermes_cli.mesh.max_parallel_workers', 4);
        config()->set('atlas.ai.providers.hermes_cli.mesh.poll_interval_microseconds', 0);

        $service = $this->service();
        $result = $service->run(
            $this->job(),
            $this->parentMission(),
            $this->subtasks(5),
            fn (array $child): MeshWorkerHandle => new FakeMeshWorkerHandle((int) $child['index'], 1, true),
            ['enabled' => true],
        );

        $this->assertSame('atlas.hermes.mesh_run.v1', $result['run']['schema_version']);
        $this->assertArrayHasKey('receipt_hash', $result['run']);
        $this->assertSame(5, $result['run']['dispatched_count']);
        $this->assertSame('all_completed', $result['reconciliation']['aggregate_status']);
        $this->assertTrue($result['reconciliation']['reconciliation_allowed_now']);
        $this->assertSame(5, count($result['result_packets']));
    }

    public function test_run_marks_partial_when_a_child_has_no_output(): void
    {
        config()->set('atlas.ai.providers.hermes_cli.mesh.max_parallel_workers', 4);
        config()->set('atlas.ai.providers.hermes_cli.mesh.poll_interval_microseconds', 0);

        $service = $this->service();
        $result = $service->run(
            $this->job(),
            $this->parentMission(),
            $this->subtasks(3),
            // child index 1 returns an empty (failed) packet.
            fn (array $child): MeshWorkerHandle => new FakeMeshWorkerHandle(
                (int) $child['index'],
                1,
                (int) $child['index'] !== 1,
            ),
            ['enabled' => true],
        );

        $this->assertSame('partial', $result['reconciliation']['aggregate_status']);
    }

    public function test_run_arms_rollback_only_for_failed_verifier_under_checkpoint_policy(): void
    {
        config()->set('atlas.ai.providers.hermes_cli.mesh.max_parallel_workers', 4);
        config()->set('atlas.ai.providers.hermes_cli.mesh.poll_interval_microseconds', 0);
        config()->set('atlas.ai.providers.hermes_cli.mesh.checkpoint_policy', 'atlas_adapter');

        $service = $this->service();
        $result = $service->run(
            $this->job(),
            $this->parentMission(['permission_mode' => 'write']),
            $this->subtasks(2),
            fn (array $child): MeshWorkerHandle => new FakeMeshWorkerHandle((int) $child['index'], 1, true),
            ['enabled' => true],
            [],
            // child 0 fails verification, child 1 passes.
            fn (array $packet, array $child): array => ['passed' => (int) $child['index'] !== 0],
        );

        $this->assertSame(1, $result['run']['rollbacks_armed']);
        $actions = $result['checkpoint_actions'];
        $this->assertCount(2, $actions);
        $this->assertTrue($actions[0]['rollback_now'], 'failed verifier + checkpoint policy on => rollback armed');
        $this->assertFalse($actions[1]['rollback_now'], 'passed verifier => no rollback');
    }

    public function test_run_does_not_arm_rollback_when_checkpoint_policy_off(): void
    {
        config()->set('atlas.ai.providers.hermes_cli.mesh.max_parallel_workers', 4);
        config()->set('atlas.ai.providers.hermes_cli.mesh.poll_interval_microseconds', 0);
        config()->set('atlas.ai.providers.hermes_cli.mesh.checkpoint_policy', 'off');

        $service = $this->service();
        $result = $service->run(
            $this->job(),
            $this->parentMission(['permission_mode' => 'write']),
            $this->subtasks(2),
            fn (array $child): MeshWorkerHandle => new FakeMeshWorkerHandle((int) $child['index'], 1, true),
            ['enabled' => true],
            [],
            fn (array $packet, array $child): array => ['passed' => false],
        );

        // Fail-closed: no checkpoint policy => verifier failures never arm rollback.
        $this->assertSame(0, $result['run']['rollbacks_armed']);
    }
}

/**
 * In-memory fake worker handle. Tracks live count to prove the service never
 * runs more than the configured number of children at once. `finishAfter`
 * controls how many isFinished() polls it survives before completing.
 */
class FakeMeshWorkerHandle implements MeshWorkerHandle
{
    public static int $live = 0;

    public static int $maxLive = 0;

    private int $polls = 0;

    public function __construct(
        private readonly int $index,
        private readonly int $finishAfter,
        private readonly bool $withOutput,
    ) {
        self::$live++;
        self::$maxLive = max(self::$maxLive, self::$live);
    }

    public static function reset(): void
    {
        self::$live = 0;
        self::$maxLive = 0;
    }

    public function isFinished(): bool
    {
        $this->polls++;
        if ($this->polls >= $this->finishAfter) {
            self::$live = max(0, self::$live - 1);

            return true;
        }

        self::$maxLive = max(self::$maxLive, self::$live);

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    public function result(): array
    {
        return [
            'schema_version' => 'atlas.hermes.result_packet.v1',
            'child_index' => $this->index,
            'result_hash' => $this->withOutput ? hash('sha256', 'child-'.$this->index) : null,
            'output' => [
                'response_hash' => $this->withOutput ? hash('sha256', 'child-'.$this->index) : null,
                'response_bytes' => $this->withOutput ? 42 : 0,
            ],
            'exit_code' => 0,
        ];
    }
}
