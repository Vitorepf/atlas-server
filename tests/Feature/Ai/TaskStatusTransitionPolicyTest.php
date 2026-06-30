<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\TaskQueue\TaskStatusTransitionPolicy;
use PHPUnit\Framework\TestCase;

final class TaskStatusTransitionPolicyTest extends TestCase
{
    // ── AC2: allowed transitions + terminal statuses ──────────────────────────

    public function test_allowed_transition_returns_true(): void
    {
        $this->assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('queued', 'claimable'));
        $this->assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('claimable', 'claimed'));
        $this->assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('claimed', 'completed_dry_run'));
    }

    public function test_same_status_transition_is_always_allowed(): void
    {
        $this->assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('queued', 'queued'));
        $this->assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('cancelled', 'cancelled'));
    }

    public function test_terminal_statuses_cannot_transition(): void
    {
        $terminals = TaskStatusTransitionPolicy::terminalStatuses();
        $this->assertNotEmpty($terminals);

        foreach ($terminals as $terminal) {
            $this->assertFalse(
                TaskStatusTransitionPolicy::statusTransitionAllowed($terminal, 'queued'),
                "$terminal should not allow transition to queued",
            );
        }
    }

    public function test_completed_dry_run_is_terminal(): void
    {
        $this->assertContains('completed_dry_run', TaskStatusTransitionPolicy::terminalStatuses());
    }

    public function test_cancelled_is_terminal(): void
    {
        $this->assertContains('cancelled', TaskStatusTransitionPolicy::terminalStatuses());
    }

    // ── AC3: explainTransition reasons ────────────────────────────────────────

    public function test_unknown_previous_status_explains_reason(): void
    {
        $r = TaskStatusTransitionPolicy::explainTransition('nonexistent', 'queued');

        $this->assertFalse($r['allowed']);
        $this->assertSame('unknown_previous_status', $r['reason']);
    }

    public function test_unknown_next_status_explains_reason(): void
    {
        $r = TaskStatusTransitionPolicy::explainTransition('queued', 'nonexistent');

        $this->assertFalse($r['allowed']);
        $this->assertSame('unknown_next_status', $r['reason']);
    }

    public function test_disallowed_transition_explains_reason(): void
    {
        // queued → completed_dry_run is not allowed
        $r = TaskStatusTransitionPolicy::explainTransition('queued', 'completed_dry_run');

        $this->assertFalse($r['allowed']);
        $this->assertSame('transition_not_allowed', $r['reason']);
    }

    public function test_terminal_transition_explains_reason(): void
    {
        $r = TaskStatusTransitionPolicy::explainTransition('completed_dry_run', 'queued');

        $this->assertFalse($r['allowed']);
        $this->assertSame('terminal_status_cannot_transition', $r['reason']);
    }

    public function test_allowed_transition_explains_allowed_true(): void
    {
        $r = TaskStatusTransitionPolicy::explainTransition('queued', 'claimable');

        $this->assertTrue($r['allowed']);
    }

    // ── AC4: claimed requires lease_id + agent_id; sets deterministic ─────────

    public function test_claimed_transition_without_metadata_returns_missing_fields(): void
    {
        $errors = TaskStatusTransitionPolicy::validateTransitionMetadata('claimed', []);

        $this->assertArrayHasKey('missing_metadata', $errors);
        $this->assertContains('lease_id', $errors['missing_metadata']);
        $this->assertContains('agent_id', $errors['missing_metadata']);
    }

    public function test_claimed_transition_with_valid_metadata_returns_no_errors(): void
    {
        $errors = TaskStatusTransitionPolicy::validateTransitionMetadata('claimed', [
            'lease_id' => 'lease-abc',
            'agent_id' => 'agent-1',
        ]);

        $this->assertEmpty($errors);
    }

    public function test_non_claimed_transition_requires_no_metadata(): void
    {
        $errors = TaskStatusTransitionPolicy::validateTransitionMetadata('claimable', []);

        $this->assertEmpty($errors);
    }

    public function test_terminal_statuses_is_deterministic(): void
    {
        $a = TaskStatusTransitionPolicy::terminalStatuses();
        $b = TaskStatusTransitionPolicy::terminalStatuses();
        $this->assertSame($a, $b);
    }

    public function test_recoverable_statuses_include_lease_expired_and_released(): void
    {
        $rec = TaskStatusTransitionPolicy::recoverableStatuses();
        $this->assertContains('lease_expired', $rec);
        $this->assertContains('released', $rec);
    }

    public function test_active_lease_statuses_include_claimed(): void
    {
        $this->assertContains('claimed', TaskStatusTransitionPolicy::activeLeaseStatuses());
    }

    public function test_transition_policy_hash_is_deterministic(): void
    {
        $this->assertSame(
            TaskStatusTransitionPolicy::transitionPolicyHash(),
            TaskStatusTransitionPolicy::transitionPolicyHash(),
        );
    }
}
