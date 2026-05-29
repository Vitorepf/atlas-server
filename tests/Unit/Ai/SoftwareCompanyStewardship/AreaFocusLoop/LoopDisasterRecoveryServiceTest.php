<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopDisasterRecoveryService;
use Tests\TestCase;

final class LoopDisasterRecoveryServiceTest extends TestCase
{
    private function service(): LoopDisasterRecoveryService
    {
        return app(LoopDisasterRecoveryService::class);
    }

    /**
     * An operator receipt that explicitly authorizes an irreversible repair.
     *
     * @return array<string,mixed>
     */
    private function operatorReceipt(): array
    {
        return [
            'receipt_id' => 'op-receipt-001',
            'approved' => true,
            'actor' => 'vitor',
            'decision' => 'authorize_irreversible_recovery',
        ];
    }

    public function test_corrupt_ledger_without_operator_receipt_needs_operator_and_preserves_evidence(): void
    {
        // Tests bullet 1: corrupt-ledger scenario w/o operator receipt =>
        // needs_operator + raw evidence preserved.
        $report = $this->service()->preflight([
            'scenario' => 'corrupt_ledger_tail',
            'ledger_path' => 'storage/atlas/.../cycle.jsonl',
            'last_valid_offset' => 42,
        ]);

        $this->assertSame(LoopDisasterRecoveryService::SCENARIO_CORRUPT_LEDGER_TAIL, $report['scenario']);
        $this->assertSame(LoopDisasterRecoveryService::STATUS_NEEDS_OPERATOR, $report['status']);
        $this->assertTrue($report['raw_evidence_preserved']);
        $this->assertTrue($report['requires_irreversible_repair']);
        $this->assertFalse($report['operator_receipt_present']);
        $this->assertContains('irreversible_repair_requires_operator_receipt', $report['blockers']);
        $this->assertSame('await_operator_receipt', $report['next_action']);

        // A reversible "preserve raw evidence" safe action must exist BEFORE cleanup.
        $safeNames = array_column($report['safe_actions'], 'action');
        $this->assertContains('preserve_raw_ledger', $safeNames);
    }

    public function test_irreversible_repair_with_operator_receipt_is_recoverable_plan(): void
    {
        // Tests bullet 2: irreversible repair WITH operator receipt => recoverable plan.
        $report = $this->service()->preflight([
            'scenario' => 'corrupt_ledger_tail',
            'ledger_path' => 'storage/atlas/.../cycle.jsonl',
            'last_valid_offset' => 42,
            'operator_receipt' => $this->operatorReceipt(),
        ]);

        $this->assertSame(LoopDisasterRecoveryService::STATUS_RECOVERABLE, $report['status']);
        $this->assertTrue($report['operator_receipt_present']);
        $this->assertTrue($report['requires_irreversible_repair']);
        $this->assertTrue($report['raw_evidence_preserved']);
        $this->assertSame('execute_recovery_plan_with_receipt', $report['next_action']);

        // The irreversible action is present, marked gated, and now authorized.
        $this->assertNotEmpty($report['irreversible_actions']);
        foreach ($report['irreversible_actions'] as $action) {
            $this->assertTrue($action['gated_on_operator_receipt']);
            $this->assertTrue($action['authorized']);
            $this->assertFalse($action['reversible']);
        }
    }

    public function test_ambiguous_state_fails_closed(): void
    {
        // Tests bullet 3: ambiguous => fail_closed. Multiple conflicting signals
        // with no explicit scenario selected.
        $report = $this->service()->preflight([
            'ledger_corrupt' => true,
            'lane_branch_missing' => true,
            'disk_threshold_exceeded' => true,
        ]);

        $this->assertSame(LoopDisasterRecoveryService::STATUS_FAIL_CLOSED, $report['status']);
        $this->assertContains('ambiguous_multiple_disaster_signals', $report['blockers']);
        $this->assertSame('stop_fail_closed', $report['next_action']);
        // Fail-closed must NOT propose any irreversible action.
        $this->assertSame([], $report['irreversible_actions']);
    }

