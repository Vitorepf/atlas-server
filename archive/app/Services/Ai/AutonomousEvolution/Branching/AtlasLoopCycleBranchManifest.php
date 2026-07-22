<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Branching;

final class AtlasLoopCycleBranchManifest
{
    public function __construct(
        public readonly string $parentCycleId,
        public readonly string $branchId,
        public readonly string $hypothesis,
        public readonly string $baseCommitSha,
        public readonly string $createdAt,
        public readonly string $workspacePath,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'base_commit_sha' => $this->baseCommitSha,
            'branch_id' => $this->branchId,
            'created_at' => $this->createdAt,
            'hypothesis' => $this->hypothesis,
            'parent_cycle_id' => $this->parentCycleId,
            'workspace_path' => $this->workspacePath,
        ];
    }
}
