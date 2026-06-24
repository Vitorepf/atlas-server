<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets;

/**
 * The bi-directional approval receipt {@see AtlasMaestroPacketShapeOperatorReviewGate::approve()} writes to
 * storage/atlas/maestro/dialogue/approved/<shape_id>.json. Carries BOTH the proposal_hash (what the operator
 * saw) AND the approval_hash (what the operator decided), so any downstream consumer can prove the operator
 * approved EXACTLY the shape that was presented — no after-the-fact substitution.
 */
final class ApprovedShape
{
    /** The canonical keys every approved-shape JSON file contains, in alphabetic order. */
    public const REQUIRED_KEYS = [
        'approval_hash',
        'cortex_snapshot_hash',
        'decided_at_utc',
        'decision_receipt',
        'operator_signature',
        'proposal_hash',
    ];

    public function __construct(
        public readonly string $shapeId,
        public readonly string $proposalHash,
        public readonly string $approvalHash,
        public readonly string $operatorSignature,
        public readonly string $cortexSnapshotHash,
        public readonly string $decidedAtUtc,
        public readonly string $decisionReceipt,
    ) {
    }

    /**
     * @return array<string,string>  ksort-canonical, exactly the REQUIRED_KEYS set
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
        ];
    }
}
