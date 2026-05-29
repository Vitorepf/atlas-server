<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopDeterministicSimulatorService;
use Tests\TestCase;

final class LoopDeterministicSimulatorServiceTest extends TestCase
{
    private function service(): LoopDeterministicSimulatorService
    {
        return app(LoopDeterministicSimulatorService::class);
    }

    /**
     * DoD: 1000 cycles of the canonical built-in decision paths produce ZERO
     * critical violations and status=pass. A correct loop never violates a core
     * invariant.
     */
    public function test_runs_1000_cycles_with_zero_critical_violations_and_pass(): void
    {
        $report = $this->service()->simulate(['cycles' => 1000]);

        $this->assertSame(LoopDeterministicSimulatorService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame('LHL-04', $report['slice_id']);
        $this->assertSame('AP-808', $report['ap_contract']);
        $this->assertSame(1000, $report['cycles_run']);
        $this->assertSame(0, $report['critical_violations']);
        $this->assertSame([], $report['invariant_report']['violations']);
        $this->assertSame(LoopDeterministicSimulatorService::STATUS_PASS, $report['status']);
        $this->assertSame('continue', $report['next_action']);
        // Every canonical invariant was actually exercised across the run.
        foreach (LoopDeterministicSimulatorService::INVARIANTS as $invariantId) {
            $this->assertArrayHasKey($invariantId, $report['invariant_report']['checked']);
            $this->assertSame(1000, $report['invariant_report']['checked'][$invariantId]);
        }
    }

    public function test_default_cycle_count_is_1000(): void
    {
        $report = $this->service()->simulate();

        $this->assertSame(LoopDeterministicSimulatorService::DEFAULT_CYCLES, $report['cycles_run']);
        $this->assertSame(1000, $report['cycles_run']);
        $this->assertSame(LoopDeterministicSimulatorService::STATUS_PASS, $report['status']);
    }

    /**
     * Recovery-in-factory_max canonical scenario must be REFUSED — never provider,
     * never merged, never success.
     */
    public function test_recovery_in_factory_max_scenario_is_refused_not_merged(): void
    {
        // Small run that still covers the whole built-in scenario set.
        $report = $this->service()->simulate(['cycles' => 50]);

        $this->assertSame(LoopDeterministicSimulatorService::STATUS_PASS, $report['status']);
        $summary = $report['scenario_summary'];
        $this->assertArrayHasKey('recovery_into_factory_max_refused', $summary);

        $recovery = $summary['recovery_into_factory_max_refused'];
        $this->assertGreaterThan(0, $recovery['count']);
        $this->assertSame(0, $recovery['merged'], 'recovery into factory_max must never merge');
        $this->assertGreaterThan(0, $recovery['refused']);
        $this->assertSame('refused', $recovery['outcome']);
        $this->assertSame(0, $recovery['violations']);
    }

    /**
     * A recovery candidate that the loop WRONGLY executes in factory_max must be
     * caught as a critical violation — the simulator refuses to call it pass.
     */
    public function test_recovery_executed_in_factory_max_is_a_critical_violation(): void
    {
        $report = $this->service()->simulate([
            'cycles' => 4,
            'scenarios' => [[
                'scenario' => 'illegal_recovery_merged_in_factory_max',
                'scope_profile' => 'factory_max',
                'is_recovery' => true,
                'preflight_allow' => true,
                'provider_invoked' => true,
                'judge_verdict' => 'accept',
                'merge_performed' => true,
                'merge_target' => 'integration_lane',
                'outcome' => 'merged',
                'success' => true,
            ]],
        ]);

        $this->assertSame(LoopDeterministicSimulatorService::STATUS_FAIL, $report['status']);
        $this->assertGreaterThan(0, $report['critical_violations']);
        $invariantIds = array_column($report['invariant_report']['violations'], 'invariant');
        $this->assertContains('recovery_in_factory_max_is_critical_violation', $invariantIds);
        $this->assertSame('stop_critical_violation', $report['next_action']);
    }

    /**
     * Judge repair_required never produces a merge in the canonical set, and a
     * fixture that DOES merge on repair_required is caught as a critical violation.
     */
    public function test_judge_repair_required_never_merges(): void
    {
        // Canonical built-in repair_required path: no merge.
        $report = $this->service()->simulate(['cycles' => 50]);
        $summary = $report['scenario_summary'];
        $this->assertArrayHasKey('judge_repair_required_no_merge', $summary);
        $this->assertSame(0, $summary['judge_repair_required_no_merge']['merged']);
        $this->assertSame(0, $summary['judge_repair_required_no_merge']['violations']);

        // A loop that merges on repair_required is a critical violation.
        $violating = $this->service()->simulate([
            'cycles' => 3,
            'scenarios' => [[
                'scenario' => 'merge_on_repair_required',
                'preflight_allow' => true,
                'provider_invoked' => true,
                'judge_verdict' => 'repair_required',
                'merge_performed' => true,
                'merge_target' => 'integration_lane',
                'outcome' => 'merged',
            ]],
        ]);
        $this->assertSame(LoopDeterministicSimulatorService::STATUS_FAIL, $violating['status']);
        $invariantIds = array_column($violating['invariant_report']['violations'], 'invariant');
        $this->assertContains('merge_performed_implies_judge_accept', $invariantIds);
    }

    /**
     * Main-merge-forbidden: the canonical scenario never merges to main, and a
     * fixture where lane-mode work mutates main is caught as a critical violation.
     */
    public function test_main_merge_forbidden_is_enforced(): void
    {
        $report = $this->service()->simulate(['cycles' => 50]);
        $summary = $report['scenario_summary'];
        $this->assertArrayHasKey('main_merge_forbidden', $summary);
        $this->assertSame(0, $summary['main_merge_forbidden']['merged'], 'forbidden main merge must never happen');
        $this->assertGreaterThan(0, $summary['main_merge_forbidden']['refused']);

        // A lane-mode cycle that mutated main violates the lane invariant.
        $violating = $this->service()->simulate([
            'cycles' => 3,
            'scenarios' => [[
                'scenario' => 'lane_mode_mutated_main',
                'preflight_allow' => true,
                'provider_invoked' => true,
                'judge_verdict' => 'accept',
                'loop_mode' => 'lane',
                'main_unchanged' => false,
                'merge_performed' => true,
                'merge_target' => 'main',
                'outcome' => 'merged',
            ]],
        ]);
        $this->assertSame(LoopDeterministicSimulatorService::STATUS_FAIL, $violating['status']);
        $invariantIds = array_column($violating['invariant_report']['violations'], 'invariant');
        $this->assertContains('lane_mode_implies_main_unchanged', $invariantIds);
    }

    public function test_provider_invoked_without_preflight_allow_is_critical_violation(): void
    {
        $report = $this->service()->simulate([
            'cycles' => 2,
            'scenarios' => [[
                'scenario' => 'provider_without_allow',
                'preflight_allow' => false,
                'provider_invoked' => true,
                'judge_verdict' => 'none',
                'outcome' => 'blocked',
            ]],
        ]);

        $this->assertSame(LoopDeterministicSimulatorService::STATUS_FAIL, $report['status']);
        $invariantIds = array_column($report['invariant_report']['violations'], 'invariant');
        $this->assertContains('provider_invoked_implies_preflight_allow', $invariantIds);
    }

    public function test_blocked_counted_as_success_is_critical_violation(): void
    {
        $report = $this->service()->simulate([
            'cycles' => 2,
            'scenarios' => [[
                'scenario' => 'blocked_dressed_as_success',
                'blocked' => true,
                'success' => true,
                'outcome' => 'blocked',
            ]],
        ]);

        $this->assertSame(LoopDeterministicSimulatorService::STATUS_FAIL, $report['status']);
        $invariantIds = array_column($report['invariant_report']['violations'], 'invariant');
        $this->assertContains('blocked_implies_not_success', $invariantIds);
    }

    public function test_sandbox_commit_counted_as_merge_is_critical_violation(): void
    {
        $report = $this->service()->simulate([
            'cycles' => 2,
            'scenarios' => [[
                'scenario' => 'sandbox_commit_as_merge',
                'preflight_allow' => true,
                'provider_invoked' => true,
                'judge_verdict' => 'accept',
                'sandbox_commit_only' => true,
                'merge_performed' => true,
                'merge_target' => 'integration_lane',
                'outcome' => 'merged',
            ]],
        ]);

        $this->assertSame(LoopDeterministicSimulatorService::STATUS_FAIL, $report['status']);
        $invariantIds = array_column($report['invariant_report']['violations'], 'invariant');
        $this->assertContains('sandbox_commit_only_implies_not_merge', $invariantIds);
    }

    public function test_forge_plan_only_counted_as_implementation_is_critical_violation(): void
    {
        $report = $this->service()->simulate([
            'cycles' => 2,
            'scenarios' => [[
                'scenario' => 'forge_plan_only_as_impl',
                'preflight_allow' => true,
                'provider_invoked' => true,
                'forge_plan_only' => true,
                'implementation_claimed' => true,
                'outcome' => 'planned',
            ]],
        ]);

        $this->assertSame(LoopDeterministicSimulatorService::STATUS_FAIL, $report['status']);
        $invariantIds = array_column($report['invariant_report']['violations'], 'invariant');
        $this->assertContains('forge_plan_only_implies_not_implementation', $invariantIds);
    }

    public function test_packet_completed_without_merge_is_critical_violation(): void
    {
        $report = $this->service()->simulate([
            'cycles' => 2,
            'scenarios' => [[
                'scenario' => 'completed_without_merge',
                'preflight_allow' => true,
                'provider_invoked' => true,
                'judge_verdict' => 'accept',
                'packet_completed' => true,
                'merge_performed' => false,
                'outcome' => 'blocked',
            ]],
        ]);

        $this->assertSame(LoopDeterministicSimulatorService::STATUS_FAIL, $report['status']);
        $invariantIds = array_column($report['invariant_report']['violations'], 'invariant');
        $this->assertContains('packet_completed_implies_merge_performed', $invariantIds);
    }

    public function test_final_clean_with_leftovers_is_critical_violation(): void
    {
        $report = $this->service()->simulate([
            'cycles' => 2,
            'scenarios' => [[
                'scenario' => 'final_clean_with_locks',
                'final_clean' => true,
                'locks_remaining' => 1,
                'orphans_remaining' => 2,
                'outcome' => 'cleanup',
            ]],
        ]);

        $this->assertSame(LoopDeterministicSimulatorService::STATUS_FAIL, $report['status']);
        $invariantIds = array_column($report['invariant_report']['violations'], 'invariant');
        $this->assertContains('final_clean_implies_zero_locks_and_zero_orphans', $invariantIds);
    }

    public function test_repeated_blocker_not_flagged_as_duplicate_spin_is_critical_violation(): void
    {
        $report = $this->service()->simulate([
            'cycles' => 2,
            'scenarios' => [[
                'scenario' => 'repeated_blocker_unflagged',
                'finding_id' => 'AAEOS-200',
                'packet_id' => 'slice-1',
                'blocker' => 'validation_failed',
                'repeated_blocker' => true,
                'duplicate_spin' => false,
                'outcome' => 'blocked',
            ]],
        ]);

        $this->assertSame(LoopDeterministicSimulatorService::STATUS_FAIL, $report['status']);
        $invariantIds = array_column($report['invariant_report']['violations'], 'invariant');
        $this->assertContains('same_finding_same_packet_same_blocker_repeated_is_duplicate_spin', $invariantIds);
    }

    /**
     * A custom scenario set (input seam) overrides the built-in scenarios.
     */
    public function test_custom_scenarios_override_built_in_set(): void
    {
        $report = $this->service()->simulate([
            'cycles' => 6,
            'scenarios' => [
                [
                    'scenario' => 'clean_lane_merge',
                    'preflight_allow' => true,
                    'provider_invoked' => true,
                    'judge_verdict' => 'accept',
                    'loop_mode' => 'lane',
                    'main_unchanged' => true,
                    'merge_performed' => true,
                    'merge_target' => 'integration_lane',
                    'packet_completed' => true,
                    'final_clean' => true,
                    'outcome' => 'merged',
                    'success' => true,
                ],
            ],
        ]);

        $this->assertSame(LoopDeterministicSimulatorService::STATUS_PASS, $report['status']);
        $this->assertSame(1, $report['scenario_count']);
        $this->assertArrayHasKey('clean_lane_merge', $report['scenario_summary']);
        $this->assertSame(6, $report['scenario_summary']['clean_lane_merge']['count']);
        $this->assertSame(6, $report['scenario_summary']['clean_lane_merge']['merged']);
    }

    public function test_accepts_scenarios_via_fixture_input_seam(): void
    {
        $report = $this->service()->simulate([
            'fixture' => [
                'cycles' => 4,
                'scenarios' => [[
                    'scenario' => 'fixture_clean_merge',
                    'preflight_allow' => true,
                    'provider_invoked' => true,
                    'judge_verdict' => 'accept',
                    'merge_performed' => true,
                    'merge_target' => 'integration_lane',
                    'outcome' => 'merged',
                    'success' => true,
                ]],
            ],
        ]);

        $this->assertSame(LoopDeterministicSimulatorService::STATUS_PASS, $report['status']);
        $this->assertSame(4, $report['cycles_run']);
        $this->assertArrayHasKey('fixture_clean_merge', $report['scenario_summary']);
    }

    /**
     * DETERMINISM: same input twice => identical report_hash (volatile fields
     * stripped). A different cycle count must produce a different hash.
     */
    public function test_emits_a_stable_report_hash(): void
    {
        $first = $this->service()->simulate(['cycles' => 1000]);
        $second = $this->service()->simulate(['cycles' => 1000]);

        $this->assertArrayHasKey('report_hash', $first);
        $this->assertStringStartsWith('sha256:', $first['report_hash']);
        $this->assertSame(
            $first['report_hash'],
            $second['report_hash'],
            'same input must produce an identical report_hash (volatile fields stripped)',
        );

        // A failing run must hash stably too AND differ from the passing hash.
        $failingInput = [
            'cycles' => 3,
            'scenarios' => [[
                'scenario' => 'illegal_merge_on_repair',
                'preflight_allow' => true,
                'provider_invoked' => true,
                'judge_verdict' => 'repair_required',
                'merge_performed' => true,
                'merge_target' => 'integration_lane',
                'outcome' => 'merged',
            ]],
        ];
        $f1 = $this->service()->simulate($failingInput);
        $f2 = $this->service()->simulate($failingInput);
        $this->assertSame($f1['report_hash'], $f2['report_hash']);
        $this->assertNotSame($first['report_hash'], $f1['report_hash']);
    }

    public function test_hostile_cycle_count_is_clamped_and_never_crashes(): void
    {
        $zero = $this->service()->simulate(['cycles' => 0]);
        $this->assertSame(LoopDeterministicSimulatorService::DEFAULT_CYCLES, $zero['cycles_run']);

        $negative = $this->service()->simulate(['cycles' => -50]);
        $this->assertSame(LoopDeterministicSimulatorService::DEFAULT_CYCLES, $negative['cycles_run']);

        $huge = $this->service()->simulate(['cycles' => 9999999]);
        $this->assertSame(LoopDeterministicSimulatorService::MAX_CYCLES, $huge['cycles_run']);
    }

    public function test_default_empty_input_does_not_crash_and_passes_clean(): void
    {
        $report = $this->service()->simulate();

        $this->assertSame(LoopDeterministicSimulatorService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(LoopDeterministicSimulatorService::STATUS_PASS, $report['status']);
        $this->assertSame(0, $report['critical_violations']);
        $this->assertFalse($report['claim_policy']['runs_provider']);
        $this->assertFalse($report['claim_policy']['runs_merge']);
        $this->assertTrue($report['claim_policy']['read_only']);
        $this->assertTrue($report['claim_policy']['blocked_never_dressed_as_ready']);
    }
}
