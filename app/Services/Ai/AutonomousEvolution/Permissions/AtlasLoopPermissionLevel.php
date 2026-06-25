<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Permissions;

/**
 * Closed enum of permission levels with a strict total order:
 *   READ < PROPOSE < WRITE < MERGE
 *
 * Used by {@see AtlasLoopPermissionLevelRegistry} to declare per-phase authority.
 */
enum AtlasLoopPermissionLevel: int
{
    case READ = 1;
    case PROPOSE = 2;
    case WRITE = 3;
    case MERGE = 4;

    public function isAtLeast(self $other): bool
    {
        return $this->value >= $other->value;
    }

    public function compareTo(self $other): int
    {
        return $this->value <=> $other->value;
    }

    public function label(): string
    {
        return $this->name;
    }
}
