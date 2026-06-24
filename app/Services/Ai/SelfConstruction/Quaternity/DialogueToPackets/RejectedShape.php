<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets;

/**
 * The bi-directional rejection receipt — same key set as {@see ApprovedShape} with `reason` added. Persisted
 * at storage/atlas/maestro/dialogue/rejected/<shape_id>.json. The shape NEVER transits to the queue once
 * rejected; a downstream attempt to re-promote requires a fresh proposal cycle.
 */
final class RejectedShape
{
    public const REQUIRED_KEYS = [
        'approval_hash',
        'cortex_snapshot_hash',
        'decided_at_utc',
        'decision_receipt',
        'operator_signature',
        'proposal_hash',
        'reason',
    ];

    public function __construct(
        public readonly string $shapeId,
        public readonly string $proposalHash,
        public readonly string $approvalHash,
        public readonly string $operatorSignature,
        public readonly string $cortexSnapshotHash,
        public readonly string $decidedAtUtc,
        public readonly string $decisionReceipt,
        public readonly string $reason,
    ) {
    }

    /**
     * @return array<string,string>
     */
    public function toArray(): array
    {
        return [
            'approval_hash' => $this->approvalHash,
            'cortex_snapshot_hash' => $this->cortexSnapshotHash,
            'decided_at_utc' => $this->decidedAtUtc,
            'decision_receipt' => $this->decisionReceipt,
            'operator_signature' => $this->operatorSignature,
            'proposal_hash' => $this->proposalHash,
            'reason' => $this->reason,
        ];
    }
}
