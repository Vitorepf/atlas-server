<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\DepartmentMaturityCheckContract;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopPreflightCycleFirewallService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\TheAdmissionDeficitReasonContract;
use Tests\TestCase;

final class LoopPreflightCycleFirewallServiceTest extends TestCase
{
    private function service(): LoopPreflightCycleFirewallService
    {
        return app(LoopPreflightCycleFirewallService::class);
    }

    /**
     * A bounded Self-Construction packet under a lane envelope that passes ALL
     * five gates (A-E). Tests mutate a copy of this to drive each negative case.
     *
     * @return array<string,mixed>
     */
    private function allowFixture(): array
    {
        return [
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'run_id' => 'run-001',
            'cycle_index' => 1,
            'scope_profile' => 'factory_max',
            'autonomous' => true,
            'merge_target' => 'integration_lane',
            // Gate A — clean environment.
            'kill_switch_active' => false,
            'stale_lock' => false,
            'loop_lock_held' => false,
            'dirty_unrelated_paths' => [],
            'orphan_sandbox_blocks_packet' => false,
            'open_merge_leases' => 0,
            'lane_head' => 'abc123',
            'lane_head_resolvable' => true,
            'provider_processes_remaining' => 0,
            // Gate D — armed lane envelope, code-capable owner.
            'envelope_armed' => true,
            'envelope_risk_ceiling' => 'medium',
            'owner_runtime' => 'atlas_dev',
            'owner_can_produce_code' => true,
            // Gate E — cost ready.
            'per_run_budget_exhausted' => false,
            'provider_timeout_seconds' => 600,
            'provider_routing_required' => false,
            'blocked_in_row' => 0,
            'max_blocked_in_row' => 14,
            'preflight_can_write_receipt' => true,
            // Gate B — admissible candidate.
            'candidate' => [
                'finding_id' => 'AAEOS-001::packet::1',
                'origin_type' => 'self_construction_admission_packet',
                'source' => 'self_construction_packet',
                'kind' => 'feature',
                'severity' => 'high',
                'title' => 'Self-Construction packet 1 — execute ONLY this bounded step',
                'why_it_matters' => 'Closes a real runtime gap in the loop control plane.',
                'evidence_refs' => ['expected_test:LoopFoo'],
                'active_slice_id' => 'slice-001',
                'requires_bounded_packet' => true,
                'cross_system' => false,
            ],
            // Gate C — fully-specified bounded packet.
            'packet' => [
                'parent_finding_id' => 'AAEOS-001',
                'packet_id' => 'slice-001',
                'slice_sequence' => 1,
                'active_slice_id' => 'slice-001',
                'allowed_files' => ['app/Services/Ai/Foo.php', 'tests/Unit/Ai/FooTest.php'],
                'forbidden_files' => ['config/atlas.php'],
                'required_tests' => ['tests/Unit/Ai/FooTest.php'],
                'expected_diff_shape' => 'small_focused',
                'stop_conditions' => ['scope_violation', 'validation_failed'],
                'owner_runtime' => 'atlas_dev',
                'claim' => ['claim_id' => 'c1'],
                'lease' => ['lease_id' => 'l1', 'ttl' => 900],
                'risk_level' => 'medium',
                'status' => 'planned',
                'objective' => 'Execute only bounded packet 1 within allowed_files.',
                'dependency_state' => 'not_required',
            ],
        ];
    }

