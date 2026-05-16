<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\RunIndex;

use App\Models\AtlasDevRunIndex;

final class AtlasDevRunIndexEntry
{
    public function __construct(
        public readonly string $runId,
        public readonly string $surfaceId,
        public readonly string $workspaceHash,
        public readonly ?string $threadId,
        public readonly string $routingDecision,
        public readonly string $taskKind,
        public readonly string $riskLevel,
        public readonly ?string $completionState,
        public readonly ?string $lastReceiptHash,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
    ) {}

    public static function fromModel(AtlasDevRunIndex $row): self
    {
        return new self(
            runId: (string) $row->run_id,
            surfaceId: (string) $row->surface_id,
            workspaceHash: (string) $row->workspace_hash,
            threadId: $row->thread_id !== null ? (string) $row->thread_id : null,
            routingDecision: (string) $row->routing_decision,
            taskKind: (string) $row->task_kind,
            riskLevel: (string) $row->risk_level,
            completionState: $row->completion_state !== null ? (string) $row->completion_state : null,
            lastReceiptHash: $row->last_receipt_hash !== null ? (string) $row->last_receipt_hash : null,
            createdAt: $row->created_at?->toJSON(),
            updatedAt: $row->updated_at?->toJSON(),
        );
    }

    public function toArray(): array
    {
        return [
            'completion_state' => $this->completionState,
            'created_at' => $this->createdAt,
            'last_receipt_hash' => $this->lastReceiptHash,
            'risk_level' => $this->riskLevel,
            'routing_decision' => $this->routingDecision,
            'run_id' => $this->runId,
            'surface_id' => $this->surfaceId,
            'task_kind' => $this->taskKind,
            'thread_id' => $this->threadId,
            'updated_at' => $this->updatedAt,
            'workspace_hash' => $this->workspaceHash,
        ];
    }
}
