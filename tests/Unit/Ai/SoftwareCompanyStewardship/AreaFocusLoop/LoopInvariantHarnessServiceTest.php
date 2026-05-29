<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\E2eContractTestCountGateContract;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopCycleInvariantRegistry;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopInvariantHarnessService;
use Tests\TestCase;

final class LoopInvariantHarnessServiceTest extends TestCase
{
    private function service(): LoopInvariantHarnessService
    {
        return app(LoopInvariantHarnessService::class);
    }

    /**
     * A clean, honest lane-packet cycle: provider allowed by preflight, judge
     * accepted, lane merge that left main unchanged, packet completed via a real
     * merge, final state clean. This must PASS.
     *
     * @return array<string,mixed>
     */
    private function cleanLanePacketCycle(): array
    {
        return [
            'cycle_ref' => 'cycle-clean-1',
            'finding_id' => 'F-100',
            'packet_id' => 'P-1',
            'provider_invoked' => true,
            'preflight_status' => 'allow',
            'merge_performed' => true,
            'judge_status' => 'accepted_for_merge_governor',
            'merge_target' => 'integration_lane',
            'main_before' => 'aaaa',
            'main_after' => 'aaaa',
            'lane_before' => 'aaaa',
            'lane_after' => 'bbbb',
            'sandbox_commit_only' => false,
            'counts_as_merge' => true,
            'forge_plan_only' => false,
            'counts_as_implementation' => true,
            'scope_profile' => 'factory_max',
            'autonomous' => true,
            'candidate_kind' => 'canonical_finding',
            'final_clean' => true,
            'locks' => 0,
            'orphan_worktrees' => 0,
            'orphan_processes' => 0,
            'packet_completed' => true,
            'counts_as_success' => true,
            'blocked' => false,
        ];
    }

