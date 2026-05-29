<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopPostCycleAuditorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ProviderSpentWithoutMergeContract;
use Tests\TestCase;

final class LoopPostCycleAuditorServiceTest extends TestCase
{
    private function service(): LoopPostCycleAuditorService
    {
        return app(LoopPostCycleAuditorService::class);
    }

    /**
     * A clean, real, successful lane-merge cycle that passes ALL six audits
     * (A-F). Tests mutate a copy of this to drive each negative case.
     *
     * @return array<string,mixed>
     */
    private function validSuccessFixture(): array
    {
        return [
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'run_id' => 'run-001',
            'cycle_index' => 1,
            // Audit A — execution truth: provider ran behind an allowed preflight.
            'preflight_status' => 'allow',
            'preflight_allowed' => true,
            'provider_invoked' => true,
            'provider_packet_id' => 'slice-001',
            'provider_candidate_id' => 'AAEOS-001::packet::1',
            'cycle_outcome' => 'merged',
            // Audit B — judge accepted with complete evidence.
            'judge_status' => 'accepted',
            'judge_evidence' => [
                'validation' => 'php artisan test',
                'changed_files' => ['app/Services/Ai/Foo.php'],
                'scope' => ['allowed_files' => ['app/Services/Ai/Foo.php']],
                'verdict' => 'accepted',
            ],
            // Audit C — real lane merge: lane advanced, main unchanged, real hash.
            'merge_performed' => true,
            'merge_target' => 'integration_lane',
            'merge_commit' => 'deadbeef1234',
            'main_before' => 'main-aaa',
            'main_after' => 'main-aaa',
            'lane_before' => 'lane-aaa',
            'lane_after' => 'lane-bbb',
            'sandbox_commit_only' => false,
            'forge_plan_only' => false,
            // Audit D — AP-791 receipt linked + references candidate + evidence.
            'receipt_id' => 'rcpt-001',
            'receipt' => [
                'receipt_id' => 'rcpt-001',
                'run_id' => 'run-001',
                'cycle_index' => 1,
                'finding_id' => 'AAEOS-001::packet::1',
                'changed_files' => ['app/Services/Ai/Foo.php'],
                'required_tests_result' => 'pass',
                'lane_after' => 'lane-bbb',
                'evidence_pack_path' => 'storage/atlas/.../pack.json',
            ],
            'changed_files' => ['app/Services/Ai/Foo.php'],
            'required_tests_result' => 'pass',
            'operator_visible_evidence' => true,
            // Audit E — clean cleanup.
            'worktree_removed' => true,
            'sandbox_branches_remaining' => 0,
            'lock_state' => 'released',
            'provider_processes_remaining' => 0,
            'kill_switch_active' => false,
            'dirty_unrelated_paths' => [],
            // Audit F — progression truth: packet complete after merge.
            'packet_marked_complete' => true,
            'next_packet_base' => 'lane-bbb',
            'same_blocked_packet_spins' => 0,
            'max_same_packet_spins' => 3,
            'backlog_feedback_updated' => true,
            // Selected candidate + packet.
            'candidate' => [
                'finding_id' => 'AAEOS-001::packet::1',
                'origin_type' => 'self_construction_admission_packet',
                'source' => 'self_construction_packet',
                'active_slice_id' => 'slice-001',
                'packet_id' => 'slice-001',
            ],
            'packet' => [
                'packet_id' => 'slice-001',
                'active_slice_id' => 'slice-001',
                'owner_runtime' => 'atlas_dev',
                'risk_level' => 'medium',
            ],
        ];
    }

    public function test_accepts_a_real_lane_merge_as_valid_success(): void
    {
        $report = $this->service()->audit($this->validSuccessFixture());

        $this->assertSame(LoopPostCycleAuditorService::STATUS_VALID_SUCCESS, $report['status']);
        $this->assertTrue($report['counts_as_real_cycle']);
        $this->assertTrue($report['counts_as_success']);
        $this->assertSame('integration_lane', $report['merge_target']);
        $this->assertSame([], $report['violations']);
        $this->assertSame('continue', $report['next_action']);
        foreach (['execution_truth', 'judge_repair_truth', 'merge_truth', 'evidence_truth', 'cleanup_truth', 'progression_truth'] as $audit) {
            $this->assertSame('passed', $report['audits'][$audit], "audit {$audit} should pass for the canonical valid_success fixture");
        }
        $this->assertSame('self_construction_packet', $report['candidate']['source']);
    }

