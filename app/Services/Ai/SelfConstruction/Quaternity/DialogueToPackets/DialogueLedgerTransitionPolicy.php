<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets;

use RuntimeException;

/**
 * Thrown by {@see DialogueLedgerTransitionPolicy::assertAllowed()} when an event is appended in a state the
 * state machine forbids (e.g. Enqueued without a prior Approved for the same shape_hash). Carries
 * from_state + to_state so the operator can audit. Co-located with the policy.
 */
final class DialogueLedgerTransitionViolation extends RuntimeException
{
    public function __construct(
        public readonly string $fromState,
        public readonly string $toState,
        public readonly string $shapeHash,
    ) {
        parent::__construct(sprintf('Dialogue ledger transition forbidden: from=%s to=%s shape=%s', $fromState, $toState, $shapeHash));
    }
}

/**
 * The dialogue-to-packet state machine: enforces the legal transitions per shape_hash. Multiple shapes coexist
 * in the same ledger; each shape has its own state stream tracked by replaying events filtered to that shape.
 *
 *   GENESIS ─→ IntentCaptured ─→ Proposed ─→ Reviewed ─→ {Approved | Rejected}
 *                                                          │
 *                                                          ▼
 *                                                       Enqueued ─→ {Executed | Failed}
 *
 * Approved/Rejected REQUIRE a non-null operator_signature (QUATERNITY invariant: operator is a real
 * primitive, not optional). Any rejected or failed shape is TERMINAL — no further events for that shape.
 */
final class DialogueLedgerTransitionPolicy
{
    public const STATE_GENESIS = 'GENESIS';

    /**
     * @return list<string>  the event types legally appendable from the given state
     */
    public static function nextAllowed(string $state): array
    {
        return match ($state) {
            self::STATE_GENESIS => [DialogueLedgerEvent::TYPE_INTENT_CAPTURED, DialogueLedgerEvent::TYPE_PROPOSED],
            DialogueLedgerEvent::TYPE_INTENT_CAPTURED => [DialogueLedgerEvent::TYPE_PROPOSED],
            DialogueLedgerEvent::TYPE_PROPOSED => [DialogueLedgerEvent::TYPE_REVIEWED],
            DialogueLedgerEvent::TYPE_REVIEWED => [DialogueLedgerEvent::TYPE_APPROVED, DialogueLedgerEvent::TYPE_REJECTED],
            DialogueLedgerEvent::TYPE_APPROVED => [DialogueLedgerEvent::TYPE_ENQUEUED],
            DialogueLedgerEvent::TYPE_ENQUEUED => [DialogueLedgerEvent::TYPE_EXECUTED, DialogueLedgerEvent::TYPE_FAILED],
            DialogueLedgerEvent::TYPE_REJECTED, DialogueLedgerEvent::TYPE_EXECUTED, DialogueLedgerEvent::TYPE_FAILED => [],
            default => [],
        };
    }

    /**
     * @param  string  $currentState  current state of this shape's stream (or STATE_GENESIS when empty)
     */
    public static function assertAllowed(string $currentState, string $proposedEventType, string $shapeHash): void
    {
        if (! in_array($proposedEventType, self::nextAllowed($currentState), true)) {
            throw new DialogueLedgerTransitionViolation($currentState, $proposedEventType, $shapeHash);
        }
    }
}
