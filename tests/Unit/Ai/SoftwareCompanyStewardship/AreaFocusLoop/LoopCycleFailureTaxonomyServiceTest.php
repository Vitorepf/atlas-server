<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCandidateQuarantineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopCycleFailureTaxonomyService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PerFailureClassRemediationHintsFromTheLoopFailureTaxonomyContract;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoopRunnerService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LoopCycleFailureTaxonomyServiceTest extends TestCase
{
    private function service(): LoopCycleFailureTaxonomyService
    {
        return app(LoopCycleFailureTaxonomyService::class);
    }

    // ------------------------------------------------------------------
    // Tier classification — all 5 tiers
    // ------------------------------------------------------------------

    public function test_tier_1_setup_failure_for_worktree_error(): void
    {
        $verdict = $this->service()->classify([
            'final_status' => 'blocked',
            'blockers' => ['git_worktree_add_failed'],
        ]);

        $this->assertSame(LoopCycleFailureTaxonomyService::TIER_SETUP, $verdict['tier']);
        $this->assertSame('setup_failure', $verdict['tier_name']);
        $this->assertSame(LoopCycleFailureTaxonomyService::RECOVERY_AUTO_REPAIR_ENV, $verdict['recovery_action']);
        $this->assertSame(1, $verdict['max_retries']);
        // The specific reason is the exact blocker, not a generic label.
        $this->assertSame('git_worktree_add_failed', $verdict['specific_reason']);
    }

    public function test_tier_1_setup_failure_for_autoload_and_sandbox_errors(): void
    {
        foreach (['autoload_dump_failed', 'sandbox_materialization_failed', 'sandbox_error'] as $blocker) {
            $verdict = $this->service()->classify(['final_status' => 'blocked', 'blockers' => [$blocker]]);
            $this->assertSame(1, $verdict['tier'], $blocker.' must be tier 1');
            $this->assertSame('setup_failure', $verdict['tier_name']);
        }
    }

    public function test_tier_2_execution_failure_for_senior_loop_not_passed(): void
    {
        $verdict = $this->service()->classify([
            'final_status' => 'blocked',
            // Real canonical prefixed blocker — substring match must still catch it.
            'blockers' => ['owner_runtime_senior_loop_execution_not_passed'],
        ]);

        $this->assertSame(LoopCycleFailureTaxonomyService::TIER_EXECUTION, $verdict['tier']);
        $this->assertSame('execution_failure', $verdict['tier_name']);
        $this->assertSame(LoopCycleFailureTaxonomyService::RECOVERY_RETRY_WITH_CONTEXT, $verdict['recovery_action']);
        $this->assertSame(1, $verdict['max_retries']);
        $this->assertSame('owner_runtime_senior_loop_execution_not_passed', $verdict['specific_reason']);
    }

    public function test_tier_2_execution_failure_for_provider_timeout(): void
    {
        $verdict = $this->service()->classify([
            'final_status' => 'blocked',
            'blockers' => ['owner_runtime_provider_timeout'],
        ]);

        $this->assertSame(2, $verdict['tier']);
        $this->assertSame('execution_failure', $verdict['tier_name']);
        $this->assertSame(1, $verdict['max_retries']);
    }

    public function test_tier_3_quality_failure_for_judge_test_psr_repair(): void
    {
        foreach ([
            'judge_rejected',
            'test_failed',
            'psr_error',
            'repair_exhausted',
        ] as $blocker) {
            $verdict = $this->service()->classify(['final_status' => 'blocked', 'blockers' => [$blocker]]);
            $this->assertSame(LoopCycleFailureTaxonomyService::TIER_QUALITY, $verdict['tier'], $blocker.' must be tier 3');
            $this->assertSame('quality_failure', $verdict['tier_name']);
            $this->assertSame(LoopCycleFailureTaxonomyService::RECOVERY_REPAIR_AGENT, $verdict['recovery_action']);
            $this->assertSame(2, $verdict['max_retries']);
        }
    }

    public function test_scaffold_or_mock_delivery_block_is_quality_not_execution(): void
    {
        // The FinalDeliveryQualityGate rejecting a scaffold/mock is the gate WORKING.
        // It MUST classify as a QUALITY tier (classified=true) so it stays OUT of the
        // tier-2 execution cascade counter — otherwise an honest scaffold streak falsely
        // halts a 24h run when the next real execution failure lands. Regression guard
        // for the 2026-05-30 soak cascade-halt root-cause fix.
        foreach ([
            'delivery_not_final_scaffold_or_mock',
            'no_patch_needed_without_proof',
        ] as $blocker) {
            $verdict = $this->service()->classify(['final_status' => 'blocked', 'blockers' => [$blocker]]);
            $this->assertSame(LoopCycleFailureTaxonomyService::TIER_QUALITY, $verdict['tier'], $blocker.' must be tier 3 quality, not tier 2 execution');
            $this->assertTrue($verdict['classified'], $blocker.' must be a CLASSIFIED quality failure');
            $this->assertNotSame(LoopCycleFailureTaxonomyService::TIER_EXECUTION, $verdict['tier']);
        }
    }

    public function test_tier_4_policy_failure_for_merge_not_performed_and_policy_not_satisfied(): void
    {
        foreach ([
            'merge_not_performed',
            'auto_merge_policy_not_satisfied',
        ] as $blocker) {
            $verdict = $this->service()->classify(['final_status' => 'blocked', 'blockers' => [$blocker]]);
            $this->assertSame(LoopCycleFailureTaxonomyService::TIER_POLICY, $verdict['tier'], $blocker.' must be tier 4');
            $this->assertSame('policy_failure', $verdict['tier_name']);
            $this->assertSame(LoopCycleFailureTaxonomyService::RECOVERY_MERGE_RETRY, $verdict['recovery_action']);
            $this->assertSame(2, $verdict['max_retries']);
        }
    }

    public function test_tier_5_budget_exhausted_for_ceilings(): void
    {
        foreach ([
            'max_merges_hit',
            'max_runtime_hit',
            'backlog_exhausted',
        ] as $blocker) {
            $verdict = $this->service()->classify(['final_status' => 'blocked', 'blockers' => [$blocker]]);
            $this->assertSame(LoopCycleFailureTaxonomyService::TIER_BUDGET, $verdict['tier'], $blocker.' must be tier 5');
            $this->assertSame('budget_exhausted', $verdict['tier_name']);
            $this->assertSame(LoopCycleFailureTaxonomyService::RECOVERY_STOP_HONEST, $verdict['recovery_action']);
            $this->assertSame(0, $verdict['max_retries']);
        }
    }

    public function test_budget_tier_wins_over_an_incidental_lower_tier_blocker(): void
    {
        // A real ceiling must never be mis-classified as a recoverable failure just
        // because an incidental lower-tier blocker also rode along.
        $verdict = $this->service()->classify([
            'final_status' => 'blocked',
            'blockers' => ['judge_rejected', 'backlog_exhausted'],
        ]);

        $this->assertSame(LoopCycleFailureTaxonomyService::TIER_BUDGET, $verdict['tier']);
        $this->assertSame(0, $verdict['max_retries']);
    }

    public function test_unclassified_blocked_cycle_is_execution_failure(): void
    {
        $verdict = $this->service()->classify([
            'final_status' => 'blocked',
            'blockers' => ['some_unmapped_reason'],
        ]);

        $this->assertSame(LoopCycleFailureTaxonomyService::TIER_EXECUTION, $verdict['tier']);
        // Specific reason still surfaces the real blocker.
        $this->assertSame('some_unmapped_reason', $verdict['specific_reason']);
    }

    public function test_non_blocked_non_merged_cycle_is_policy_failure(): void
    {
        $verdict = $this->service()->classify([
            'final_status' => 'cycle_completed_waiting_review_or_merge',
            'blockers' => [],
            'merge_performed' => false,
        ]);

        $this->assertSame(LoopCycleFailureTaxonomyService::TIER_POLICY, $verdict['tier']);
        $this->assertSame('merge_not_performed', $verdict['specific_reason']);
    }

    // ------------------------------------------------------------------
    // Cascade safety
    // ------------------------------------------------------------------

    public function test_is_cascade_safe_true_within_retry_budget(): void
    {
        // Tier 3 quality failure has max_retries=2; recurrence 2 is still safe.
        $verdict = $this->service()->classify([
            'final_status' => 'blocked',
            'blockers' => ['judge_rejected'],
            'recurrence' => 2,
        ]);

        $this->assertSame(2, $verdict['recurrence']);
        $this->assertTrue($verdict['is_cascade_safe']);
    }

    public function test_is_cascade_safe_false_when_recurring_beyond_retry_budget(): void
    {
        // Tier 3 quality failure has max_retries=2; recurrence 3 exceeds it.
        $verdict = $this->service()->classify([
            'final_status' => 'blocked',
            'blockers' => ['judge_rejected'],
            'recurrence' => 3,
        ]);

        $this->assertFalse($verdict['is_cascade_safe'], 'a tier recurring past its max_retries is not cascade-safe to retry');
    }

    public function test_budget_tier_is_never_cascade_safe_to_retry(): void
    {
        // max_retries=0 — there is no more work, so retrying is never safe.
        $verdict = $this->service()->classify([
            'final_status' => 'blocked',
            'blockers' => ['backlog_exhausted'],
            'recurrence' => 0,
        ]);

        $this->assertFalse($verdict['is_cascade_safe']);
    }

    public function test_deterministic_schema_and_shape(): void
    {
        $verdict = $this->service()->classify(['final_status' => 'blocked', 'blockers' => ['judge_rejected']]);

        $this->assertSame(LoopCycleFailureTaxonomyService::REPORT_SCHEMA, $verdict['schema_version']);
        foreach (['tier', 'tier_name', 'specific_reason', 'recovery_action', 'max_retries', 'is_cascade_safe', 'classified'] as $key) {
            $this->assertArrayHasKey($key, $verdict);
        }
    }

    public function test_classified_true_only_when_a_known_needle_matches(): void
    {
        // A recognised blocker => classified true.
        $known = $this->service()->classify(['final_status' => 'blocked', 'blockers' => ['judge_rejected']]);
        $this->assertTrue($known['classified']);

        // An unrecognised blocker falls into the generic execution bucket => false,
        // so the supervisor never escalates an unknown blocker as a known failure.
        $unknown = $this->service()->classify(['final_status' => 'blocked', 'blockers' => ['full_atlas_forge_flow_required']]);
        $this->assertSame(LoopCycleFailureTaxonomyService::TIER_EXECUTION, $unknown['tier']);
        $this->assertFalse($unknown['classified']);
    }

    public function test_empty_input_does_not_crash(): void
    {
        $verdict = $this->service()->classify([]);

        // No blocker, no merge => policy failure (work neither blocked nor merged).
        $this->assertSame(LoopCycleFailureTaxonomyService::TIER_POLICY, $verdict['tier']);
        $this->assertSame('merge_not_performed', $verdict['specific_reason']);
        $this->assertIsBool($verdict['is_cascade_safe']);
    }

    public function test_per_failure_class_remediation_hints_from_the_loop_failure_taxonomy_signal_exposes_runtime_signal(): void
    {
        $signal = $this->service()->perFailureClassRemediationHintsFromTheLoopFailureTaxonomySignal();

        $this->assertSame(
            'atlas.software_company_stewardship.per_failure_class_remediation_hints_signal.v1',
            $signal['schema_version'],
        );
        $this->assertSame(
            'per_failure_class_remediation_hints_from_loop_failure_taxonomy',
            $signal['signal_id'],
        );
        $this->assertSame('runtime_signal', $signal['semantic_step']);
        $this->assertTrue($signal['informational_signal_only']);
        $this->assertSame(LoopCycleFailureTaxonomyService::REPORT_SCHEMA, $signal['taxonomy_report_schema']);
        $this->assertSame(
            PerFailureClassRemediationHintsFromTheLoopFailureTaxonomyContract::SCHEMA,
            $signal['remediation_hints_schema'],
        );
        $this->assertSame(
            PerFailureClassRemediationHintsFromTheLoopFailureTaxonomyContract::TIER_HINT_BY_TIER_NAME,
            $signal['tier_hint_by_tier_name'],
        );
    }

    public function test_per_failure_class_remediation_hints_empty_input_returns_default_contract(): void
    {
        $result = $this->service()->perFailureClassRemediationHints([]);

        $this->assertSame(
            PerFailureClassRemediationHintsFromTheLoopFailureTaxonomyContract::defaults()->toArray(),
            $result,
        );
        $this->assertSame(
            PerFailureClassRemediationHintsFromTheLoopFailureTaxonomyContract::SCHEMA,
            $result['schema_version'],
        );
        $this->assertNull($result['outputs']['remediation_hint']);
        $this->assertFalse($result['outputs']['hint_recognized']);
        $this->assertFalse($result['outputs']['actionable_for_runner']);
    }

    public function test_per_failure_class_remediation_hints_setup_failure_returns_retryable(): void
    {
        $result = $this->service()->perFailureClassRemediationHints([
            'tier' => LoopCycleFailureTaxonomyService::TIER_SETUP,
            'tier_name' => LoopCycleFailureTaxonomyService::TIER_SETUP_NAME,
            'specific_reason' => 'git_worktree_add_failed',
            'recovery_action' => LoopCycleFailureTaxonomyService::RECOVERY_AUTO_REPAIR_ENV,
            'classified' => true,
        ]);

        $this->assertSame(LoopCycleFailureTaxonomyService::TIER_SETUP, $result['inputs']['tier']);
        $this->assertSame('setup_failure', $result['inputs']['tier_name']);
        $this->assertSame('git_worktree_add_failed', $result['inputs']['specific_reason']);
        $this->assertSame(
            PerFailureClassRemediationHintsFromTheLoopFailureTaxonomyContract::HINT_RETRYABLE,
            $result['outputs']['remediation_hint'],
        );
        $this->assertTrue($result['outputs']['hint_recognized']);
        $this->assertTrue($result['outputs']['actionable_for_runner']);
    }

    // ------------------------------------------------------------------
    // Runner integration — cascade detection + responsive kill switch
    // ------------------------------------------------------------------

    private function runner(string $tmp): Reliable24hLoopRunnerService
    {
        $service = app(Reliable24hLoopRunnerService::class);
        $service->setStorageRootForTesting($tmp);
        app(AreaFocusCandidateQuarantineService::class)->setStorageRootForTesting($tmp.'/quarantine');

        return $service;
    }

    /**
     * Scripted AP-786 session runner (test double only) returning a single cycle.
     *
     * @param  callable(int):array<string,mixed>  $cycleFor
     * @return callable(array<string,mixed>):array<string,mixed>
     */
    private function fakeSessionRunner(callable $cycleFor): callable
    {
        $calls = 0;

        return function (array $input) use (&$calls, $cycleFor): array {
            $calls++;

            return ['status' => 'completed', 'cycles' => [$cycleFor($calls)]];
        };
    }

    public function test_runner_halts_on_execution_failure_cascade(): void
    {
        $tmp = sys_get_temp_dir().'/atlas_taxonomy_'.uniqid('', true);
        File::ensureDirectoryExists($tmp);

        try {
            $service = $this->runner($tmp);
            $service->setSleeperForTesting(static fn (int $s): null => null);
            // A DIFFERENT execution-failure finding each cycle so duplicate-finding
            // protection never fires — the cascade is on the TIER, not the finding.
            $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n): array => [
                'cycle_id' => 'c'.$n,
                'final_status' => 'blocked',
                'selected_finding' => ['finding_id' => 'exec_fail_'.$n],
                'merge_performed' => false,
                'blockers' => ['owner_runtime_senior_loop_execution_not_passed'],
            ]));

            $report = $service->run([
                'area_id' => 'agentic_engineering_os',
                'focus' => 'dev_forge',
                'execute' => true,
                'continue_on_blocked' => true,
                'max_cycles' => 50,
                'max_blocked_in_row' => 100, // high so the cascade, not blocked-in-row, stops us
            ]);

            $this->assertSame(Reliable24hLoopRunnerService::STATUS_CASCADE_HALT, $report['status']);
            $this->assertStringContainsString('execution_failure_cascade', $report['stop_reason']);
            // Halts after the threshold (3 consecutive), well before max_cycles.
            $this->assertSame(3, $report['cycles_this_run']);
        } finally {
            File::deleteDirectory($tmp);
        }
    }

    public function test_runner_policy_cascade_does_not_halt_when_a_different_finding_can_still_be_tried(): void
    {
        $tmp = sys_get_temp_dir().'/atlas_taxonomy_'.uniqid('', true);
        File::ensureDirectoryExists($tmp);

        try {
            $service = $this->runner($tmp);
            $service->setSleeperForTesting(static fn (int $s): null => null);
            // Repeated tier-4 policy failures (merge_not_performed), each on a
            // DIFFERENT finding, with NO merge budget set. Unlike a tier-2 execution
            // cascade (which halts), a tier-4 policy cascade must keep trying a
            // different finding — it stops only on the ordinary max_cycles budget,
            // never on a STATUS_CASCADE_HALT.
            $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n): array => [
                'cycle_id' => 'c'.$n,
                'final_status' => 'blocked',
                'selected_finding' => ['finding_id' => 'policy_fail_'.$n],
                'merge_performed' => false,
                'blockers' => ['merge_not_performed'],
            ]));

            $report = $service->run([
                'area_id' => 'agentic_engineering_os',
                'focus' => 'dev_forge',
                'execute' => true,
                'continue_on_blocked' => true,
                'max_cycles' => 6,
                'max_blocked_in_row' => 100,
            ]);

            // It ran to the cycle budget (did NOT halt early like an execution cascade).
            $this->assertSame(Reliable24hLoopRunnerService::STATUS_BUDGET, $report['status']);
            $this->assertStringContainsString('max_cycles', $report['stop_reason']);
            $this->assertSame(6, $report['cycles_this_run']);
            $this->assertNotSame(Reliable24hLoopRunnerService::STATUS_CASCADE_HALT, $report['status']);
        } finally {
            File::deleteDirectory($tmp);
        }
    }

    public function test_runner_stops_immediately_on_budget_exhausted_failure_tier(): void
    {
        $tmp = sys_get_temp_dir().'/atlas_taxonomy_'.uniqid('', true);
        File::ensureDirectoryExists($tmp);

        try {
            $service = $this->runner($tmp);
            $service->setSleeperForTesting(static fn (int $s): null => null);
            // A cycle whose blocker is a real ceiling (tier-5). The runner must stop
            // HONESTLY on the first occurrence (STATUS_BUDGET), not wait for the
            // blocked-in-row ceiling. Note: a literal `backlog_exhausted` blocker is
            // already caught by the dedicated backlog stop, so use a runtime ceiling
            // blocker to exercise the tier-5 cascade path specifically.
            $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n): array => [
                'cycle_id' => 'c'.$n,
                'final_status' => 'blocked',
                'selected_finding' => ['finding_id' => 'ceiling_'.$n],
                'merge_performed' => false,
                'blockers' => ['max_runtime_hit'],
            ]));

            $report = $service->run([
                'area_id' => 'agentic_engineering_os',
                'focus' => 'dev_forge',
                'execute' => true,
                'continue_on_blocked' => true,
                'max_cycles' => 50,
                'max_blocked_in_row' => 100,
            ]);

            $this->assertSame(Reliable24hLoopRunnerService::STATUS_BUDGET, $report['status']);
            $this->assertSame('budget_exhausted_failure_tier', $report['stop_reason']);
            // Stopped on the FIRST tier-5 cycle, not after a blocked-in-row run.
            $this->assertSame(1, $report['cycles_this_run']);
        } finally {
            File::deleteDirectory($tmp);
        }
    }

    public function test_runner_responsive_sleep_honors_mid_sleep_kill_switch(): void
    {
        $tmp = sys_get_temp_dir().'/atlas_taxonomy_'.uniqid('', true);
        File::ensureDirectoryExists($tmp);

        try {
            $service = $this->runner($tmp);

            // A fake sleeper that drops the kill-switch file mid-sleep (simulating a
            // kill issued while the loop is sleeping between cycles). With the OLD
            // single blocking sleep this would be honored only after the FULL sleep;
            // with the responsive chunked sleep it is detected on the next chunk
            // check, so the loop stops with STATUS_KILLED rather than running cycle 2.
            $killPath = $service->killSwitchPath('agentic_engineering_os', 'dev_forge');
            $sleepChunks = 0;
            $service->setSleeperForTesting(function (int $s) use (&$sleepChunks, $killPath): void {
                $sleepChunks++;
                if ($sleepChunks === 1) {
                    File::ensureDirectoryExists(dirname($killPath));
                    File::put($killPath, 'stop');
                }
            });

            $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n): array => [
                'cycle_id' => 'c'.$n,
                'final_status' => 'cycle_completed_waiting_review_or_merge',
                'selected_finding' => ['finding_id' => 'find_'.$n],
                'merge_performed' => false,
                'blockers' => [],
            ]));

            $report = $service->run([
                'area_id' => 'agentic_engineering_os',
                'focus' => 'dev_forge',
                'execute' => true,
                'continue_on_blocked' => true,
                'max_cycles' => 20,
                'sleep_seconds' => 30, // a long sleep the kill must interrupt
            ]);

            $this->assertSame(Reliable24hLoopRunnerService::STATUS_KILLED, $report['status']);
            $this->assertSame('kill_switch_active', $report['stop_reason']);
            // Exactly one cycle ran; the kill interrupted the sleep before cycle 2.
            $this->assertSame(1, $report['cycles_this_run']);
            // The sleep was chunked (granularity 5s over 30s) — not one 30s block.
            $this->assertGreaterThanOrEqual(1, $sleepChunks);
        } finally {
            File::deleteDirectory($tmp);
        }
    }

    public function test_runner_responsive_sleep_chunks_a_long_sleep(): void
    {
        $tmp = sys_get_temp_dir().'/atlas_taxonomy_'.uniqid('', true);
        File::ensureDirectoryExists($tmp);

        try {
            $service = $this->runner($tmp);

            // Capture every chunk duration. A 30s sleep with 5s granularity must be
            // delivered as 5s chunks (max latency to honor a kill = 5s), never one
            // 30s block.
            $chunks = [];
            $service->setSleeperForTesting(function (int $s) use (&$chunks): void {
                $chunks[] = $s;
            });

            // Single cycle then budget stop, so exactly one inter-cycle sleep runs.
            $service->setSessionRunnerForTesting($this->fakeSessionRunner(fn (int $n): array => [
                'cycle_id' => 'c'.$n,
                'final_status' => 'cycle_completed_waiting_review_or_merge',
                'selected_finding' => ['finding_id' => 'find_'.$n],
                'merge_performed' => false,
                'blockers' => [],
            ]));

            $service->run([
                'area_id' => 'agentic_engineering_os',
                'focus' => 'dev_forge',
                'execute' => true,
                'continue_on_blocked' => true,
                'max_cycles' => 1,
                'sleep_seconds' => 30,
            ]);

            // max_cycles=1 stops at the budget check on iteration 2, but the single
            // inter-cycle sleep after cycle 1 must have been chunked into <=5s pieces.
            $this->assertNotSame([], $chunks, 'the inter-cycle sleep must have run');
            foreach ($chunks as $chunk) {
                $this->assertLessThanOrEqual(5, $chunk, 'each sleep chunk must be at most the 5s interrupt granularity');
            }
            $this->assertSame(30, array_sum($chunks), 'the chunks must sum to the requested sleep');
        } finally {
            File::deleteDirectory($tmp);
        }
    }
}
