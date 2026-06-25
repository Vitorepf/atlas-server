<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Branching;

final class AtlasLoopCycleBranchOutcome
{
    /** @param array<string,mixed> $provenance */
    public function __construct(
        public readonly bool $success,
        public readonly string $diffSummary,
        public readonly string $diffContent,
        public readonly array $provenance = [],
    ) {}
}