    public function test_unknown_scenario_fails_closed_and_never_guesses(): void
    {
        $report = $this->service()->preflight(['scenario' => 'something_we_have_never_seen']);

        $this->assertSame(LoopDisasterRecoveryService::SCENARIO_UNKNOWN, $report['scenario']);
        $this->assertSame(LoopDisasterRecoveryService::STATUS_FAIL_CLOSED, $report['status']);
        $this->assertContains('unknown_or_undiagnosable_disaster_scenario', $report['blockers']);
        $this->assertFalse($report['guessed']);
        $this->assertSame([], $report['irreversible_actions']);
    }

    public function test_never_proposes_an_authorized_irreversible_action_without_a_receipt(): void
    {
        // Tests bullet 4: never guesses — no irreversible action is auto-authorized
        // without an operator receipt, across EVERY scenario that can require one.
        foreach (['corrupt_ledger_tail', 'dirty_sandbox', 'unexpected_main_lane_divergence'] as $scenario) {
            $report = $this->service()->preflight([
                'scenario' => $scenario,
                'ledger_path' => 'l.jsonl',
                'last_valid_offset' => 1,
                'sandbox_path' => 'wt/sandbox',
                'dirty_paths' => ['app/Foo.php'],
                'main_head' => 'aaa',
                'lane_head' => 'bbb',
                'expected_merge_base' => 'ccc',
            ]);

            $this->assertSame(LoopDisasterRecoveryService::STATUS_NEEDS_OPERATOR, $report['status'], "scenario {$scenario} must gate on operator receipt");
            $this->assertNotEmpty($report['irreversible_actions'], "scenario {$scenario} should expose its irreversible plan");
            foreach ($report['irreversible_actions'] as $action) {
                $this->assertTrue($action['gated_on_operator_receipt']);
                $this->assertFalse($action['authorized'], "scenario {$scenario} irreversible action must NOT be authorized without a receipt");
                $this->assertTrue($action['requires_operator_receipt']);
            }
            // Honesty: a blank/empty receipt does not authorize anything.
            $this->assertFalse($report['operator_receipt_present']);
        }
    }

    public function test_blank_operator_receipt_does_not_authorize_irreversible_repair(): void
    {
        $report = $this->service()->preflight([
            'scenario' => 'corrupt_ledger_tail',
            'ledger_path' => 'l.jsonl',
            'last_valid_offset' => 1,
            'operator_receipt' => ['receipt_id' => '', 'approved' => true],
        ]);

        $this->assertSame(LoopDisasterRecoveryService::STATUS_NEEDS_OPERATOR, $report['status']);
        $this->assertFalse($report['operator_receipt_present']);

        // Explicitly NOT-approved receipt also does not authorize.
        $rejected = $this->service()->preflight([
            'scenario' => 'corrupt_ledger_tail',
            'ledger_path' => 'l.jsonl',
            'last_valid_offset' => 1,
            'operator_receipt' => ['receipt_id' => 'op-2', 'approved' => false],
        ]);
        $this->assertSame(LoopDisasterRecoveryService::STATUS_NEEDS_OPERATOR, $rejected['status']);
        $this->assertFalse($rejected['operator_receipt_present']);
    }

    public function test_missing_lane_branch_with_known_good_ref_is_recoverable_reversibly(): void
    {
        // A purely-reversible scenario needs NO operator receipt to be recoverable.
        $report = $this->service()->preflight([
            'scenario' => 'missing_lane_branch',
            'lane_branch' => 'atlas/integration-lane',
            'lane_recreate_from' => 'refs/atlas/lane-last-good',
        ]);

        $this->assertSame(LoopDisasterRecoveryService::SCENARIO_MISSING_LANE_BRANCH, $report['scenario']);
        $this->assertSame(LoopDisasterRecoveryService::STATUS_RECOVERABLE, $report['status']);
        $this->assertFalse($report['requires_irreversible_repair']);
        $this->assertSame([], $report['irreversible_actions']);
        $this->assertSame('execute_safe_recovery_plan', $report['next_action']);
    }

    public function test_missing_lane_branch_without_known_good_ref_fails_closed(): void
    {
        // No known-good source => recreating the lane would be a guess => fail closed.
        $report = $this->service()->preflight([
            'scenario' => 'missing_lane_branch',
            'lane_branch' => 'atlas/integration-lane',
        ]);

        $this->assertSame(LoopDisasterRecoveryService::STATUS_FAIL_CLOSED, $report['status']);
        $this->assertSame([], $report['irreversible_actions']);
    }