    public function test_clean_lane_packet_fixture_passes(): void
    {
        $report = $this->service()->evaluate(['cycles' => [$this->cleanLanePacketCycle()]]);

        $this->assertSame(LoopInvariantHarnessService::STATUS_PASS, $report['status']);
        $this->assertSame([], $report['violations']);
        $this->assertSame(0, $report['critical_violations']);
        $this->assertFalse($report['blocks_long_run_readiness']);
        $this->assertSame('continue', $report['next_action']);
        $this->assertSame(LoopInvariantHarnessService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertFalse($report['claim_policy']['runs_provider']);
        $this->assertFalse($report['claim_policy']['runs_merge']);
    }

    /**
     * The historical false-success: a merge was performed while the judge said
     * repair_required. The harness must FAIL and block long-run readiness.
     */
    public function test_historical_false_success_merge_with_judge_repair_required_fails(): void
    {
        $cycle = $this->cleanLanePacketCycle();
        $cycle['judge_status'] = 'repair_required';

        $report = $this->service()->evaluate(['cycles' => [$cycle]]);

        $this->assertSame(LoopInvariantHarnessService::STATUS_FAIL, $report['status']);
        $this->assertTrue($report['blocks_long_run_readiness']);
        $this->assertSame('stop_invariant_violation', $report['next_action']);
        $this->assertContains(
            'invariant_violation:'.LoopCycleInvariantRegistry::INVARIANT_MERGE_JUDGE,
            $report['blockers'],
        );
        $this->assertViolation($report, LoopCycleInvariantRegistry::INVARIANT_MERGE_JUDGE);
    }

    public function test_provider_invoked_without_preflight_allow_violates(): void
    {
        $cycle = $this->cleanLanePacketCycle();
        $cycle['provider_invoked'] = true;
        $cycle['preflight_status'] = 'block';

        $report = $this->service()->evaluate(['cycles' => [$cycle]]);

        $this->assertSame(LoopInvariantHarnessService::STATUS_FAIL, $report['status']);
        $this->assertViolation($report, LoopCycleInvariantRegistry::INVARIANT_PROVIDER_PREFLIGHT);
    }

    public function test_lane_mode_with_main_changed_violates(): void
    {
        $cycle = $this->cleanLanePacketCycle();
        $cycle['merge_target'] = 'integration_lane';
        $cycle['main_before'] = 'aaaa';
        $cycle['main_after'] = 'zzzz';

        $report = $this->service()->evaluate(['cycles' => [$cycle]]);

        $this->assertSame(LoopInvariantHarnessService::STATUS_FAIL, $report['status']);
        $this->assertViolation($report, LoopCycleInvariantRegistry::INVARIANT_LANE_MAIN_UNCHANGED);
    }

    public function test_blocked_counted_as_success_violates(): void
    {
        $cycle = [
            'cycle_ref' => 'blocked-as-success',
            'status' => 'blocked',
            'blocked' => true,
            'counts_as_success' => true,
        ];

        $report = $this->service()->evaluate(['cycles' => [$cycle]]);

        $this->assertSame(LoopInvariantHarnessService::STATUS_FAIL, $report['status']);
        $this->assertViolation($report, LoopCycleInvariantRegistry::INVARIANT_BLOCKED_NOT_SUCCESS);
    }

    public function test_blocked_not_counted_as_success_passes(): void
    {
        // An honest blocked cycle (valid_block) must NOT be a violation.
        $cycle = [
            'cycle_ref' => 'honest-block',
            'status' => 'valid_block',
            'blocked' => true,
            'counts_as_success' => false,
        ];

        $report = $this->service()->evaluate(['cycles' => [$cycle]]);

        $this->assertSame(LoopInvariantHarnessService::STATUS_PASS, $report['status']);
    }

    public function test_sandbox_commit_counted_as_merge_violates(): void
    {
        $cycle = [
            'cycle_ref' => 'sandbox-as-merge',
            'sandbox_commit_only' => true,
            'counts_as_merge' => true,
        ];

        $report = $this->service()->evaluate(['cycles' => [$cycle]]);

        $this->assertSame(LoopInvariantHarnessService::STATUS_FAIL, $report['status']);
        $this->assertViolation($report, LoopCycleInvariantRegistry::INVARIANT_SANDBOX_NOT_MERGE);
    }

    public function test_forge_plan_only_counted_as_implementation_violates(): void
    {
        $cycle = [
            'cycle_ref' => 'plan-as-impl',
            'forge_plan_only' => true,
            'counts_as_implementation' => true,
        ];

        $report = $this->service()->evaluate(['cycles' => [$cycle]]);

        $this->assertSame(LoopInvariantHarnessService::STATUS_FAIL, $report['status']);
        $this->assertViolation($report, LoopCycleInvariantRegistry::INVARIANT_FORGE_PLAN_NOT_IMPL);
    }

    public function test_recovery_in_factory_max_is_critical_violation(): void
    {
        $cycle = [
            'cycle_ref' => 'recovery-in-factory-max',
            'scope_profile' => 'factory_max',
            'autonomous' => true,
            'candidate_kind' => 'starvation_recovery',
            'recovery_candidate' => true,
        ];

        $report = $this->service()->evaluate(['cycles' => [$cycle]]);

        $this->assertSame(LoopInvariantHarnessService::STATUS_FAIL, $report['status']);
        $this->assertGreaterThanOrEqual(1, $report['critical_violations']);
        $this->assertViolation($report, LoopCycleInvariantRegistry::INVARIANT_RECOVERY_FACTORY_MAX);
    }

    public function test_final_clean_with_orphans_violates(): void
    {
        $cycle = [
            'cycle_ref' => 'fake-clean',
            'final_clean' => true,
            'locks' => 1,
            'orphan_worktrees' => 2,
            'orphan_processes' => 0,
        ];

        $report = $this->service()->evaluate(['cycles' => [$cycle]]);

        $this->assertSame(LoopInvariantHarnessService::STATUS_FAIL, $report['status']);
        $this->assertViolation($report, LoopCycleInvariantRegistry::INVARIANT_FINAL_CLEAN);
    }

    public function test_duplicate_spin_repeated_signature_violates(): void
    {
        $cycle = [
            'cycle_ref' => 'dup-spin',
            'finding_id' => 'F-9',
            'packet_id' => 'P-9',
            'blocker' => 'provider_timeout',
            'prior_signature' => 'F-9|P-9|provider_timeout',
        ];

        $report = $this->service()->evaluate(['cycles' => [$cycle]]);

        $this->assertSame(LoopInvariantHarnessService::STATUS_FAIL, $report['status']);
        $this->assertViolation($report, LoopCycleInvariantRegistry::INVARIANT_DUPLICATE_SPIN);
    }

    public function test_duplicate_spin_explicit_flag_violates(): void
    {
        $cycle = ['cycle_ref' => 'dup-flag', 'duplicate_spin' => true];

        $report = $this->service()->evaluate(['cycles' => [$cycle]]);

        $this->assertSame(LoopInvariantHarnessService::STATUS_FAIL, $report['status']);
        $this->assertViolation($report, LoopCycleInvariantRegistry::INVARIANT_DUPLICATE_SPIN);
    }

    public function test_packet_completed_without_merge_violates(): void
    {
        $cycle = [
            'cycle_ref' => 'packet-no-merge',
            'packet_completed' => true,
            'merge_performed' => false,
        ];

        $report = $this->service()->evaluate(['cycles' => [$cycle]]);

        $this->assertSame(LoopInvariantHarnessService::STATUS_FAIL, $report['status']);
        $this->assertViolation($report, LoopCycleInvariantRegistry::INVARIANT_PACKET_COMPLETE_MERGE);
    }

    public function test_registry_lists_all_ten_canonical_invariants(): void
    {
        $defs = (new LoopCycleInvariantRegistry)->definitions();

        $this->assertCount(10, $defs);
        $expected = [
            LoopCycleInvariantRegistry::INVARIANT_PROVIDER_PREFLIGHT,
            LoopCycleInvariantRegistry::INVARIANT_MERGE_JUDGE,
            LoopCycleInvariantRegistry::INVARIANT_LANE_MAIN_UNCHANGED,
            LoopCycleInvariantRegistry::INVARIANT_BLOCKED_NOT_SUCCESS,
            LoopCycleInvariantRegistry::INVARIANT_SANDBOX_NOT_MERGE,
            LoopCycleInvariantRegistry::INVARIANT_FORGE_PLAN_NOT_IMPL,
            LoopCycleInvariantRegistry::INVARIANT_RECOVERY_FACTORY_MAX,
            LoopCycleInvariantRegistry::INVARIANT_FINAL_CLEAN,
            LoopCycleInvariantRegistry::INVARIANT_DUPLICATE_SPIN,
            LoopCycleInvariantRegistry::INVARIANT_PACKET_COMPLETE_MERGE,
        ];
        foreach ($expected as $id) {
            $this->assertArrayHasKey($id, $defs, "registry missing invariant {$id}");
        }
    }

    public function test_report_aggregates_sim_chaos_resource_invariant_and_passes_when_all_ok(): void
    {
        $report = $this->service()->report([
            'cycles' => [$this->cleanLanePacketCycle()],
            'simulation' => ['status' => 'pass'],
            'chaos' => ['status' => 'pass'],
            'resource' => ['status' => 'ok'],
        ]);

        $this->assertSame(LoopInvariantHarnessService::STATUS_PASS, $report['status']);
        $this->assertSame('assurance_report', $report['report_kind']);
        $this->assertTrue($report['composed']['simulation']['ok']);
        $this->assertTrue($report['composed']['chaos']['ok']);
        $this->assertTrue($report['composed']['resource']['ok']);
        $this->assertTrue($report['composed']['invariant']['ok']);
        $this->assertSame([], $report['blockers']);
        $this->assertFalse($report['blocks_long_run_readiness']);
    }

    public function test_report_fails_when_a_composed_part_failed(): void
    {
        $report = $this->service()->report([
            'cycles' => [$this->cleanLanePacketCycle()],
            'simulation' => ['status' => 'pass'],
            'chaos' => ['status' => 'fail'],
            'resource' => ['status' => 'ok'],
        ]);

        $this->assertSame(LoopInvariantHarnessService::STATUS_FAIL, $report['status']);
        $this->assertTrue($report['blocks_long_run_readiness']);
        $this->assertContains('chaos_fail', $report['blockers']);
    }

    public function test_report_fails_when_a_composed_part_missing(): void
    {
        $report = $this->service()->report([
            'cycles' => [$this->cleanLanePacketCycle()],
            'simulation' => ['status' => 'pass'],
            // chaos absent
            'resource' => ['status' => 'ok'],
        ]);

        $this->assertSame(LoopInvariantHarnessService::STATUS_FAIL, $report['status']);
        $this->assertContains('chaos_report_missing', $report['blockers']);
        $this->assertFalse($report['composed']['chaos']['present']);
    }

    public function test_report_fails_when_invariants_violated_even_if_other_parts_ok(): void
    {
        $cycle = $this->cleanLanePacketCycle();
        $cycle['judge_status'] = 'repair_required'; // merge without accept

        $report = $this->service()->report([
            'cycles' => [$cycle],
            'simulation' => ['status' => 'pass'],
            'chaos' => ['status' => 'pass'],
            'resource' => ['status' => 'ok'],
        ]);

        $this->assertSame(LoopInvariantHarnessService::STATUS_FAIL, $report['status']);
        $this->assertFalse($report['composed']['invariant']['ok']);
        $this->assertSame(LoopInvariantHarnessService::STATUS_FAIL, $report['invariant_report']['status']);
    }

    public function test_empty_cycles_warns_but_does_not_falsely_violate(): void
    {
        $report = $this->service()->evaluate(['cycles' => []]);

        $this->assertSame(LoopInvariantHarnessService::STATUS_PASS, $report['status']);
        $this->assertSame(0, $report['cycles_checked']);
        $this->assertContains('no_cycles_supplied_to_invariant_harness', $report['warnings']);
    }

    public function test_fixture_seam_feeds_cycles(): void
    {
        $cycle = $this->cleanLanePacketCycle();
        $cycle['judge_status'] = 'repair_required';

        $report = $this->service()->evaluate(['fixture' => ['cycles' => [$cycle]]]);

        $this->assertSame(LoopInvariantHarnessService::STATUS_FAIL, $report['status']);
        $this->assertViolation($report, LoopCycleInvariantRegistry::INVARIANT_MERGE_JUDGE);
    }

    public function test_evaluate_is_deterministic_same_input_same_hash(): void
    {
        $cycles = [
            $this->cleanLanePacketCycle(),
            ['cycle_ref' => 'b', 'status' => 'valid_block', 'blocked' => true, 'counts_as_success' => false],
        ];

        $first = $this->service()->evaluate(['cycles' => $cycles]);
        $second = $this->service()->evaluate(['cycles' => $cycles]);

        $this->assertSame($first['report_hash'], $second['report_hash']);
        $this->assertNotSame('', $first['report_hash']);
        $this->assertStringStartsWith('sha256:', $first['report_hash']);
    }

    public function test_report_is_deterministic_same_input_same_hash(): void
    {
        $input = [
            'cycles' => [$this->cleanLanePacketCycle()],
            'simulation' => ['status' => 'pass'],
            'chaos' => ['status' => 'pass'],
            'resource' => ['status' => 'ok'],
        ];

        $first = $this->service()->report($input);
        $second = $this->service()->report($input);

        $this->assertSame($first['report_hash'], $second['report_hash']);
    }

    public function test_different_input_yields_different_hash(): void
    {
        $clean = $this->service()->evaluate(['cycles' => [$this->cleanLanePacketCycle()]]);

        $dirty = $this->cleanLanePacketCycle();
        $dirty['judge_status'] = 'repair_required';
        $failed = $this->service()->evaluate(['cycles' => [$dirty]]);

        $this->assertNotSame($clean['report_hash'], $failed['report_hash']);
    }

    public function test_e2e_contract_test_count_gate_empty_input_returns_default_contract(): void
    {
        $gate = $this->service()->e2eContractTestCountGate([]);

        $this->assertSame(E2eContractTestCountGateContract::defaults()->toArray(), $gate);
        $this->assertSame(E2eContractTestCountGateContract::GATE_ID, $gate['gate_id']);
        $this->assertSame(0, $gate['inputs']['contract_test_count']);
        $this->assertFalse($gate['outputs']['blocks_long_run_readiness']);
    }

    /**
     * Assert the report carries a violation for the given invariant id and that the
     * matching invariant summary is marked not-passed.
     *
     * @param  array<string,mixed>  $report
     */
    private function assertViolation(array $report, string $invariantId): void
    {
        $violationIds = array_column($report['violations'], 'invariant');
        $this->assertContains($invariantId, $violationIds, "expected a violation for {$invariantId}");

        $summary = null;
        foreach ($report['invariants'] as $row) {
            if (($row['invariant'] ?? null) === $invariantId) {
                $summary = $row;
                break;
            }
        }
        $this->assertNotNull($summary, "invariant summary missing for {$invariantId}");
        $this->assertFalse($summary['passed'], "invariant {$invariantId} should be marked not-passed");
    }
}
