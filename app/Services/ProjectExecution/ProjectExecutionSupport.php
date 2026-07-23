<?php

namespace App\Services\ProjectExecution;

/**
 * Leaf primitives shared by ProjectExecutionService and its family sections.
 * Pure, stateless, no I/O.
 */
class ProjectExecutionSupport
{
    public function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }

    public function validEnergy(mixed $energy): string
    {
        return in_array($energy, ['low', 'medium', 'high'], true) ? (string) $energy : 'medium';
    }
}
