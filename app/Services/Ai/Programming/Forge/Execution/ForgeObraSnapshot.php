<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Forge\Execution;

use App\Models\AiForgeLongHorizonState;

final readonly class ForgeObraSnapshot
{
    private function __construct(
        public ForgeObraId $obra,
        public string $intakeId,
        public string $status,
        public string $stateHash,
        public ?string $currentMilestone,
        public array $activePackets,
        public array $completedPackets,
        public array $blockers,
        public ?array $nextAction,
        public string $commissioningHash,
    ) {}

    public static function fromState(AiForgeLongHorizonState $state, string $commissioningHash): self
    {
        return new self(
            ForgeObraId::fromString((string) $state->intake_id), (string) $state->intake_id, (string) $state->status,
            (string) $state->state_hash, $state->current_milestone,
            array_values((array) $state->active_work_packets), array_values((array) $state->completed_work_packets),
            array_values((array) $state->blockers), is_array($state->next_action) ? $state->next_action : null, $commissioningHash,
        );
    }
}
