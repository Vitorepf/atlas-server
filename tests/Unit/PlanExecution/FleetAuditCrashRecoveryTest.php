<?php

declare(strict_types=1);

namespace Tests\Unit\PlanExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\DeterministicPlanSliceCycleExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\FleetAuditLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\FleetObservabilityReadModelService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanCompletionTrackerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanDrivenLoopRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanSliceCycleExecutor;
use Tests\TestCase;

/**
 * Axis N · part 2 · END-TO-END proof of fleet GOVERNANCE + OBSERVABILITY driving the REAL
 * runner with DETERMINISTIC fake workers (no live provider):
 *   1. The append-only fleet audit ledger records the run and replays deterministically.
 *   2. A simulated mid-run CRASH recovers on resume WITHOUT double-merging an already-merged
 *      slice (git/tracker is source of truth; the audit ledger seeds the skip set).
 *   3. The enterprise read-model reports REAL numbers (throughput, parallel utilization,
 *      conflicts deferred, product merges, measured-or-reverted).
 */
final class FleetAuditCrashRecoveryTest extends TestCase
{
    private string $trackerRoot;

    private string $auditRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $seed = bin2hex(random_bytes(6));
        $this->trackerRoot = sys_get_temp_dir().'/atlas_fleet_tr_'.$seed;
        $this->auditRoot = sys_get_temp_dir().'/atlas_fleet_au_'.$seed;
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->trackerRoot);
        $this->removeDir($this->auditRoot);
        parent::tearDown();
    }

    private function tracker(): PlanCompletionTrackerService
    {
        $t = new PlanCompletionTrackerService;
        $t->setStorageRootForTesting($this->trackerRoot);

        return $t;
    }

    private function audit(): FleetAuditLedgerService
    {
        $a = new FleetAuditLedgerService;
        $a->setStorageRootForTesting($this->auditRoot);

        return $a;
    }

    private function runner(PlanCompletionTrackerService $tracker, FleetAuditLedgerService $audit): PlanDrivenLoopRunnerService
    {
        $runner = new PlanDrivenLoopRunnerService;
        $runner->setTrackerForTesting($tracker);
        $runner->setFleetAuditForTesting($audit);

        return $runner;
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

    /** @return array<string,mixed> */
    private function threeIndependentPlan(): array
    {
        return [
            'schema_version' => 'atlas.plan_execution.decomposed_plan.v1',
            'plan_id' => 'fleet-crash',
            'decomposition_status' => 'complete',
            'slices' => [
                $this->slice('A', 1, [], ['app/Crash/A.php']),
                $this->slice('B', 2, [], ['app/Crash/B.php']),
                $this->slice('C', 3, [], ['app/Crash/C.php']),
            ],
            'dependency_graph' => [],
            'plan_hash' => 'sha256:crash',
        ];
    }

    public function test_audit_ledger_records_and_replays_a_full_clean_run(): void
    {
        $tracker = $this->tracker();
        $audit = $this->audit();
        $plan = $this->threeIndependentPlan();

        $result = $this->runner($tracker, $audit)->run([
            'decomposed_plan' => $plan,
            'area_id' => 'agentic_engineering_os',
            'executor' => new DeterministicPlanSliceCycleExecutor,
            'fleet_parallel' => true,
            'max_parallel' => 4,
        ]);

        $this->assertSame(PlanDrivenLoopRunnerService::STATUS_COMPLETE, $result['status']);
        $this->assertSame(3, $result['delivered_count']);
        $this->assertSame([], $result['resumed_merged_slice_ids'], 'fresh run resumes nothing');

        $replay = $audit->replay('fleet-crash', 'agentic_engineering_os');
        $this->assertSame(1, $replay['batches_planned']);
        $this->assertSame(3, $replay['workers_claimed']);
        $this->assertSame(3, $replay['workers_returned']);
        $this->assertSame(3, $replay['merged_count']);
        $this->assertSame(0, $replay['corrupted_lines']);
        // Every worker reached a terminal event => nothing reclaimable.
        $this->assertSame([], $replay['reclaimable_slice_ids']);
        $this->assertEqualsCanonicalizing(['A', 'B', 'C'], $replay['merged_slice_ids']);
    }

    public function test_mid_run_crash_recovers_without_double_merge(): void
    {
        // A is independent; B and C depend on A => batch 1 = {A} (merges + recorded),
        // batch 2 = {B,C}. The worker crashes while working B in batch 2 — AFTER A is
        // durably merged. This is the realistic "worktree died mid-run" case the resume
        // must survive without double-merging A.
        $plan = [
            'schema_version' => 'atlas.plan_execution.decomposed_plan.v1',
            'plan_id' => 'fleet-crash',
            'decomposition_status' => 'complete',
            'slices' => [
                $this->slice('A', 1, [], ['app/Crash/A.php']),
                $this->slice('B', 2, ['A'], ['app/Crash/B.php']),
                $this->slice('C', 3, ['A'], ['app/Crash/C.php']),
            ],
            'dependency_graph' => [],
            'plan_hash' => 'sha256:crash',
        ];

        // --- RUN 1: batch 1 merges A; batch 2 THROWS while working B. ---
        $crashingExecutor = new class implements PlanSliceCycleExecutor
        {
            public function isSimulated(): bool
            {
                return true;
            }

            public function executeSlice(array $slice, array $context): array
            {
                $sid = (string) ($slice['slice_id'] ?? '');
                if ($sid === 'B') {
                    throw new \RuntimeException('simulated worktree crash mid-run');
                }

                return (new DeterministicPlanSliceCycleExecutor)->executeSlice($slice, $context);
            }
        };

        $tracker1 = $this->tracker();
        $audit1 = $this->audit();
        $crashed = false;
        try {
            $this->runner($tracker1, $audit1)->run([
                'decomposed_plan' => $plan,
                'area_id' => 'agentic_engineering_os',
                'executor' => $crashingExecutor,
                'fleet_parallel' => true,
                'max_parallel' => 4,
            ]);
        } catch (\RuntimeException $e) {
            $crashed = true;
        }
        $this->assertTrue($crashed, 'run 1 crashed mid-batch as designed');

        // Durable state after the crash: A merged exactly once; B is an orphaned claim.
        $afterCrash = $audit1->replay('fleet-crash', 'agentic_engineering_os');
        $this->assertSame(['A'], $afterCrash['merged_slice_ids'], 'only A merged before the crash');
        $this->assertSame(['B'], $afterCrash['reclaimable_slice_ids'], 'B left an orphaned worker lease');
        $rollupAfterCrash = $tracker1->rollup('fleet-crash', 'agentic_engineering_os', $plan);
        $this->assertSame(1, $rollupAfterCrash['delivered_count'], 'tracker durably has exactly 1 delivery');

        // --- RUN 2: RESUME against the SAME ledgers with a healthy executor. A is seeded into
        // skip from the audit ledger and must NOT be re-dispatched / re-merged. B and C finish. ---
        $tracker2 = $this->tracker(); // same storage root => same JSONL
        $audit2 = $this->audit();
        $resume = $this->runner($tracker2, $audit2)->run([
            'decomposed_plan' => $plan,
            'area_id' => 'agentic_engineering_os',
            'executor' => new DeterministicPlanSliceCycleExecutor,
            'fleet_parallel' => true,
            'max_parallel' => 4,
        ]);

        $this->assertSame(PlanDrivenLoopRunnerService::STATUS_COMPLETE, $resume['status']);
        $this->assertSame(3, $resume['delivered_count'], 'all 3 delivered after resume');
        $this->assertContains('A', $resume['resumed_merged_slice_ids'], 'A recognized as already merged');

        // NO DOUBLE-MERGE: A appears merged exactly ONCE across the whole audit history, and
        // was never re-dispatched as a worker in run 2.
        $finalReplay = $audit2->replay('fleet-crash', 'agentic_engineering_os');
        $this->assertSame(3, $finalReplay['merged_count']);
        $this->assertEqualsCanonicalizing(['A', 'B', 'C'], $finalReplay['merged_slice_ids']);

        $aMergeEvents = 0;
        $aClaimEvents = 0;
        foreach ($this->readAudit('fleet-crash', 'agentic_engineering_os') as $event) {
            if (($event['slice_id'] ?? '') !== 'A') {
                continue;
            }
            if (($event['kind'] ?? '') === FleetAuditLedgerService::KIND_MERGED) {
                $aMergeEvents++;
            }
            if (($event['kind'] ?? '') === FleetAuditLedgerService::KIND_WORKER_CLAIMED) {
                $aClaimEvents++;
            }
        }
        $this->assertSame(1, $aMergeEvents, 'A merged exactly once — never double-merged');
        $this->assertSame(1, $aClaimEvents, 'A dispatched exactly once — not re-run on resume');

        // Tracker (source of truth) confirms exactly 3 deliveries, not 4.
        $this->assertSame(3, $tracker2->rollup('fleet-crash', 'agentic_engineering_os', $plan)['delivered_count']);
    }

    public function test_read_model_reports_real_numbers_including_conflicts_and_measured_outcomes(): void
    {
        // Worker C declares disjoint scope but touches A's file => integrator defers C.
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
                $changed = $sid === 'C' ? ['app/Crash/C.php', 'app/Crash/A.php'] : ['app/Crash/'.$sid.'.php'];

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

        $tracker = $this->tracker();
        $audit = $this->audit();
        $plan = $this->threeIndependentPlan();

        $result = $this->runner($tracker, $audit)->run([
            'decomposed_plan' => $plan,
            'area_id' => 'agentic_engineering_os',
            'executor' => $executor,
            'fleet_parallel' => true,
            'max_parallel' => 4,
            'max_no_progress' => 1,
        ]);
        $this->assertSame(2, $result['delivered_count'], 'A,B merge; C deferred on conflict');

        // Seed the Fase 5 metric ledger with real measured-or-reverted events for the area.
        $metrics = new \App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\MetricLedgerService;
        $metricsRoot = sys_get_temp_dir().'/atlas_fleet_mx_'.bin2hex(random_bytes(6));
        $metrics->setStorageRootForTesting($metricsRoot);
        $metrics->setMeasurerForTesting(fn (string $cmd, string $root): array => ['ok' => true, 'exit_code' => 0, 'out' => '{"metric":12}', 'err' => '']);
        // One outcome MET (12 - 10 >= 1).
        $metrics->measureOutcome(
            $metrics->normalizeContract(['metric_id' => 'm', 'baseline' => 10, 'target_delta' => 1, 'measure_command' => 'x']),
            ['area_id' => 'agentic_engineering_os', 'cycle_id' => 'fk_A_1', 'merge_hash' => 'fkA', 'repo_root' => sys_get_temp_dir()],
        );
        // One outcome NOT MET (12 - 20 < 1) => a revert candidate.
        $metrics->measureOutcome(
            $metrics->normalizeContract(['metric_id' => 'm', 'baseline' => 20, 'target_delta' => 1, 'measure_command' => 'x']),
            ['area_id' => 'agentic_engineering_os', 'cycle_id' => 'fk_B_2', 'merge_hash' => 'fkB', 'repo_root' => sys_get_temp_dir()],
        );

        $readModel = new FleetObservabilityReadModelService($audit, $tracker, $metrics);
        $report = $readModel->report('fleet-crash', 'agentic_engineering_os', $plan);

        $this->assertSame(FleetObservabilityReadModelService::SCHEMA, $report['schema_version']);

        // Throughput: 1 planned batch, 3 workers claimed, 2 real product merges.
        $this->assertSame(1, $report['throughput']['batches_planned']);
        $this->assertSame(3, $report['throughput']['workers_claimed']);
        $this->assertSame(2, $report['throughput']['product_merges']);
        $this->assertSame(2.0, $report['throughput']['merges_per_batch']);

        // Parallel utilization: avg batch size 3 against max_parallel 4 => 0.75.
        $this->assertSame(4, $report['parallel_utilization']['max_parallel']);
        $this->assertSame(3.0, $report['parallel_utilization']['avg_batch_size']);
        $this->assertSame(0.75, $report['parallel_utilization']['utilization_ratio']);

        // Conflicts deferred: C deferred on file-conflict.
        $this->assertSame(1, $report['conflicts_deferred']['deferred_count']);
        $this->assertContains('C', $report['conflicts_deferred']['deferred_slice_ids']);

        // Product merges: tracker is the authority and audit agrees (consistent, no drift).
        $this->assertSame(2, $report['product_merges']['delivered_count']);
        $this->assertSame(2, $report['product_merges']['audit_merged_count']);
        $this->assertTrue($report['product_merges']['audit_vs_tracker_consistent']);

        // Measured-or-reverted (Fase 5): 2 measured, 1 met, 1 not-met => 1 revert candidate.
        $this->assertSame(2, $report['measured_or_reverted']['measured']);
        $this->assertSame(1, $report['measured_or_reverted']['outcome_met']);
        $this->assertSame(1, $report['measured_or_reverted']['outcome_not_met']);
        $this->assertSame(1, $report['measured_or_reverted']['reverted_candidates']);

        $this->removeDir($metricsRoot);
    }

    /** @return list<array<string,mixed>> */
    private function readAudit(string $planId, string $areaId): array
    {
        $slug = static fn (string $v): string => trim((string) preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim($v))), '_') ?: 'unscoped';
        $path = $this->auditRoot.'/'.$slug($areaId).'/'.$slug($planId).'/fleet_audit_ledger.jsonl';
        if (! is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
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