    public function test_detects_merge_with_judge_repair_required_as_invalid_cycle(): void
    {
        // Tests bullet: detects merge with judge=repair_required (invalid_cycle).
        $input = $this->validSuccessFixture();
        $input['judge_status'] = 'repair_required';
        $input['judge_evidence']['verdict'] = 'repair_required';

        $report = $this->service()->audit($input);

        // A blocking judge verdict behind a real merge is a critical breach of the
        // judge gate; the cycle never counts as success.
        $this->assertContains($report['status'], [
            LoopPostCycleAuditorService::STATUS_INVALID,
            LoopPostCycleAuditorService::STATUS_CRITICAL,
        ]);
        $this->assertFalse($report['counts_as_success']);
        $this->assertSame('violated', $report['audits']['judge_repair_truth']);
        $codes = array_column($report['violations'], 'code');
        $this->assertContains('merge_with_blocking_judge_verdict:repair_required', $codes);
    }

    public function test_accepts_lane_merge_only_when_lane_advanced_and_main_unchanged(): void
    {
        // Tests bullet: accepts lane merge only when lane advanced & main unchanged.

        // (a) lane did NOT advance => invalid.
        $noAdvance = $this->validSuccessFixture();
        $noAdvance['lane_after'] = $noAdvance['lane_before'];
        $r1 = $this->service()->audit($noAdvance);
        $this->assertSame(LoopPostCycleAuditorService::STATUS_INVALID, $r1['status']);
        $this->assertContains('lane_merge_claimed_but_lane_not_advanced', array_column($r1['violations'], 'code'));
        $this->assertSame('violated', $r1['audits']['merge_truth']);

        // (b) a lane merge that also moved main is a CRITICAL violation.
        $touchedMain = $this->validSuccessFixture();
        $touchedMain['main_after'] = 'main-zzz';
        $r2 = $this->service()->audit($touchedMain);
        $this->assertSame(LoopPostCycleAuditorService::STATUS_CRITICAL, $r2['status']);
        $this->assertContains('lane_merge_changed_main', array_column($r2['violations'], 'code'));
        $this->assertFalse($r2['counts_as_success']);
    }

    public function test_rejects_sandbox_only_commit_claimed_as_merge(): void
    {
        // Tests bullet: rejects sandbox-only commit as success.
        $input = $this->validSuccessFixture();
        $input['sandbox_commit_only'] = true;

        $report = $this->service()->audit($input);

        $this->assertSame(LoopPostCycleAuditorService::STATUS_INVALID, $report['status']);
        $this->assertFalse($report['counts_as_success']);
        $this->assertContains('sandbox_commit_claimed_as_merge', array_column($report['violations'], 'code'));
        $this->assertSame('violated', $report['audits']['merge_truth']);
    }

    public function test_rejects_plan_only_forge_claimed_as_implementation(): void
    {
        // A plan-only Forge path is NOT real implementation.
        $input = $this->validSuccessFixture();
        $input['forge_plan_only'] = true;

        $report = $this->service()->audit($input);

        $this->assertSame(LoopPostCycleAuditorService::STATUS_INVALID, $report['status']);
        $this->assertFalse($report['counts_as_success']);
        $this->assertContains('forge_plan_only_claimed_as_implementation', array_column($report['violations'], 'code'));
    }

    public function test_rejects_provider_call_without_preflight_allow_as_critical_violation(): void
    {
        // Tests bullet: rejects provider call without preflight allow (critical_violation).
        $input = $this->validSuccessFixture();
        $input['preflight_status'] = 'block';
        $input['preflight_allowed'] = false;

        $report = $this->service()->audit($input);

        $this->assertSame(LoopPostCycleAuditorService::STATUS_CRITICAL, $report['status']);
        $this->assertFalse($report['counts_as_real_cycle']);
        $this->assertFalse($report['counts_as_success']);
        $this->assertSame('stop_critical_violation', $report['next_action']);
        $codes = array_column($report['violations'], 'code');
        $this->assertContains('provider_invoked_without_preflight_allow', $codes);
        $this->assertContains('preflight_block_but_provider_invoked', $codes);
        $this->assertSame('violated', $report['audits']['execution_truth']);
    }