    public function test_lock_owner_liveness_unknown_fails_closed_never_assumes_dead(): void
    {
        // NEVER guess the owner is dead. Unknown liveness => fail closed.
        $report = $this->service()->preflight([
            'scenario' => 'lock_owner_dead',
            'lock_path' => 'storage/atlas/.../loop.lock',
            'lock_owner_pid' => '12345',
        ]);

        $this->assertSame(LoopDisasterRecoveryService::STATUS_FAIL_CLOSED, $report['status']);
        $this->assertContains('lock_owner_liveness_unknown', $report['blockers']);
        $this->assertSame([], $report['irreversible_actions']);
    }

    public function test_lock_owner_confirmed_dead_is_recoverable_reversibly(): void
    {
        $report = $this->service()->preflight([
            'scenario' => 'lock_owner_dead',
            'lock_path' => 'storage/atlas/.../loop.lock',
            'lock_owner_pid' => '12345',
            'lock_owner_alive' => false,
        ]);

        $this->assertSame(LoopDisasterRecoveryService::STATUS_RECOVERABLE, $report['status']);
        $this->assertFalse($report['requires_irreversible_repair']);
        $this->assertSame([], $report['irreversible_actions']);
        $safeNames = array_column($report['safe_actions'], 'action');
        $this->assertContains('break_stale_lock', $safeNames);
        $this->assertContains('archive_stale_lock', $safeNames);
    }

    public function test_lock_owner_still_alive_must_not_break_lock(): void
    {
        $report = $this->service()->preflight([
            'scenario' => 'lock_owner_dead',
            'lock_path' => 'storage/atlas/.../loop.lock',
            'lock_owner_pid' => '12345',
            'lock_owner_alive' => true,
        ]);

        $this->assertNotSame(LoopDisasterRecoveryService::STATUS_RECOVERABLE, $report['status']);
        $this->assertContains('lock_owner_still_alive', $report['blockers']);
        $this->assertSame([], $report['irreversible_actions']);
    }

    public function test_missing_evidence_receipt_is_never_fabricated(): void
    {
        $report = $this->service()->preflight([
            'scenario' => 'missing_evidence_receipt',
            'cycle_ref' => 'run-001#cycle-7',
        ]);

        $this->assertSame(LoopDisasterRecoveryService::SCENARIO_MISSING_EVIDENCE_RECEIPT, $report['scenario']);
        $this->assertContains('evidence_receipt_missing_cannot_be_fabricated', $report['blockers']);
        $this->assertSame([], $report['irreversible_actions']);
        // No action fabricates a receipt out of nothing.
        $safeNames = array_column($report['safe_actions'], 'action');
        $this->assertNotContains('fabricate_receipt', $safeNames);
    }

    public function test_dirty_sandbox_force_clean_is_irreversible_and_gated(): void
    {
        $report = $this->service()->preflight([
            'scenario' => 'dirty_sandbox',
            'sandbox_path' => 'wt/sandbox-001',
            'dirty_paths' => ['app/Services/Ai/Foo.php'],
        ]);

        $this->assertSame(LoopDisasterRecoveryService::SCENARIO_DIRTY_SANDBOX, $report['scenario']);
        $this->assertSame(LoopDisasterRecoveryService::STATUS_NEEDS_OPERATOR, $report['status']);
        $this->assertTrue($report['raw_evidence_preserved']);
        $irreversibleNames = array_column($report['irreversible_actions'], 'action');
        $this->assertContains('force_clean_sandbox', $irreversibleNames);
        $safeNames = array_column($report['safe_actions'], 'action');
        $this->assertContains('preserve_sandbox_diff', $safeNames);
    }

    public function test_disk_threshold_exceeded_is_reversible_pause(): void
    {
        $report = $this->service()->preflight([
            'scenario' => 'disk_threshold_exceeded',
            'disk_used_pct' => 96,
            'disk_threshold_pct' => 90,
        ]);

        $this->assertSame(LoopDisasterRecoveryService::SCENARIO_DISK_THRESHOLD_EXCEEDED, $report['scenario']);
        $this->assertSame(LoopDisasterRecoveryService::STATUS_RECOVERABLE, $report['status']);
        $this->assertSame([], $report['irreversible_actions']);
        $safeNames = array_column($report['safe_actions'], 'action');
        $this->assertContains('pause_loop_for_disk', $safeNames);
    }

