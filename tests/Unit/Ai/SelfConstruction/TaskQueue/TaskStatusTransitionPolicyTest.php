<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQueue;

use App\Services\Ai\SelfConstruction\TaskQueue\TaskStatusTransitionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Covers TaskStatusTransitionPolicy — the pure state machine that governs
 * task packet status transitions in the Agent Control Plane queue.
 */
final class TaskStatusTransitionPolicyTest extends TestCase
{
    // ── AC: valid transitions allowed ───────────────────────────────────────

    public function test_queued_to_claimable_allowed(): void
    {
        $this->assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('queued', 'claimable'));
    }

    public function test_queued_to_blocked_allowed(): void
    {
        $this->assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('queued', 'blocked'));
    }

    public function test_queued_to_cancelled_allowed(): void
    {
        $this->assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('queued', 'cancelled'));
    }

    public function test_claimable_to_claimed_allowed(): void
    {
        $this->assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('claimable', 'claimed'));
    }

    public function test_claimable_to_blocked_allowed(): void
    {
        $this->assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('claimable', 'blocked'));
    }

    public function test_claimed_to_lease_expired_allowed(): void
    {
        $this->assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('claimed', 'lease_expired'));
    }

    public function test_claimed_to_released_allowed(): void
    {
        $this->assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('claimed', 'released'));
    }

    public function test_claimed_to_completed_dry_run_allowed(): void
    {
        $this->assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('claimed', 'completed_dry_run'));
    }

    public function test_claimed_to_resolved_allowed(): void
    {
        $this->assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('claimed', 'resolved'));
    }

    public function test_lease_expired_to_claimable_allowed(): void
    {
        $this->assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('lease_expired', 'claimable'));
    }

    public function test_released_to_claimable_allowed(): void
    {
        $this->assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('released', 'claimable'));
    }

    public function test_blocked_to_claimable_allowed(): void
    {
        $this->assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('blocked', 'claimable'));
    }

    public function test_completed_dry_run_to_resolved_allowed(): void
    {
        $this->assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('completed_dry_run', 'resolved'));
    }

    public function test_same_status_transition_allowed(): void
    {
        $this->assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('claimed', 'claimed'));
        $this->assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('queued', 'queued'));
    }

    // ── AC: dangerous transitions rejected ──────────────────────────────────

    public function test_queued_to_claimed_rejected_skip_claim(): void
    {
        $this->assertFalse(TaskStatusTransitionPolicy::statusTransitionAllowed('queued', 'claimed'));
    }

    public function test_blocked_to_claimed_rejected(): void
    {
        $this->assertFalse(TaskStatusTransitionPolicy::statusTransitionAllowed('blocked', 'claimed'));
    }

    public function test_queued_to_resolved_rejected_skip_proof(): void
    {
        $this->assertFalse(TaskStatusTransitionPolicy::statusTransitionAllowed('queued', 'resolved'));
    }

    public function test_claimable_to_resolved_rejected_skip_claim(): void
    {
        $this->assertFalse(TaskStatusTransitionPolicy::statusTransitionAllowed('claimable', 'resolved'));
    }

    public function test_released_to_claimed_rejected(): void
    {
        $this->assertFalse(TaskStatusTransitionPolicy::statusTransitionAllowed('released', 'claimed'));
    }

    // ── AC: terminal resurrection rejected ──────────────────────────────────

    public function test_completed_dry_run_to_claimed_rejected(): void
    {
        $this->assertFalse(TaskStatusTransitionPolicy::statusTransitionAllowed('completed_dry_run', 'claimed'));
    }

    public function test_completed_dry_run_to_claimable_rejected(): void
    {
        $this->assertFalse(TaskStatusTransitionPolicy::statusTransitionAllowed('completed_dry_run', 'claimable'));
    }

    public function test_cancelled_to_claimable_rejected(): void
    {
        $this->assertFalse(TaskStatusTransitionPolicy::statusTransitionAllowed('cancelled', 'claimable'));
    }

    public function test_resolved_to_claimable_rejected(): void
    {
        $this->assertFalse(TaskStatusTransitionPolicy::statusTransitionAllowed('resolved', 'claimable'));
    }

    public function test_resolved_to_cancelled_rejected(): void
    {
        $this->assertFalse(TaskStatusTransitionPolicy::statusTransitionAllowed('resolved', 'cancelled'));
    }

    // ── AC: rejected transitions have actionable reasons ────────────────────

    public function test_explain_queued_to_claimed_reason(): void
    {
        $result = TaskStatusTransitionPolicy::explainTransition('queued', 'claimed');
        $this->assertFalse($result['allowed']);
        $this->assertSame('queued_must_become_claimable_before_claim', $result['reason']);
    }

    public function test_explain_blocked_to_claimed_reason(): void
    {
        $result = TaskStatusTransitionPolicy::explainTransition('blocked', 'claimed');
        $this->assertFalse($result['allowed']);
        $this->assertSame('blocked_must_return_to_claimable_before_claim', $result['reason']);
    }

    public function test_explain_queued_to_resolved_reason(): void
    {
        $result = TaskStatusTransitionPolicy::explainTransition('queued', 'resolved');
        $this->assertFalse($result['allowed']);
        $this->assertSame('resolved_requires_active_lease_or_completion', $result['reason']);
    }

    public function test_explain_terminal_resurrection_blocked_reason(): void
    {
        $result = TaskStatusTransitionPolicy::explainTransition('completed_dry_run', 'claimed');
        $this->assertFalse($result['allowed']);
        $this->assertStringStartsWith('terminal_status_resurrection_blocked', $result['reason']);
    }

    public function test_explain_cancelled_resurrection_blocked_reason(): void
    {
        $result = TaskStatusTransitionPolicy::explainTransition('cancelled', 'claimable');
        $this->assertFalse($result['allowed']);
        $this->assertStringStartsWith('terminal_status_resurrection_blocked', $result['reason']);
    }

    public function test_explain_resolved_resurrection_blocked_reason(): void
    {
        $result = TaskStatusTransitionPolicy::explainTransition('resolved', 'claimable');
        $this->assertFalse($result['allowed']);
        $this->assertStringStartsWith('terminal_status_resurrection_blocked', $result['reason']);
    }

    public function test_explain_unknown_previous_status(): void
    {
        $result = TaskStatusTransitionPolicy::explainTransition('nonexistent', 'queued');
        $this->assertFalse($result['allowed']);
        $this->assertSame('unknown_previous_status', $result['reason']);
    }

    public function test_explain_unknown_next_status(): void
    {
        $result = TaskStatusTransitionPolicy::explainTransition('queued', 'nonexistent');
        $this->assertFalse($result['allowed']);
        $this->assertSame('unknown_next_status', $result['reason']);
    }

    public function test_explain_valid_transition_no_reason_key(): void
    {
        $result = TaskStatusTransitionPolicy::explainTransition('queued', 'claimable');
        $this->assertTrue($result['allowed']);
        $this->assertArrayNotHasKey('reason', $result);
    }

    // ── AC: validateTransitionMetadata ──────────────────────────────────────

    public function test_validate_claimed_requires_lease_and_agent(): void
    {
        $result = TaskStatusTransitionPolicy::validateTransitionMetadata('claimed', []);
        $this->assertContains('lease_id', $result['missing_metadata']);
        $this->assertContains('agent_id', $result['missing_metadata']);
    }

    public function test_validate_claimed_passes_with_required_fields(): void
    {
        $result = TaskStatusTransitionPolicy::validateTransitionMetadata('claimed', [
            'lease_id' => 'l1', 'agent_id' => 'a1',
        ]);
        $this->assertSame([], $result);
    }

    public function test_validate_resolved_requires_matching_lease_and_packet(): void
    {
        $result = TaskStatusTransitionPolicy::validateTransitionMetadata('resolved', []);
        $this->assertContains('lease_id', $result['missing_metadata']);
        $this->assertContains('agent_id', $result['missing_metadata']);
        $this->assertContains('task_packet_id', $result['missing_metadata']);
        $this->assertTrue($result['resolved_requires_matching_lease']);
    }

    public function test_validate_resolved_passes_with_all_fields(): void
    {
        $result = TaskStatusTransitionPolicy::validateTransitionMetadata('resolved', [
            'lease_id' => 'l1', 'agent_id' => 'a1', 'task_packet_id' => 'tp1',
        ]);
        $this->assertSame([], $result);
    }

    public function test_validate_non_claimed_non_resolved_returns_empty(): void
    {
        $this->assertSame([], TaskStatusTransitionPolicy::validateTransitionMetadata('queued', []));
        $this->assertSame([], TaskStatusTransitionPolicy::validateTransitionMetadata('blocked', []));
    }

    // ── AC: terminal/recoverable/active helper queries ──────────────────────

    public function test_terminal_statuses_includes_resolved_and_cancelled(): void
    {
        $terminals = TaskStatusTransitionPolicy::terminalStatuses();
        $this->assertContains('resolved', $terminals);
        $this->assertContains('cancelled', $terminals);
    }

    public function test_terminal_statuses_excludes_active_states(): void
    {
        $terminals = TaskStatusTransitionPolicy::terminalStatuses();
        $this->assertNotContains('queued', $terminals);
        $this->assertNotContains('claimed', $terminals);
        $this->assertNotContains('claimable', $terminals);
    }

    public function test_recoverable_statuses_include_blocked_and_lease_expired(): void
    {
        $recoverable = TaskStatusTransitionPolicy::recoverableStatuses();
        $this->assertContains('blocked', $recoverable);
        $this->assertContains('lease_expired', $recoverable);
        $this->assertContains('released', $recoverable);
    }

    public function test_active_lease_statuses_include_claimed(): void
    {
        $active = TaskStatusTransitionPolicy::activeLeaseStatuses();
        $this->assertContains('claimed', $active);
    }

    // ── determinism ─────────────────────────────────────────────────────────

    public function test_transition_policy_hash_is_deterministic(): void
    {
        $this->assertSame(
            TaskStatusTransitionPolicy::transitionPolicyHash(),
            TaskStatusTransitionPolicy::transitionPolicyHash(),
        );
    }

    public function test_transition_policy_hash_is_sha256_hex(): void
    {
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            TaskStatusTransitionPolicy::transitionPolicyHash(),
        );
    }
}
