<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\TaskQueue\TaskStatusTransitionPolicy;
use Tests\TestCase;

class TaskStatusTransitionPolicyTest extends TestCase
{
    public function test_status_transition_same_state_allowed(): void
    {
        self::assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('claimable', 'claimable'));
        self::assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('claimed', 'claimed'));
    }

    public function test_status_transition_queued_to_claimable(): void
    {
        self::assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('queued', 'claimable'));
    }

    public function test_status_transition_claimable_to_claimed(): void
    {
        self::assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('claimable', 'claimed'));
    }

    public function test_status_transition_claimed_to_completed_dry_run(): void
    {
        self::assertTrue(TaskStatusTransitionPolicy::statusTransitionAllowed('claimed', 'completed_dry_run'));
    }

    public function test_status_transition_terminal_cannot_advance(): void
    {
        self::assertFalse(TaskStatusTransitionPolicy::statusTransitionAllowed('completed_dry_run', 'claimable'));
        self::assertFalse(TaskStatusTransitionPolicy::statusTransitionAllowed('cancelled', 'claimable'));
    }

    public function test_status_transition_unknown_previous_blocked(): void
    {
        self::assertFalse(TaskStatusTransitionPolicy::statusTransitionAllowed('unknown_state', 'claimable'));
    }

    public function test_status_transition_unknown_next_blocked(): void
    {
        self::assertFalse(TaskStatusTransitionPolicy::statusTransitionAllowed('claimable', 'unknown_state'));
    }

    public function test_validate_transition_metadata_non_claim(): void
    {
        self::assertSame([], TaskStatusTransitionPolicy::validateTransitionMetadata('queued', []));
        self::assertSame([], TaskStatusTransitionPolicy::validateTransitionMetadata('released', []));
    }

    public function test_validate_transition_metadata_claim_valid(): void
    {
        $metadata = ['lease_id' => 'l1', 'agent_id' => 'a1'];

        self::assertSame([], TaskStatusTransitionPolicy::validateTransitionMetadata('claimed', $metadata));
    }

    public function test_validate_transition_metadata_claim_missing_fields(): void
    {
        $result = TaskStatusTransitionPolicy::validateTransitionMetadata('claimed', []);

        self::assertSame(['missing_metadata' => ['lease_id', 'agent_id']], array_intersect_key($result, ['missing_metadata' => []]));
    }

    public function test_validate_transition_metadata_claim_partial(): void
    {
        $result = TaskStatusTransitionPolicy::validateTransitionMetadata('claimed', ['lease_id' => 'l1']);

        self::assertContains('agent_id', $result['missing_metadata']);
        self::assertNotContains('lease_id', $result['missing_metadata']);
    }

    public function test_transition_policy_hash_deterministic(): void
    {
        $h1 = TaskStatusTransitionPolicy::transitionPolicyHash();
        $h2 = TaskStatusTransitionPolicy::transitionPolicyHash();

        self::assertSame($h1, $h2);
        self::assertSame(64, strlen($h1));
    }
}