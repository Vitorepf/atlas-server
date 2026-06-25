<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\TaskClassDiscovery;

/**
 * Immutable lifecycle receipt for a task-class event. Provider-safe: no provider id / prompt /
 * trace fields.
 */
final readonly class AtlasLoopTaskClassReceipt
{
    public const KIND_CLUSTER_MINED = 'cluster_mined';
    public const KIND_PROPOSAL_EMITTED = 'proposal_emitted';
    public const KIND_PROPOSAL_REFUSED = 'proposal_refused';
    public const KIND_PROPOSAL_APPROVED = 'proposal_approved';
    public const KIND_REGISTRY_SUPERSEDE = 'registry_supersede';
    public const KIND_PACKET_MATCHED = 'packet_matched_to_class';
    public const KIND_PACKET_OUTCOME = 'packet_outcome_recorded';

    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $evidenceIds
     */
    public function __construct(
        public string $receiptId,
        public int $seq,
        public string $eventKind,
        public ?string $classId,
        public array $payload,
        public array $evidenceIds,
        public string $prevHash,
        public string $thisHash,
        public int $recordedAtUnix,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'class_id' => $this->classId,
            'event_kind' => $this->eventKind,
            'evidence_ids' => $this->evidenceIds,
            'payload' => $this->payload,
            'prev_hash' => $this->prevHash,
            'receipt_id' => $this->receiptId,
            'recorded_at_unix' => $this->recordedAtUnix,
            'seq' => $this->seq,
            'this_hash' => $this->thisHash,
        ];
    }
}