    public function test_detects_orphan_worktree_branch_lock_and_provider_process(): void
    {
        // Tests bullet: detects orphan worktree/branch/lock/provider proc.
        $input = $this->validSuccessFixture();
        $input['worktree_removed'] = false;
        $input['worktree_retained_reason'] = '';
        $input['sandbox_branches_remaining'] = 2;
        $input['lock_state'] = 'held';
        $input['provider_processes_remaining'] = 1;

        $report = $this->service()->audit($input);

        $codes = array_column($report['violations'], 'code');
        $this->assertContains('orphan_worktree_without_retention_reason', $codes);
        $this->assertContains('orphan_sandbox_branches_remaining', $codes);
        $this->assertContains('loop_lock_not_released_or_renewed', $codes);
        $this->assertContains('orphan_provider_process_remaining', $codes);
        $this->assertSame('violated', $report['audits']['cleanup_truth']);
        // An orphan provider process is a CRITICAL violation.
        $this->assertSame(LoopPostCycleAuditorService::STATUS_CRITICAL, $report['status']);
        $this->assertFalse($report['counts_as_success']);
    }

    public function test_retained_worktree_with_reason_is_only_a_warning(): void
    {
        $input = $this->validSuccessFixture();
        $input['worktree_removed'] = false;
        $input['worktree_retained_reason'] = 'kept for operator inspection of failed packet';

        $report = $this->service()->audit($input);

        $this->assertSame(LoopPostCycleAuditorService::STATUS_VALID_SUCCESS, $report['status']);
        $this->assertContains('worktree_retained_with_reason', $report['warnings']);
        $this->assertSame('passed', $report['audits']['cleanup_truth']);
    }

    public function test_marks_packet_complete_only_after_merge(): void
    {
        // Tests bullet: marks packet complete only after merge.
        $input = $this->validSuccessFixture();
        $input['merge_performed'] = false;
        $input['merge_target'] = 'none';
        // No merge happened, but the packet claims completion => false success.
        $input['packet_marked_complete'] = true;
        // Drop merge-only fields so we isolate the progression violation.
        unset($input['merge_commit'], $input['judge_status'], $input['judge_evidence']);
        $input['lane_after'] = $input['lane_before'];

        $report = $this->service()->audit($input);

        $this->assertContains($report['status'], [
            LoopPostCycleAuditorService::STATUS_INVALID,
            LoopPostCycleAuditorService::STATUS_CRITICAL,
        ]);
        $this->assertFalse($report['counts_as_success']);
        $this->assertContains('packet_marked_complete_without_merge', array_column($report['violations'], 'code'));
        $this->assertSame('violated', $report['audits']['progression_truth']);
    }

    public function test_backlog_exhaustion_is_valid_block_not_failure(): void
    {
        // Tests bullet: backlog exhaustion => valid_block not failure.
        $report = $this->service()->audit([
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'run_id' => 'run-001',
            'cycle_index' => 2,
            'preflight_status' => 'block',
            'preflight_block_status' => 'backlog_exhausted',
            'preflight_allowed' => false,
            'provider_invoked' => false,
            'merge_performed' => false,
            'merge_target' => 'none',
            'cycle_outcome' => 'backlog_exhausted',
            'backlog_exhausted' => true,
            // Clean host — nothing to clean up after an honest no-op cycle.
            'worktree_removed' => true,
            'sandbox_branches_remaining' => 0,
            'lock_state' => 'released',
            'provider_processes_remaining' => 0,
        ]);

        $this->assertSame(LoopPostCycleAuditorService::STATUS_VALID_BLOCK, $report['status']);
        $this->assertFalse($report['counts_as_success']);
        $this->assertSame([], $report['violations']);
        $this->assertSame('stop_backlog_exhausted', $report['next_action']);
        $this->assertNotSame(LoopPostCycleAuditorService::STATUS_INVALID, $report['status']);
        $this->assertNotSame(LoopPostCycleAuditorService::STATUS_CRITICAL, $report['status']);
    }

    public function test_backlog_exhaustion_dressed_as_recovery_is_refused(): void
    {
        // NEGATIVE INVARIANT: backlog exhaustion must never be masked as recovery.
        $report = $this->service()->audit([
            'run_id' => 'run-001',
            'cycle_index' => 3,
            'preflight_allowed' => false,
            'provider_invoked' => false,
            'merge_performed' => false,
            'merge_target' => 'none',
            'cycle_outcome' => 'backlog_exhausted',
            'backlog_exhausted' => true,
            'synthetic_recovery_used' => true,
            'candidate' => ['origin_type' => 'starvation_recovery', 'finding_id' => 'recovery-x'],
            'worktree_removed' => true,
            'lock_state' => 'released',
        ]);

        $this->assertSame(LoopPostCycleAuditorService::STATUS_INVALID, $report['status']);
        $this->assertContains('backlog_exhaustion_dressed_as_recovery', array_column($report['violations'], 'code'));
        $this->assertFalse($report['counts_as_success']);
    }

