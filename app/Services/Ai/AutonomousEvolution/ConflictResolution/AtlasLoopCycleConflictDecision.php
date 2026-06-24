<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\ConflictResolution;

final class AtlasLoopCycleConflictDecision
{
    /**
     * @param  list<array<string,mixed>>  $receipts
     * @param  list<string>  $blockedCycles
     */
    public function __construct(
        public readonly string $decision,
        public readonly array $blockedCycles,
        public readonly array $receipts,
    ) {}
}