    public function test_unexpected_divergence_without_merge_base_fails_closed(): void
    {
        $report = $this->service()->preflight([
            'scenario' => 'unexpected_main_lane_divergence',
            'main_head' => 'aaa',
            'lane_head' => 'bbb',
        ]);

        $this->assertSame(LoopDisasterRecoveryService::STATUS_FAIL_CLOSED, $report['status']);
        $this->assertContains('divergence_expected_merge_base_unknown', $report['blockers']);
        $this->assertSame([], $report['irreversible_actions']);
    }

    public function test_claim_policy_is_read_only_and_non_destructive(): void
    {
        $report = $this->service()->preflight(['scenario' => 'corrupt_ledger_tail', 'ledger_path' => 'l.jsonl', 'last_valid_offset' => 1]);

        $this->assertTrue($report['claim_policy']['read_only']);
        $this->assertFalse($report['claim_policy']['runs_provider']);
        $this->assertFalse($report['claim_policy']['runs_loop']);
        $this->assertFalse($report['claim_policy']['runs_merge']);
        $this->assertFalse($report['claim_policy']['deletes_branches']);
        $this->assertFalse($report['claim_policy']['destructive_git']);
        $this->assertFalse($report['claim_policy']['guesses_recovery']);
        $this->assertFalse($report['claim_policy']['irreversible_without_receipt']);
        $this->assertTrue($report['claim_policy']['blocked_never_dressed_as_ready']);
    }

    public function test_accepts_disaster_snapshot_via_fixture_input_seam(): void
    {
        // The wiring phase passes a whole snapshot under `fixture`; direct keys win.
        $report = $this->service()->preflight([
            'fixture' => [
                'scenario' => 'missing_lane_branch',
                'lane_branch' => 'atlas/integration-lane',
                'lane_recreate_from' => 'refs/atlas/lane-last-good',
            ],
        ]);

        $this->assertSame(LoopDisasterRecoveryService::SCENARIO_MISSING_LANE_BRANCH, $report['scenario']);
        $this->assertSame(LoopDisasterRecoveryService::STATUS_RECOVERABLE, $report['status']);
    }

    public function test_emits_a_stable_report_hash(): void
    {
        // Tests bullet 5: deterministic hash — same input twice => identical hash.
        $input = [
            'scenario' => 'corrupt_ledger_tail',
            'ledger_path' => 'storage/atlas/.../cycle.jsonl',
            'last_valid_offset' => 42,
            'operator_receipt' => $this->operatorReceipt(),
        ];

        $first = $this->service()->preflight($input);
        $second = $this->service()->preflight($input);

        $this->assertArrayHasKey('report_hash', $first);
        $this->assertStringStartsWith('sha256:', $first['report_hash']);
        $this->assertSame(
            $first['report_hash'],
            $second['report_hash'],
            'same input must produce an identical report_hash (volatile fields stripped)',
        );

        // A different authorization state must hash differently.
        $noReceipt = $input;
        unset($noReceipt['operator_receipt']);
        $n1 = $this->service()->preflight($noReceipt);
        $n2 = $this->service()->preflight($noReceipt);
        $this->assertSame($n1['report_hash'], $n2['report_hash']);
        $this->assertNotSame($first['report_hash'], $n1['report_hash']);
    }

    public function test_default_empty_input_does_not_crash_and_fails_closed(): void
    {
        // Diagnostic default: empty/undiagnosable state fails closed, never crashes.
        $report = $this->service()->preflight();

        $this->assertSame(LoopDisasterRecoveryService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame('LHL-17', $report['slice_id']);
        $this->assertSame('AP-809', $report['ap_contract']);
        $this->assertSame(LoopDisasterRecoveryService::SCENARIO_UNKNOWN, $report['scenario']);
        $this->assertSame(LoopDisasterRecoveryService::STATUS_FAIL_CLOSED, $report['status']);
        $this->assertFalse($report['guessed']);
        $this->assertSame([], $report['irreversible_actions']);
    }
}