    public function test_blocked_packet_requires_blocker_and_retry_policy(): void
    {
        $input = $this->validSuccessFixture();
        $input['merge_performed'] = false;
        $input['merge_target'] = 'none';
        $input['cycle_outcome'] = 'blocked';
        $input['packet_marked_complete'] = false;
        unset($input['merge_commit'], $input['judge_status'], $input['judge_evidence']);
        // Lane head did not advance (no merge); the next packet rebases on it.
        $input['lane_after'] = $input['lane_before'];
        $input['next_packet_base'] = $input['lane_before'];
        // No blocker_reason, no retry policy => two progression violations.

        $report = $this->service()->audit($input);

        $codes = array_column($report['violations'], 'code');
        $this->assertContains('blocked_packet_without_blocker_reason', $codes);
        $this->assertContains('blocked_packet_without_retry_policy', $codes);
        $this->assertSame(LoopPostCycleAuditorService::STATUS_INVALID, $report['status']);

        // With a blocker + retry policy recorded, progression is honest again.
        $input['blocker_reason'] = 'validation_failed';
        $input['retry_policy'] = ['after_cycles' => 5];
        $ok = $this->service()->audit($input);
        $this->assertSame(LoopPostCycleAuditorService::STATUS_VALID_BLOCK, $ok['status']);
        $this->assertSame('passed', $ok['audits']['progression_truth']);
    }

    public function test_rejects_merge_without_real_hash(): void
    {
        $input = $this->validSuccessFixture();
        unset($input['merge_commit']);

        $report = $this->service()->audit($input);

        $this->assertSame(LoopPostCycleAuditorService::STATUS_INVALID, $report['status']);
        $this->assertContains('merge_performed_without_real_hash', array_column($report['violations'], 'code'));
    }

    public function test_rejects_advance_without_merge(): void
    {
        // No merge claimed, yet main moved => a CRITICAL phantom advance.
        $input = [
            'run_id' => 'run-001',
            'cycle_index' => 4,
            'preflight_allowed' => false,
            'provider_invoked' => false,
            'merge_performed' => false,
            'merge_target' => 'none',
            'main_before' => 'main-aaa',
            'main_after' => 'main-bbb',
            'lane_before' => 'lane-aaa',
            'lane_after' => 'lane-aaa',
            'worktree_removed' => true,
            'lock_state' => 'released',
        ];

        $report = $this->service()->audit($input);

        $this->assertSame(LoopPostCycleAuditorService::STATUS_CRITICAL, $report['status']);
        $this->assertContains('main_advanced_without_merge', array_column($report['violations'], 'code'));
    }

    public function test_requires_ap791_receipt_linked_and_referencing_candidate_for_a_merge(): void
    {
        // Audit D: a merged cycle without a linked, candidate-referencing receipt.
        $input = $this->validSuccessFixture();
        unset($input['receipt_id'], $input['receipt']);
        $input['operator_visible_evidence'] = false;

        $report = $this->service()->audit($input);

        $this->assertSame(LoopPostCycleAuditorService::STATUS_INVALID, $report['status']);
        $codes = array_column($report['violations'], 'code');
        $this->assertContains('ap791_receipt_missing', $codes);
        $this->assertSame('violated', $report['audits']['evidence_truth']);
    }

    public function test_kill_switch_must_be_respected(): void
    {
        $input = $this->validSuccessFixture();
        $input['kill_switch_active'] = true; // fired, yet a provider still ran.

        $report = $this->service()->audit($input);

        $this->assertSame(LoopPostCycleAuditorService::STATUS_CRITICAL, $report['status']);
        $this->assertContains('kill_switch_not_respected', array_column($report['violations'], 'code'));
    }

