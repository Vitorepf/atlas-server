<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Quaternity\DialogueToPackets;

use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\DialogueLedgerEvent;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\DialogueLedgerTransitionPolicy;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\DialogueLedgerTransitionViolation;
use PHPUnit\Framework\TestCase;

final class DialogueLedgerTransitionPolicyTest extends TestCase
{
    // ── AC: proposed to approved requires review state ──────────────────────────

    public function test_proposed_to_approved_directly_is_rejected_skipping_review(): void
    {
        $this->expectException(DialogueLedgerTransitionViolation::class);

        DialogueLedgerTransitionPolicy::assertAllowed(
            DialogueLedgerEvent::TYPE_PROPOSED,
            DialogueLedgerEvent::TYPE_APPROVED,
            'shape-1',
        );
    }

    public function test_proposed_to_reviewed_is_allowed(): void
    {
        DialogueLedgerTransitionPolicy::assertAllowed(
            DialogueLedgerEvent::TYPE_PROPOSED,
            DialogueLedgerEvent::TYPE_REVIEWED,
            'shape-1',
        );

        $this->addToAssertionCount(1); // no exception thrown
    }

    public function test_reviewed_to_approved_is_allowed(): void
    {
        DialogueLedgerTransitionPolicy::assertAllowed(
            DialogueLedgerEvent::TYPE_REVIEWED,
            DialogueLedgerEvent::TYPE_APPROVED,
            'shape-1',
        );

        $this->addToAssertionCount(1);
    }

    // ── AC: rejected shapes cannot later be approved without a new proposal ─────

    public function test_rejected_shape_cannot_later_be_approved(): void
    {
        $this->expectException(DialogueLedgerTransitionViolation::class);

        DialogueLedgerTransitionPolicy::assertAllowed(
            DialogueLedgerEvent::TYPE_REJECTED,
            DialogueLedgerEvent::TYPE_APPROVED,
            'shape-1',
        );
    }

    public function test_rejected_is_terminal_with_no_allowed_next_events(): void
    {
        $this->assertSame([], DialogueLedgerTransitionPolicy::nextAllowed(DialogueLedgerEvent::TYPE_REJECTED));
    }

    public function test_rejected_shape_requires_a_new_proposal_from_genesis(): void
    {
        // A new proposal starts fresh from GENESIS — never resumes a rejected stream.
        DialogueLedgerTransitionPolicy::assertAllowed(
            DialogueLedgerTransitionPolicy::STATE_GENESIS,
            DialogueLedgerEvent::TYPE_PROPOSED,
            'shape-2-new-proposal',
        );

        $this->addToAssertionCount(1);
    }

    // ── AC: duplicate approval is rejected ───────────────────────────────────────

    public function test_duplicate_approval_is_rejected(): void
    {
        $this->expectException(DialogueLedgerTransitionViolation::class);

        DialogueLedgerTransitionPolicy::assertAllowed(
            DialogueLedgerEvent::TYPE_APPROVED,
            DialogueLedgerEvent::TYPE_APPROVED,
            'shape-1',
        );
    }

    public function test_approved_only_allows_enqueued_next(): void
    {
        $this->assertSame(
            [DialogueLedgerEvent::TYPE_ENQUEUED],
            DialogueLedgerTransitionPolicy::nextAllowed(DialogueLedgerEvent::TYPE_APPROVED),
        );
    }

    // ── supporting coverage ──────────────────────────────────────────────────────

    public function test_violation_carries_from_state_to_state_and_shape_hash(): void
    {
        try {
            DialogueLedgerTransitionPolicy::assertAllowed(
                DialogueLedgerEvent::TYPE_APPROVED,
                DialogueLedgerEvent::TYPE_APPROVED,
                'shape-xyz',
            );
            $this->fail('expected DialogueLedgerTransitionViolation');
        } catch (DialogueLedgerTransitionViolation $e) {
            $this->assertSame(DialogueLedgerEvent::TYPE_APPROVED, $e->fromState);
            $this->assertSame(DialogueLedgerEvent::TYPE_APPROVED, $e->toState);
            $this->assertSame('shape-xyz', $e->shapeHash);
        }
    }

    public function test_executed_and_failed_are_terminal(): void
    {
        $this->assertSame([], DialogueLedgerTransitionPolicy::nextAllowed(DialogueLedgerEvent::TYPE_EXECUTED));
        $this->assertSame([], DialogueLedgerTransitionPolicy::nextAllowed(DialogueLedgerEvent::TYPE_FAILED));
    }
}
