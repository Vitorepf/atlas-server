<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\ConflictResolution;

final class AtlasLoopCycleConflictReport
{
    /**
     * @param  list<string>  $overlappingPaths
     * @param  list<array{path:string,mode:string}>  $perFile
     */
    public function __construct(
        public readonly string $leftCycleId,
        public readonly string $rightCycleId,
        public readonly array $overlappingPaths,
        public readonly array $perFile,
    ) {}

    public function hasConflict(): bool
    {
        return $this->overlappingPaths !== [];
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'left_cycle_id' => $this->leftCycleId,
            'right_cycle_id' => $this->rightCycleId,
            'overlapping_paths' => $this->overlappingPaths,
            'per_file' => $this->perFile,
        ];
    }
}
