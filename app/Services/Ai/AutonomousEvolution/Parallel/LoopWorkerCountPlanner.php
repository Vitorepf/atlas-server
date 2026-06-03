<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Parallel;

use Closure;

/**
 * Plans a safe parallel width: clamp the requested worker count to (cores - 2) and the
 * configured ceiling, never below 1. The cpu probe is injectable so the planner is
 * deterministically testable.
 */
final class LoopWorkerCountPlanner
{
    /** @param  Closure():int|null  $cpuProbe */
    public function __construct(private readonly ?Closure $cpuProbe = null) {}

    public function plan(int $requested): int
    {
        $cores = $this->cpuProbe !== null ? (int) ($this->cpuProbe)() : $this->detectCores();
        $byCpu = max(1, $cores - 2);
        $ceiling = max(1, (int) config('atlas.loop.parallel.max_workers', 4));

        return max(1, min(max(1, $requested), $byCpu, $ceiling));
    }

    private function detectCores(): int
    {
        $raw = @shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n hw.ncpu 2>/dev/null' : 'nproc 2>/dev/null');

        return max(1, (int) trim((string) $raw) ?: 4);
    }
}
