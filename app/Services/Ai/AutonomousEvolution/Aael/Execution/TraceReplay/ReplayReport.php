<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\TraceReplay;

final class ReplayReport
{
    /** @param list<DivergenceFact> $divergences */
    public function __construct(
        public readonly int $totalStepsReplayed,
        public readonly int $divergedStepCount,
        public readonly ?int $firstDivergedStepIndex,
        public readonly string $rootCommitAtRecord,
        public readonly string $rootCommitAtReplay,
        public readonly array $divergences,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'diverged_step_count' => $this->divergedStepCount,
            'divergences' => array_map(static fn (DivergenceFact $d): array => $d->toArray(), $this->divergences),
            'first_diverged_step_index' => $this->firstDivergedStepIndex,
            'root_commit_at_record' => $this->rootCommitAtRecord,
            'root_commit_at_replay' => $this->rootCommitAtReplay,
            'total_steps_replayed' => $this->totalStepsReplayed,
        ];
    }
}