    public function test_blocked_cycle_is_never_dressed_as_success(): void
    {
        // NEGATIVE INVARIANT: a blocked outcome that also claims success is refused.
        $input = [
            'run_id' => 'run-001',
            'cycle_index' => 5,
            'preflight_allowed' => true,
            'provider_invoked' => true,
            'cycle_outcome' => 'blocked',
            'claims_success' => true,
            'merge_performed' => false,
            'merge_target' => 'none',
            'blocker_reason' => 'validation_failed',
            'retry_recorded' => true,
            'worktree_removed' => true,
            'lock_state' => 'released',
            'candidate' => ['finding_id' => 'AAEOS-009', 'source' => 'self_construction_packet'],
        ];

        $report = $this->service()->audit($input);

        $this->assertContains($report['status'], [
            LoopPostCycleAuditorService::STATUS_INVALID,
            LoopPostCycleAuditorService::STATUS_CRITICAL,
        ]);
        $this->assertFalse($report['counts_as_success']);
        $this->assertContains('blocked_cycle_claimed_as_success', array_column($report['violations'], 'code'));
    }

    public function test_accepts_cycle_record_via_fixture_input_seam(): void
    {
        // The wiring phase passes a whole cycle record under `fixture`; direct keys
        // still win. Here the fixture carries the valid_success payload.
        $report = $this->service()->audit(['fixture' => $this->validSuccessFixture()]);

        $this->assertSame(LoopPostCycleAuditorService::STATUS_VALID_SUCCESS, $report['status']);
        $this->assertTrue($report['counts_as_success']);
    }

    public function test_emits_a_stable_report_hash(): void
    {
        // Tests bullet: stable hash.
        $input = $this->validSuccessFixture();

        $first = $this->service()->audit($input);
        $second = $this->service()->audit($input);

        $this->assertArrayHasKey('report_hash', $first);
        $this->assertStringStartsWith('sha256:', $first['report_hash']);
        $this->assertSame(
            $first['report_hash'],
            $second['report_hash'],
            'same input must produce an identical report_hash (volatile fields stripped)',
        );

        // A critical-violation input must hash stably too AND differ from success.
        $critical = $input;
        $critical['preflight_allowed'] = false;
        $critical['preflight_status'] = 'block';
        $c1 = $this->service()->audit($critical);
        $c2 = $this->service()->audit($critical);
        $this->assertSame($c1['report_hash'], $c2['report_hash']);
        $this->assertNotSame($first['report_hash'], $c1['report_hash']);
    }

