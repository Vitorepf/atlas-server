<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets;

/**
 * One row of the dialogue-to-packet append-only ledger — every state transition in the pipeline materialises
 * here. Includes the cryptographic chain links (prev_row_hash + this_row_hash) so {@see verifyChain()} can
 * detect any historical mutation.
 *
 * Event types are the state-machine vocabulary: IntentCaptured → Proposed → Reviewed → Approved|Rejected →
 * Enqueued → Executed|Failed. The transition policy ({@see DialogueLedgerTransitionPolicy}) enforces order.
 */
final class DialogueLedgerEvent
{
    public const TYPE_INTENT_CAPTURED = 'IntentCaptured';

    public const TYPE_PROPOSED = 'Proposed';

    public const TYPE_REVIEWED = 'Reviewed';

    public const TYPE_APPROVED = 'Approved';

    public const TYPE_REJECTED = 'Rejected';

    public const TYPE_ENQUEUED = 'Enqueued';

    public const TYPE_EXECUTED = 'Executed';

    public const TYPE_FAILED = 'Failed';

    public const ALL_TYPES = [
        self::TYPE_INTENT_CAPTURED,
        self::TYPE_PROPOSED,
        self::TYPE_REVIEWED,
        self::TYPE_APPROVED,
        self::TYPE_REJECTED,
        self::TYPE_ENQUEUED,
        self::TYPE_EXECUTED,
        self::TYPE_FAILED,
    ];

    /**
     * @param  array<string,mixed>  $payload
     */
    public function __construct(
        public readonly string $ulid,
        public readonly ?string $parentUlid,
        public readonly string $eventType,
        public readonly string $shapeHash,
        public readonly string $cortexSnapshotHash,
        public readonly ?string $operatorSignature,
        public readonly array $payload,
        public readonly string $createdAtUtc,
        public readonly string $prevRowHash,
        public readonly string $thisRowHash,
    ) {
    }

    /**
     * Canonical row bytes (excluding this_row_hash) — what the chain hasher hashes.
     *
     * @return array<string,mixed>
     */
    public function canonicalBody(): array
    {
        return [
            'cortex_snapshot_hash' => $this->cortexSnapshotHash,
            'created_at_utc' => $this->createdAtUtc,
            'event_type' => $this->eventType,
            'operator_signature' => $this->operatorSignature,
            'parent_ulid' => $this->parentUlid,
            'payload' => $this->payload,
            'prev_row_hash' => $this->prevRowHash,
            'shape_hash' => $this->shapeHash,
            'ulid' => $this->ulid,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return $this->canonicalBody() + ['this_row_hash' => $this->thisRowHash];
    }
}
