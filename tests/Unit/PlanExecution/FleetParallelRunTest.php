<?php

declare(strict_types=1);

namespace Tests\Unit\PlanExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\DeterministicPlanSliceCycleExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\FleetIntegratorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\FleetSlicePlannerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanCompletionTrackerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanDrivenLoopRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanSliceCycleExecutor;
use PHPUnit\Framework\TestCase;

/**
 * Axis N · END-TO-END proof of the parallel fleet path driving the REAL loop runner with
 * DETERMINISTIC fake workers (no live provider). The sequential path is exercised by
 * PlanDrivenLoopRunnerServiceTest; this pins the opt-in parallel path's planner +
 * worker fan-out + serialized integrator behind the identical gate chain.
 */
final class FleetParallelRunTest extends TestCase
{
    private string $storageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = sys_get_temp_dir().'/atlas_fleet_run_'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->storageRoot);
        parent::tearDown();
    }

    private function runner(): PlanDrivenLoopRunnerService
    {
        $tracker = new PlanCompletionTrackerService;
        $tracker->setStorageRootForTesting($this->storageRoot);
        $runner = new PlanDrivenLoopRunnerService;
        $runner->setTrackerForTesting($tracker); // also rebinds the fleet integrator to this tracker

        return $runner;
    }

    /**
     * 3 fully-independent slices (no deps, disjoint allowed_files): all dispatched in ONE
     * parallel batch.
     *
     * @return array<string,mixed>
     */
    private function threeIndependentPlan(): array
    {
        return [
            'schema_version' => 'atlas.plan_execution.decomposed_plan.v1',
            'plan_id' => 'fleet-plan',
            'decomposition_status' => 'complete',
            'slices' => [
                $this->slice('A', 1, [], ['app/Fleet/A.php']),
                $this->slice('B', 2, [], ['app/Fleet/B.php']),
                $this->slice('C', 3, [], ['app/Fleet/C.php']),
            ],
            'dependency_graph' => [],
            'plan_hash' => 'sha256:fleet',
        ];
    }

    /**
     * @param  list<string>  $dependsOn
     * @param  list<string>  $allowedFiles
     * @return array<string,mixed>
     */
    private function slice(string $id, int $seq, array $dependsOn, array $allowedFiles): array
    {
        return [
            'slice_id' => $id,
            'sequence' => $seq,
            'depends_on' => $dependsOn,
            'allowed_files' => $allowedFiles,
            'acceptance_criteria' => ['aceite '.$id],
            'owner' => 'atlas_dev',
            'finding' => ['finding_id' => $id, 'title' => 'finding '.$id],
            'finding_id' => $id,
        ];
    }

    public function test_three_independent_slices_dispatched_in_one_parallel_batch(): void
    {
        $result = $this->runner()->run([
            'decomposed_plan' => $this->threeIndependentPlan(),
            'area_id' => 'agentic_engineering_os',
            'executor' => new DeterministicPlanSliceCycleExecutor,
            'fleet_parallel' => true,
            'max_parallel' => 4,
        ]);

        $this->assertTrue($result['fleet_parallel']);
        $this->assertSame(PlanDrivenLoopRunnerService::STATUS_COMPLETE, $result['status']);
        $this->assertSame(3, $result['delivered_count']);
        $this->assertSame(100.0, $result['completion_pct']);
        // Single batch: all 3 independent slices dispatched together.
        $this->assertSame(1, $result['batches_run']);
        $this->assertCount(1, $result['trace']);
        $this->assertSame(3, $result['trace'][0]['merged_count']);
        $this->assertSame(['A', 'B', 'C'], $result['trace'][0]['slice_ids']);
        // Simulated executor => never claims real delivery.
        $this->assertTrue($result['simulated']);
        $this->assertFalse($result['claim_policy']['real_delivery_claimed']);
    }

    public function test_two_clean_one_conflicting_merges_two_defers_one(): void
    {
        // Worker C declares disjoint scope but its cycle TOUCHES app/Fleet/A.php (a file
        // already merged by worker A this batch) => integrator defers C on file-conflict.
        $executor = new class implements PlanSliceCycleExecutor
        {
            private int $seq = 0;

            public function isSimulated(): bool
            {
                return true;
            }

            public function executeSlice(array $slice, array $context): array
            {
                $this->seq++;
                $sid = (string) $slice['slice_id'];
                // C collides on A's file; A and B touch only their own declared file.
                $changed = $sid === 'C' ? ['app/Fleet/C.php', 'app/Fleet/A.php'] : ['app/Fleet/'.$sid.'.php'];

                return [
                    'cycle_id' => 'fk_'.$sid.'_'.$this->seq,
                    'final_status' => 'cycle_completed',
                    'merge_performed' => true,
                    'blockers' => [],
                    'owner' => 'atlas_dev',
                    'selected_finding' => ['finding_id' => $sid, 'title' => 'finding '.$sid],
                    'changed_files' => $changed,
                    'validation' => ['ran' => true, 'passed' => true, 'commands' => ['php artisan test'], 'results' => [['ok' => true]]],
                    'merge_governance' => ['status' => 'merged', 'merge_commit' => 'fk'.$sid],
                    'result_bridge_id' => 'rb_'.$sid,
                    'inbox_item_id' => 'inbox_'.$sid,
                    'owner_flow' => ['provider_router_used' => false],
                    'owner_result' => ['runtime_invocation' => ['command_result' => ['owner_cli_provider_calls' => 1]]],
                    'simulated' => true,
                    'plan_slice_id' => $sid,
                ];
            }
        };

        $result = $this->runner()->run([
            'decomposed_plan' => $this->threeIndependentPlan(),
            'area_id' => 'area_conflict',
            'executor' => $executor,
            'fleet_parallel' => true,
            'max_parallel' => 4,
            'max_no_progress' => 1,
        ]);

        $this->assertTrue($result['fleet_parallel']);
        // First batch dispatches A, B, C; A+B merge, C deferred on file-conflict.
        $firstBatch = $result['trace'][0];
        $this->assertSame(['A', 'B', 'C'], $firstBatch['slice_ids']);
        $this->assertSame(2, $firstBatch['merged_count'], 'A and B merge cleanly');
        $this->assertSame(1, $firstBatch['deferred_count'], 'C deferred on conflict');

        $cDisp = null;
        foreach ($firstBatch['dispositions'] as $d) {
            if (($d['slice_id'] ?? '') === 'C') {
                $cDisp = $d;
            }
        }
        $this->assertNotNull($cDisp);
        $this->assertSame(FleetIntegratorService::DISPOSITION_DEFERRED_CONFLICT, $cDisp['disposition']);

        // Honest stop: 2 of 3 delivered, C never fabricated => not complete.
        $this->assertSame(2, $result['delivered_count']);
        $this->assertNotSame(100.0, $result['completion_pct']);
        $this->assertNotSame(PlanDrivenLoopRunnerService::STATUS_COMPLETE, $result['status']);
        // C was attempted then skipped (anti-spin), named in blockers.
        $hasCSkip = false;
        foreach ($result['blockers'] as $b) {
            if (str_contains((string) $b, 'skipped') && str_contains((string) $b, 'C')) {
                $hasCSkip = true;
            }
        }
        $this->assertTrue($hasCSkip);
    }

    public function test_dependency_chain_serializes_across_batches(): void
    {
        // A->B->C chain with disjoint files: planner can only ever pick the one ready
        // slice per batch (B waits for A's real merge), proving the dependency gate holds
        // in the parallel path exactly as in the sequential one.
        $plan = [
            'schema_version' => 'atlas.plan_execution.decomposed_plan.v1',
            'plan_id' => 'fleet-chain',
            'decomposition_status' => 'complete',
            'slices' => [
                $this->slice('A', 1, [], ['app/Chain/A.php']),
                $this->slice('B', 2, ['A'], ['app/Chain/B.php']),
                $this->slice('C', 3, ['B'], ['app/Chain/C.php']),
            ],
            'dependency_graph' => [],
            'plan_hash' => 'sha256:chain',
        ];

        $result = $this->runner()->run([
            'decomposed_plan' => $plan,
            'area_id' => 'area_chain',
            'executor' => new DeterministicPlanSliceCycleExecutor,
            'fleet_parallel' => true,
            'max_parallel' => 4,
        ]);

        $this->assertSame(PlanDrivenLoopRunnerService::STATUS_COMPLETE, $result['status']);
        $this->assertSame(3, $result['delivered_count']);
        // 3 batches, one slice each (dependency-serialized).
        $this->assertSame(3, $result['batches_run']);
        foreach ($result['trace'] as $b) {
            $this->assertCount(1, $b['slice_ids']);
        }
    }

    public function test_planner_picks_independent_pair_then_dependent_batch(): void
    {
        // S1,S4 independent; S2 dep S1; S5 dep S4. Batch 1 = {S1,S4}; batch 2 = {S2,S5}.
        $plan = [
            'schema_version' => 'atlas.plan_execution.decomposed_plan.v1',
            'plan_id' => 'fleet-pairs',
            'decomposition_status' => 'complete',
            'slices' => [
                $this->slice('S1', 1, [], ['app/P/S1.php']),
                $this->slice('S2', 2, ['S1'], ['app/P/S2.php']),
                $this->slice('S4', 4, [], ['app/P/S4.php']),
                $this->slice('S5', 5, ['S4'], ['app/P/S5.php']),
            ],
            'dependency_graph' => [],
            'plan_hash' => 'sha256:pairs',
        ];

        $planner = new FleetSlicePlannerService;
        $tracker = new PlanCompletionTrackerService;
        $tracker->setStorageRootForTesting($this->storageRoot);
        $rollup = $tracker->rollup('fleet-pairs', 'area_pairs', $plan);

        $batch = $planner->planBatch($plan, $rollup, 4);
        $this->assertSame(FleetSlicePlannerService::KIND_BATCH_READY, $batch['kind']);
        $this->assertSame(['S1', 'S4'], $batch['slice_ids'], 'only the two ready independents are batched');

        // Full run delivers all four across two batches.
        $result = $this->runner()->run([
            'decomposed_plan' => $plan,
            'area_id' => 'area_pairs',
            'executor' => new DeterministicPlanSliceCycleExecutor,
            'fleet_parallel' => true,
            'max_parallel' => 4,
        ]);
        $this->assertSame(PlanDrivenLoopRunnerService::STATUS_COMPLETE, $result['status']);
        $this->assertSame(4, $result['delivered_count']);
        $this->assertSame(2, $result['batches_run']);
    }

    public function test_undeclared_scope_slice_runs_solo(): void
    {
        // A slice with NO allowed_files must never be batched in parallel (unknown scope).
        $plan = [
            'schema_version' => 'atlas.plan_execution.decomposed_plan.v1',
            'plan_id' => 'fleet-solo',
            'decomposition_status' => 'complete',
            'slices' => [
                $this->slice('A', 1, [], []),               // no scope => solo
                $this->slice('B', 2, [], ['app/Solo/B.php']),
            ],
            'dependency_graph' => [],
            'plan_hash' => 'sha256:solo',
        ];

        $planner = new FleetSlicePlannerService;
        $tracker = new PlanCompletionTrackerService;
        $tracker->setStorageRootForTesting($this->storageRoot);
        $batch = $planner->planBatch($plan, $tracker->rollup('fleet-solo', 'area_solo', $plan), 4);
        $this->assertSame(['A'], $batch['slice_ids'], 'undeclared-scope A runs solo, B not co-batched');
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->removeDir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