    public function test_default_empty_input_does_not_crash_and_blocks_honestly(): void
    {
        // Diagnostic default: an empty/clean cycle (no provider, no merge) audits as
        // an honest valid_block, never a crash and never a false success.
        $report = $this->service()->audit();

        $this->assertSame(LoopPostCycleAuditorService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(LoopPostCycleAuditorService::STATUS_VALID_BLOCK, $report['status']);
        $this->assertFalse($report['counts_as_success']);
        $this->assertFalse($report['counts_as_real_cycle']);
        $this->assertSame([], $report['violations']);
        $this->assertSame('LHL-02', $report['slice_id']);
        $this->assertSame('AP-807', $report['ap_contract']);
    }

    /** Step-1 contract seam: blocked cycles that audit identically still classify differently for spend. */
    public function test_provider_spent_without_merge_contract_distinguishes_blocked_cycles_the_auditor_treats_identically(): void
    {
        $noSpendInput = [
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'run_id' => 'run-001',
            'cycle_index' => 2,
            'preflight_status' => 'block',
            'preflight_block_status' => 'backlog_exhausted',
            'preflight_allowed' => false,
            'provider_invoked' => false,
            'merge_performed' => false,
            'merge_target' => 'none',
            'cycle_outcome' => 'backlog_exhausted',
            'backlog_exhausted' => true,
            'worktree_removed' => true,
            'sandbox_branches_remaining' => 0,
            'lock_state' => 'released',
            'provider_processes_remaining' => 0,
        ];

        $providerWastedInput = $this->validSuccessFixture();
        $providerWastedInput['merge_performed'] = false;
        $providerWastedInput['merge_target'] = 'none';
        $providerWastedInput['cycle_outcome'] = 'blocked';
        $providerWastedInput['packet_marked_complete'] = false;
        unset($providerWastedInput['merge_commit'], $providerWastedInput['judge_status'], $providerWastedInput['judge_evidence']);
        $providerWastedInput['lane_after'] = $providerWastedInput['lane_before'];
        $providerWastedInput['next_packet_base'] = $providerWastedInput['lane_before'];
        $providerWastedInput['blocker_reason'] = 'validation_failed';
        $providerWastedInput['retry_policy'] = ['after_cycles' => 5];

        $noSpendReport = $this->service()->audit($noSpendInput);
        $providerWastedReport = $this->service()->audit($providerWastedInput);

        $this->assertSame(LoopPostCycleAuditorService::STATUS_VALID_BLOCK, $noSpendReport['status']);
        $this->assertSame(LoopPostCycleAuditorService::STATUS_VALID_BLOCK, $providerWastedReport['status']);

        $noSpendShape = ProviderSpentWithoutMergeContract::fromArray($noSpendInput)->toArray();
        $providerWastedShape = ProviderSpentWithoutMergeContract::fromArray($providerWastedInput)->toArray();

        $this->assertSame(
            ProviderSpentWithoutMergeContract::CLASSIFICATION_NO_SPEND_BLOCK,
            $noSpendShape['outputs']['blocked_cycle_spend_classification'],
        );
        $this->assertSame(
            ProviderSpentWithoutMergeContract::CLASSIFICATION_PROVIDER_WASTED,
            $providerWastedShape['outputs']['blocked_cycle_spend_classification'],
        );
        $this->assertNotSame(
            $noSpendShape['outputs']['blocked_cycle_spend_classification'],
            $providerWastedShape['outputs']['blocked_cycle_spend_classification'],
        );

        $this->assertSame(
            ProviderSpentWithoutMergeContract::CLASSIFICATION_NO_SPEND_BLOCK,
            $noSpendReport['provider_spent_without_merge']['outputs']['blocked_cycle_spend_classification'],
        );
        $this->assertSame(
            ProviderSpentWithoutMergeContract::CLASSIFICATION_PROVIDER_WASTED,
            $providerWastedReport['provider_spent_without_merge']['outputs']['blocked_cycle_spend_classification'],
        );
        $this->assertFalse($noSpendReport['provider_spent_without_merge']['outputs']['surfaces_wasted_provider_spend']);
        $this->assertTrue($providerWastedReport['provider_spent_without_merge']['outputs']['surfaces_wasted_provider_spend']);
    }

    public function test_audit_surfaces_provider_spent_without_merge_for_provider_invoked_without_merge(): void
    {
        $input = [
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'run_id' => 'run-waste-001',
            'cycle_index' => 7,
            'preflight_allowed' => true,
            'provider_invoked' => true,
            'merge_performed' => false,
            'merge_target' => 'none',
            'cycle_outcome' => 'blocked',
            'blocker_reason' => 'validation_failed',
            'retry_policy' => ['after_cycles' => 5],
            'worktree_removed' => true,
            'lock_state' => 'released',
        ];

        $report = $this->service()->audit($input);

        $this->assertSame(LoopPostCycleAuditorService::STATUS_VALID_BLOCK, $report['status']);
        $this->assertSame(
            ProviderSpentWithoutMergeContract::CLASSIFICATION_PROVIDER_WASTED,
            $report['provider_spent_without_merge']['outputs']['blocked_cycle_spend_classification'],
        );
        $this->assertTrue($report['provider_spent_without_merge']['outputs']['counts_as_provider_waste']);
        $this->assertTrue($report['provider_spent_without_merge']['outputs']['surfaces_wasted_provider_spend']);
        $this->assertSame('run-waste-001', $report['provider_spent_without_merge']['inputs']['run_id']);
    }

    public function test_provider_spent_without_merge_empty_input_returns_step_one_default_contract(): void
    {
        $shape = $this->service()->providerSpentWithoutMerge([]);

        $this->assertSame(
            ProviderSpentWithoutMergeContract::defaults()->toArray(),
            $shape,
        );
        $this->assertSame(ProviderSpentWithoutMergeContract::SIGNAL_ID, $shape['signal_id']);
        $this->assertSame(
            ProviderSpentWithoutMergeContract::CLASSIFICATION_NO_SPEND_BLOCK,
            $shape['outputs']['blocked_cycle_spend_classification'],
        );
    }

    public function test_provider_spent_without_merge_first_rule_classifies_provider_invoked_without_merge(): void
    {
        $shape = $this->service()->providerSpentWithoutMerge([
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'run_id' => 'run-waste-002',
            'cycle_index' => 3,
            'provider_invoked' => true,
            'merge_performed' => false,
        ]);

        $this->assertSame(
            ProviderSpentWithoutMergeContract::CLASSIFICATION_PROVIDER_WASTED,
            $shape['outputs']['blocked_cycle_spend_classification'],
        );
        $this->assertTrue($shape['outputs']['counts_as_provider_waste']);
        $this->assertTrue($shape['outputs']['surfaces_wasted_provider_spend']);
        $this->assertSame('run-waste-002', $shape['inputs']['run_id']);
    }
}
