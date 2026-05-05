<?php

namespace App\Services\Ai\Kernel\Repair;

final readonly class RepairDecision
{
    /**
     * @param  array<int,string>  $reasons
     * @param  array<string,mixed>  $evidencePayload
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public RepairDecisionStatus $status,
        public ?string $strategy,
        public int $nextAttempt,
        public array $reasons = [],
        public array $evidencePayload = [],
        public array $metadata = [],
    ) {}

    public function allowsRepair(): bool
    {
        return $this->status === RepairDecisionStatus::RepairAllowed;
    }

    public function requiresHumanReview(): bool
    {
        return $this->status === RepairDecisionStatus::NeedsHumanReview;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'status' => $this->status->value,
            'strategy' => $this->strategy,
            'next_attempt' => $this->nextAttempt,
            'reasons' => $this->reasons,
            'evidence_payload' => $this->evidencePayload,
            'metadata' => $this->metadata,
        ];
    }
}
