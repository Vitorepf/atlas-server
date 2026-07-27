<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\TraceReplay;

final class DivergenceFact
{
    public function __construct(
        public readonly int $stepIndex,
        public readonly string $action,
        public readonly string $recordedFp,
        public readonly string $observedFp,
        public readonly bool $diverged,
        public readonly ?int $firstByteDiffOffset,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'diverged' => $this->diverged,
            'first_byte_diff_offset' => $this->firstByteDiffOffset,
            'observed_fp' => $this->observedFp,
            'recorded_fp' => $this->recordedFp,
            'step_index' => $this->stepIndex,
        ];
    }
}
