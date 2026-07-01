<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalClientRecoveryPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainLocalClientRecoveryPlannerTest extends TestCase
{
    private AtlasExternalBrainLocalClientRecoveryPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new AtlasExternalBrainLocalClientRecoveryPlanner;
    }

    private function input(string $failureType, array $overrides = []): array
    {
        return array_merge([
            'failure_type' => $failureType,
            'task_packet_id' => 'codex-meta-fixture-task',
            'lease_id' => 'lease_fixture',
            'client_id' => 'claude-muscle-recovery-4',
            'allowed_files' => ['app/Foo.php'],
            'evidence_status' => 'partial',
        ], $overrides);
    }

    // ── existing failure-type mappings (preserved) ────────────────────────────

    public function test_stall_decides_retry_local(): void
    {
        $result = $this->planner->plan($this->input('stall'));

        $this->assertSame(AtlasExternalBrainLocalClientRecoveryPlanner::SCHEMA, $result['schema']);
        $this->assertSame('retry_local', $result['decision']);
    }

    public function test_malformed_output_decides_retry_local(): void
    {
        $result = $this->planner->plan($this->input('malformed_output'));

        $this->assertSame('retry_local', $result['decision']);
    }

    public function test_lost_login_decides_fallback_atlas_native(): void
    {
        $result = $this->planner->plan($this->input('lost_login'));

        $this->assertSame('fallback_atlas_native', $result['decision']);
    }

    public function test_quota_exhausted_decides_fallback_atlas_native(): void
    {
        $result = $this->planner->plan($this->input('quota_exhausted'));

        $this->assertSame('fallback_atlas_native', $result['decision']);
    }

    public function test_ui_brittle_decides_fallback_manual_muscle(): void
    {
        $result = $this->planner->plan($this->input('ui_brittle'));

        $this->assertSame('fallback_manual_muscle', $result['decision']);
    }

    public function test_scope_violation_decides_give_back_task(): void
    {
        $result = $this->planner->plan($this->input('scope_violation'));

        $this->assertSame('give_back_task', $result['decision']);
    }

    public function test_missing_evidence_decides_give_back_task(): void
    {
        $result = $this->planner->plan($this->input('missing_evidence'));

        $this->assertSame('give_back_task', $result['decision']);
    }

    public function test_unknown_failure_type_defaults_to_give_back_task(): void
    {
        $result = $this->planner->plan($this->input('something_unexpected'));

        $this->assertSame('give_back_task', $result['decision']);
    }

    // ── AC2: timeout, context loss, crash and stale lease incidents ──────────

    public function test_timeout_decides_retry_local(): void
    {
        $result = $this->planner->plan($this->input('timeout'));

        $this->assertSame('retry_local', $result['decision']);
    }

    public function test_context_loss_decides_fallback_atlas_native(): void
    {
        $result = $this->planner->plan($this->input('context_loss'));

        $this->assertSame('fallback_atlas_native', $result['decision']);
    }

    public function test_crash_decides_retry_local(): void
    {
        $result = $this->planner->plan($this->input('crash'));

        $this->assertSame('retry_local', $result['decision']);
    }

    public function test_stale_lease_decides_give_back_task(): void
    {
        $result = $this->planner->plan($this->input('stale_lease'));

        $this->assertSame('give_back_task', $result['decision']);
    }

    // ── AC3: preserves task id, lease/report command, last verified evidence,
    //    give_back/success decision boundaries ────────────────────────────────

    public function test_plan_preserves_task_identity_and_scope_without_mutation(): void
    {
        $result = $this->planner->plan($this->input('stall'));

        $this->assertSame('codex-meta-fixture-task', $result['task_packet_id']);
        $this->assertSame('lease_fixture', $result['lease_id']);
        $this->assertSame(['app/Foo.php'], $result['allowed_files']);
        $this->assertSame('partial', $result['evidence_status']);
        $this->assertNotEmpty($result['next_safe_action']);
    }

    public function test_last_verified_evidence_mirrors_evidence_status(): void
    {
        $result = $this->planner->plan($this->input('stall', ['evidence_status' => 'tests_green']));

        $this->assertSame('tests_green', $result['last_verified_evidence']);
    }

    public function test_report_command_includes_client_task_and_lease(): void
    {
        $result = $this->planner->plan($this->input('stall'));

        $this->assertStringContainsString('--client=claude-muscle-recovery-4', $result['report_command']);
        $this->assertStringContainsString('--task=codex-meta-fixture-task', $result['report_command']);
        $this->assertStringContainsString('--lease=lease_fixture', $result['report_command']);
    }

    public function test_report_command_uses_give_back_outcome_and_no_commit_for_give_back_decision(): void
    {
        $result = $this->planner->plan($this->input('missing_evidence'));

        $this->assertStringContainsString('--outcome=give_back', $result['report_command']);
        $this->assertStringNotContainsString('--commit', $result['report_command']);
    }

    public function test_report_command_uses_success_outcome_and_commit_for_retry_decision(): void
    {
        $result = $this->planner->plan($this->input('stall'));

        $this->assertStringContainsString('--outcome=success', $result['report_command']);
        $this->assertStringContainsString('--commit', $result['report_command']);
    }

    public function test_decision_boundary_present_and_distinct_for_give_back_vs_retry(): void
    {
        $giveBack = $this->planner->plan($this->input('missing_evidence'));
        $retry = $this->planner->plan($this->input('stall'));

        $this->assertNotEmpty($giveBack['decision_boundary']);
        $this->assertNotEmpty($retry['decision_boundary']);
        $this->assertStringContainsString('give_back_only', $giveBack['decision_boundary']);
        $this->assertStringContainsString('success_if', $retry['decision_boundary']);
    }

    // ── AC4: refuses blind retry on duplicate commit / stale scope / missing proof ──

    public function test_possible_duplicate_commit_refuses_blind_retry(): void
    {
        $result = $this->planner->plan($this->input('stall', ['possible_duplicate_commit' => true]));

        $this->assertSame('verify_before_retry', $result['decision']);
        $this->assertTrue($result['blind_retry_refused']);
        $this->assertContains('possible_duplicate_commit', $result['blind_retry_reasons']);
    }

    public function test_stale_allowed_files_refuses_blind_retry(): void
    {
        $result = $this->planner->plan($this->input('timeout', ['stale_allowed_files' => true]));

        $this->assertSame('verify_before_retry', $result['decision']);
        $this->assertContains('stale_allowed_files', $result['blind_retry_reasons']);
    }

    public function test_missing_proof_risk_refuses_blind_retry(): void
    {
        $result = $this->planner->plan($this->input('crash', ['missing_proof_risk' => true]));

        $this->assertSame('verify_before_retry', $result['decision']);
        $this->assertContains('missing_proof_risk', $result['blind_retry_reasons']);
    }

    public function test_blind_retry_refusal_does_not_apply_to_non_retry_decisions(): void
    {
        $result = $this->planner->plan($this->input('lost_login', ['possible_duplicate_commit' => true]));

        $this->assertSame('fallback_atlas_native', $result['decision']);
        $this->assertFalse($result['blind_retry_refused']);
        $this->assertSame([], $result['blind_retry_reasons']);
    }

    public function test_clean_retry_local_is_not_refused(): void
    {
        $result = $this->planner->plan($this->input('stall'));

        $this->assertSame('retry_local', $result['decision']);
        $this->assertFalse($result['blind_retry_refused']);
    }

    public function test_verify_before_retry_has_no_loss_recovery_plan_when_ids_present(): void
    {
        $result = $this->planner->plan($this->input('stall', ['possible_duplicate_commit' => true]));

        $this->assertTrue($result['no_loss_recovery_plan']);
    }

    // ── no_loss_recovery_plan preserved ────────────────────────────────────────

    public function test_no_loss_recovery_plan_true_when_ids_preserved(): void
    {
        $result = $this->planner->plan($this->input('stall'));

        $this->assertTrue($result['no_loss_recovery_plan']);
    }

    public function test_no_loss_recovery_plan_false_when_lease_id_missing(): void
    {
        $result = $this->planner->plan($this->input('stall', ['lease_id' => '']));

        $this->assertFalse($result['no_loss_recovery_plan']);
    }

    public function test_no_loss_recovery_plan_false_when_task_packet_id_missing(): void
    {
        $result = $this->planner->plan($this->input('stall', ['task_packet_id' => '']));

        $this->assertFalse($result['no_loss_recovery_plan']);
    }
}