    public function test_allows_bounded_self_construction_packet_under_lane_envelope(): void
    {
        $report = $this->service()->evaluate($this->allowFixture());

        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_ALLOW, $report['status']);
        $this->assertTrue($report['provider_allowed']);
        $this->assertSame('integration_lane', $report['merge_target']);
        $this->assertSame([], $report['blockers']);
        $this->assertNull($report['block_status']);
        $this->assertSame('continue', $report['next_action']);
        foreach (['environment', 'candidate_truth', 'packet_fitness', 'authority', 'cost'] as $gate) {
            $this->assertSame('passed', $report['gates'][$gate], "gate {$gate} should pass for the canonical allow fixture");
        }
        $this->assertSame('self_construction_packet', $report['candidate']['source']);
    }

    public function test_blocks_starvation_recovery_in_autonomous_factory_max(): void
    {
        $input = $this->allowFixture();
        $input['candidate']['is_starvation_recovery'] = true;
        $input['candidate']['origin_type'] = 'starvation_recovery';

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $report['status']);
        $this->assertFalse($report['provider_allowed']);
        $this->assertContains('starvation_recovery_in_autonomous_factory_max', $report['blockers']);
        $this->assertSame('blocked', $report['gates']['candidate_truth']);
        $this->assertSame(LoopPreflightCycleFirewallService::BLOCK_BACKLOG_EXHAUSTED, $report['block_status']);
    }

    public function test_blocks_routine_missing_test_filler_in_high_power_mode(): void
    {
        $input = $this->allowFixture();
        $input['scope_profile'] = 'factory_max'; // high-power / certification mode
        $input['candidate']['kind'] = 'missing_test';
        $input['candidate']['is_missing_test_filler'] = true;
        $input['candidate']['strategic_value'] = false;
        $input['candidate']['title'] = 'Add missing test for helper';

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $report['status']);
        $this->assertFalse($report['provider_allowed']);
        $this->assertContains('routine_missing_test_filler_in_high_power_mode', $report['blockers']);
        $this->assertSame('blocked', $report['gates']['candidate_truth']);
    }

    public function test_blocks_benchmark_rivals_as_implementation(): void
    {
        $input = $this->allowFixture();
        $input['candidate']['kind'] = 'benchmark';
        $input['candidate']['title'] = 'Benchmark/rivals run masquerading as implementation';

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $report['status']);
        $this->assertFalse($report['provider_allowed']);
        $this->assertContains('benchmark_or_rivals_as_implementation', $report['blockers']);
        $this->assertSame(LoopPreflightCycleFirewallService::BLOCK_ADMISSION_BLOCKED, $report['block_status']);
    }

    public function test_blocks_cross_system_without_envelope(): void
    {
        $input = $this->allowFixture();
        $input['candidate']['cross_system'] = true;
        $input['envelope_armed'] = false;

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $report['status']);
        $this->assertFalse($report['provider_allowed']);
        $this->assertContains('cross_system_without_armed_envelope', $report['blockers']);
        $this->assertSame('blocked', $report['gates']['authority']);
    }

    public function test_blocks_cross_system_autonomous_to_main(): void
    {
        $input = $this->allowFixture();
        $input['candidate']['cross_system'] = true;
        $input['autonomous'] = true;
        $input['merge_target'] = 'main';
        $input['factory_scoped'] = true; // isolate the routing violation
        $input['envelope_armed'] = true;

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $report['status']);
        $this->assertFalse($report['provider_allowed']);
        $this->assertContains('cross_system_autonomous_to_main', $report['blockers']);
        $this->assertSame('blocked', $report['gates']['authority']);
    }

    public function test_provider_allowed_is_false_whenever_status_is_block(): void
    {
        // Drive a Gate-E block (timeout below floor) and assert the hard invariant:
        // status=block => provider_allowed=false (no provider call before allow).
        $input = $this->allowFixture();
        $input['provider_timeout_seconds'] = 60;

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $report['status']);
        $this->assertFalse($report['provider_allowed']);
        $this->assertContains('provider_timeout_below_floor', $report['blockers']);
        $this->assertSame('blocked', $report['gates']['cost']);
    }

    public function test_empty_candidate_is_honest_backlog_exhaustion_not_recovery(): void
    {
        // NEGATIVE INVARIANT: no admissible candidate => backlog_exhausted, never a
        // synthetic recovery dressed as work.
        $input = $this->allowFixture();
        unset($input['candidate'], $input['packet']);

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $report['status']);
        $this->assertFalse($report['provider_allowed']);
        $this->assertSame(LoopPreflightCycleFirewallService::BLOCK_BACKLOG_EXHAUSTED, $report['block_status']);
        $this->assertContains('no_admissible_candidate_backlog_exhausted', $report['blockers']);
    }

    public function test_blocks_high_value_work_without_bounded_packet(): void
    {
        $input = $this->allowFixture();
        unset($input['packet']);
        unset($input['candidate']['self_construction_packet']);
        $input['candidate']['requires_bounded_packet'] = true;
        $input['candidate']['severity'] = 'high';

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $report['status']);
        $this->assertContains('high_value_work_without_bounded_packet', $report['blockers']);
        $this->assertSame('blocked', $report['gates']['packet_fitness']);
    }

    public function test_blocks_packet_text_that_says_implement_whole_feature(): void
    {
        $input = $this->allowFixture();
        $input['packet']['objective'] = 'Implement the whole feature end to end.';

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $report['status']);
        $this->assertContains('packet_text_says_implement_whole_feature', $report['blockers']);
        $this->assertSame('blocked', $report['gates']['packet_fitness']);
    }

    public function test_blocks_packet_missing_required_fields(): void
    {
        $input = $this->allowFixture();
        unset($input['packet']['stop_conditions'], $input['packet']['required_tests']);

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $report['status']);
        $this->assertSame('blocked', $report['gates']['packet_fitness']);
        $missing = array_filter($report['blockers'], static fn (string $b): bool => str_starts_with($b, 'packet_missing_required_fields:'));
        $this->assertNotEmpty($missing);
    }

    public function test_blocks_unbounded_packet_allowed_files(): void
    {
        $input = $this->allowFixture();
        $input['packet']['allowed_files'] = array_map(static fn (int $i): string => "app/File{$i}.php", range(1, 20));

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $report['status']);
        $this->assertContains('packet_allowed_files_not_bounded', $report['blockers']);
    }

    public function test_blocks_review_locked_without_changed_blocker(): void
    {
        $input = $this->allowFixture();
        $input['candidate']['review_locked'] = true;
        $input['candidate']['changed_blocker_reason'] = '';

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $report['status']);
        $this->assertContains('review_locked_without_changed_blocker', $report['blockers']);
        $this->assertSame(LoopPreflightCycleFirewallService::BLOCK_REVIEW_LOCKED, $report['block_status']);
    }

    public function test_blocks_quarantined_non_transient_but_allows_transient_retry(): void
    {
        $nonTransient = $this->allowFixture();
        $nonTransient['candidate']['quarantined'] = true;
        $nonTransient['candidate']['quarantine_transient'] = false;
        $blockReport = $this->service()->evaluate($nonTransient);
        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $blockReport['status']);
        $this->assertContains('quarantined_non_transient', $blockReport['blockers']);
        $this->assertSame(LoopPreflightCycleFirewallService::BLOCK_QUARANTINED, $blockReport['block_status']);

        $transient = $this->allowFixture();
        $transient['candidate']['quarantined'] = true;
        $transient['candidate']['quarantine_transient'] = true;
        $allowReport = $this->service()->evaluate($transient);
        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_ALLOW, $allowReport['status'], 'a transient quarantine must be allowed to retry');
        $this->assertContains('candidate_transient_quarantine_retry', $allowReport['warnings']);
    }

    public function test_blocks_duplicate_finding_blocker_slice_spin(): void
    {
        // NEGATIVE INVARIANT: same finding_id + same blocker + same slice repeated.
        $input = $this->allowFixture();
        $input['candidate']['last_blocker'] = 'validation_failed';
        $input['seen_finding_blocker_slice'] = [
            ['finding_id' => 'AAEOS-001::packet::1', 'blocker' => 'validation_failed', 'active_slice_id' => 'slice-001'],
        ];

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $report['status']);
        $this->assertContains('duplicate_finding_blocker_slice_spin', $report['blockers']);
        $this->assertSame(LoopPreflightCycleFirewallService::BLOCK_DUPLICATE_BLOCKED, $report['block_status']);
    }

    public function test_blocks_planned_candidate_counted_as_executable(): void
    {
        // NEGATIVE INVARIANT: planned must never be counted as executable.
        $input = $this->allowFixture();
        $input['candidate']['status'] = 'planned';
        $input['candidate']['counted_as_executable'] = true;

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $report['status']);
        $this->assertContains('planned_candidate_counted_as_executable', $report['blockers']);
    }

    public function test_blocks_missing_source_doc_evidence_or_canonical_reason(): void
    {
        $input = $this->allowFixture();
        unset($input['candidate']['evidence_refs'], $input['candidate']['why_it_matters']);
        $input['candidate']['source_doc'] = '';
        $input['candidate']['canonical_reason'] = '';

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $report['status']);
        $this->assertContains('missing_source_doc_evidence_or_canonical_reason', $report['blockers']);
    }

    public function test_blocks_main_auto_merge_not_factory_scoped(): void
    {
        $input = $this->allowFixture();
        $input['merge_target'] = 'main';
        $input['factory_scoped'] = false;
        $input['candidate']['cross_system'] = false;

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $report['status']);
        // Environment refuses a non-factory main target; authority confirms it too.
        $this->assertContains('main_target_not_factory_scoped', $report['blockers']);
        $this->assertSame('blocked', $report['gates']['authority']);
    }

    public function test_blocks_main_auto_merge_risk_over_ceiling(): void
    {
        $input = $this->allowFixture();
        $input['merge_target'] = 'main';
        $input['factory_scoped'] = true;
        $input['candidate']['cross_system'] = false;
        $input['envelope_risk_ceiling'] = 'medium';
        $input['packet']['risk_level'] = 'critical';

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $report['status']);
        $this->assertContains('main_auto_merge_risk_over_ceiling', $report['blockers']);
    }

    public function test_blocks_forge_plan_only_as_real_execution(): void
    {
        // NEGATIVE INVARIANT: a plan-only Forge path is not real implementation.
        $input = $this->allowFixture();
        $input['owner_runtime'] = 'forge';
        $input['packet']['owner_runtime'] = 'forge';
        $input['forge_plan_only'] = true;
        $input['owner_can_produce_code'] = true;

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $report['status']);
        $this->assertContains('forge_plan_only_not_real_execution', $report['blockers']);
        $this->assertSame('blocked', $report['gates']['authority']);
    }

    public function test_blocks_owner_runtime_that_cannot_produce_code(): void
    {
        $input = $this->allowFixture();
        $input['owner_runtime'] = 'forge';
        $input['packet']['owner_runtime'] = 'forge';
        $input['owner_can_produce_code'] = false;

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $report['status']);
        $this->assertContains('owner_runtime_cannot_produce_code', $report['blockers']);
    }

    public function test_blocks_environment_kill_switch_stale_lock_and_lease(): void
    {
        $kill = $this->allowFixture();
        $kill['kill_switch_active'] = true;
        $r1 = $this->service()->evaluate($kill);
        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $r1['status']);
        $this->assertContains('kill_switch_active', $r1['blockers']);
        $this->assertSame('blocked', $r1['gates']['environment']);

        $stale = $this->allowFixture();
        $stale['stale_lock'] = true;
        $r2 = $this->service()->evaluate($stale);
        $this->assertContains('stale_loop_lock_held', $r2['blockers']);

        $lease = $this->allowFixture();
        $lease['open_merge_leases'] = 1;
        $r3 = $this->service()->evaluate($lease);
        $this->assertContains('open_merge_lease_conflict', $r3['blockers']);

        $laneMissing = $this->allowFixture();
        $laneMissing['lane_head'] = null;
        $laneMissing['lane_head_resolvable'] = false;
        $r4 = $this->service()->evaluate($laneMissing);
        $this->assertContains('integration_lane_head_unresolvable', $r4['blockers']);
    }

    public function test_blocks_provider_leftovers_without_cleanup_plan_but_warns_when_planned(): void
    {
        $noPlan = $this->allowFixture();
        $noPlan['provider_processes_remaining'] = 2;
        $noPlan['provider_leftover_cleanup_planned'] = false;
        $r1 = $this->service()->evaluate($noPlan);
        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $r1['status']);
        $this->assertContains('provider_process_leftovers_without_cleanup_plan', $r1['blockers']);

        $planned = $this->allowFixture();
        $planned['provider_processes_remaining'] = 2;
        $planned['provider_leftover_cleanup_planned'] = true;
        $r2 = $this->service()->evaluate($planned);
        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_ALLOW, $r2['status']);
        $this->assertContains('provider_leftovers_cleanup_planned_before_invocation', $r2['warnings']);
    }

    public function test_blocks_max_blocked_in_row_and_already_spent_call(): void
    {
        $maxed = $this->allowFixture();
        $maxed['blocked_in_row'] = 14;
        $maxed['max_blocked_in_row'] = 14;
        $r1 = $this->service()->evaluate($maxed);
        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $r1['status']);
        $this->assertContains('max_blocked_in_row_exceeded', $r1['blockers']);

        $spent = $this->allowFixture();
        $spent['candidate']['already_spent_call_same_blocker'] = true;
        $r2 = $this->service()->evaluate($spent);
        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $r2['status']);
        $this->assertContains('same_candidate_already_spent_call_same_blocker', $r2['blockers']);
    }

    public function test_block_status_is_never_success_when_blocked(): void
    {
        // The block taxonomy must always be one of the allowed values, never `success`.
        $input = $this->allowFixture();
        $input['kill_switch_active'] = true;

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $report['status']);
        $this->assertContains($report['block_status'], [
            LoopPreflightCycleFirewallService::BLOCK_BACKLOG_EXHAUSTED,
            LoopPreflightCycleFirewallService::BLOCK_ADMISSION_BLOCKED,
            LoopPreflightCycleFirewallService::BLOCK_REVIEW_LOCKED,
            LoopPreflightCycleFirewallService::BLOCK_QUARANTINED,
            LoopPreflightCycleFirewallService::BLOCK_DUPLICATE_BLOCKED,
        ]);
        $this->assertNotSame('success', $report['block_status']);
    }

    public function test_accepts_cycle_record_via_fixture_input_seam(): void
    {
        // The wiring phase passes a whole cycle record under `fixture`; direct keys
        // still win. Here the fixture carries the allow payload.
        $report = $this->service()->evaluate(['fixture' => $this->allowFixture()]);

        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_ALLOW, $report['status']);
        $this->assertTrue($report['provider_allowed']);
    }

    public function test_emits_a_stable_report_hash(): void
    {
        $input = $this->allowFixture();

        $first = $this->service()->evaluate($input);
        $second = $this->service()->evaluate($input);

        $this->assertArrayHasKey('report_hash', $first);
        $this->assertStringStartsWith('sha256:', $first['report_hash']);
        $this->assertSame(
            $first['report_hash'],
            $second['report_hash'],
            'same input must produce an identical report_hash (volatile fields stripped)',
        );

        // A blocked input must hash stably too AND differ from the allow hash.
        $blocked = $input;
        $blocked['kill_switch_active'] = true;
        $b1 = $this->service()->evaluate($blocked);
        $b2 = $this->service()->evaluate($blocked);
        $this->assertSame($b1['report_hash'], $b2['report_hash']);
        $this->assertNotSame($first['report_hash'], $b1['report_hash']);
    }

    public function test_default_empty_input_does_not_crash_and_blocks_honestly(): void
    {
        // Diagnostic default: empty state analyzes as backlog-exhausted block, never a crash.
        $report = $this->service()->evaluate();

        $this->assertSame(LoopPreflightCycleFirewallService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(LoopPreflightCycleFirewallService::STATUS_BLOCK, $report['status']);
        $this->assertFalse($report['provider_allowed']);
        $this->assertSame(LoopPreflightCycleFirewallService::BLOCK_BACKLOG_EXHAUSTED, $report['block_status']);
        $this->assertSame('LHL-01', $report['slice_id']);
        $this->assertSame('AP-807', $report['ap_contract']);
    }

    public function test_the_admission_deficit_reason_entry_empty_input_returns_default_contract(): void
    {
        $result = $this->service()->theAdmissionDeficitReason([]);

        $this->assertSame(
            TheAdmissionDeficitReasonContract::defaults()->toArray(),
            $result,
        );
        $this->assertSame(TheAdmissionDeficitReasonContract::SCHEMA, $result['schema_version']);
        $this->assertSame('admission_deficit_reason', $result['contract_id']);
        $this->assertSame(
            LoopPreflightCycleFirewallService::BLOCK_BACKLOG_EXHAUSTED,
            $result['preflight_block_status'],
        );
        $this->assertSame(
            TheAdmissionDeficitReasonContract::REASON_GENUINELY_EMPTY,
            $result['outputs']['admission_deficit_reason'],
        );
        $this->assertSame([
            'selection_rejection_reasons' => [],
            'candidates_considered' => 0,
        ], $result['inputs']);
        $this->assertTrue($result['outputs']['surfaces_operator_actionable_reason']);
    }

    /** Step-3 first rule: selection_rejection_reasons maps through the step-1 contract. */
    public function test_the_admission_deficit_reason_classifies_all_review_locked_when_rejection_reasons_present(): void
    {
        $input = [
            'selection_rejection_reasons' => [
                'review_locked_existing_branch',
                'review_locked_existing_branch',
            ],
            'candidates_considered' => 2,
        ];

        $result = $this->service()->theAdmissionDeficitReason($input);

        $this->assertSame(
            TheAdmissionDeficitReasonContract::fromArray($input)->toArray(),
            $result,
        );
        $this->assertSame(
            TheAdmissionDeficitReasonContract::REASON_ALL_REVIEW_LOCKED,
            $result['outputs']['admission_deficit_reason'],
        );
        $this->assertSame(2, $result['outputs']['rejection_bucket_counts']['review_locked']);
        $this->assertTrue($result['outputs']['surfaces_operator_actionable_reason']);
    }

    public function test_department_maturity_check_entry_empty_input_returns_default_contract(): void
    {
        $result = $this->service()->departmentMaturityCheck([]);

        $this->assertSame(
            DepartmentMaturityCheckContract::defaults()->toArray(),
            $result,
        );
        $this->assertSame(DepartmentMaturityCheckContract::SCHEMA, $result['schema_version']);
        $this->assertSame('department_maturity_check', $result['check_id']);
        $this->assertFalse($result['outputs']['blocks_preflight_cycle']);
        $this->assertSame([], $result['outputs']['blocker_ids']);
        $this->assertSame([], $result['outputs']['immature_department_ids']);
    }

    /** Step-3 first rule: department_maturity_snapshot maps through the step-1 contract. */
    public function test_department_maturity_check_blocks_when_required_department_at_l1(): void
    {
        $input = [
            'routing_tier' => 'R3+',
            'required_department_ids' => ['dev'],
            'department_maturity_snapshot' => ['dev' => 'L1'],
        ];

        $result = $this->service()->departmentMaturityCheck($input);

        $this->assertSame(
            DepartmentMaturityCheckContract::fromArray($input)->toArray(),
            $result,
        );
        $this->assertTrue($result['outputs']['blocks_preflight_cycle']);
        $this->assertSame(
            [DepartmentMaturityCheckContract::BLOCKER_REQUIRED_DEPARTMENT_BELOW_L2],
            $result['outputs']['blocker_ids'],
        );
        $this->assertSame(['dev'], $result['outputs']['immature_department_ids']);
    }
}
