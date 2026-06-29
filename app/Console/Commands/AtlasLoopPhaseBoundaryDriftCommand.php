<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\LiveCycle\FactPassing\AtlasLoopPhaseBoundaryFactDriftDetector;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopPhaseBoundaryFactDriftDetector::detect()} at the operator surface: over a
 * window of recent cycles it emits, per phase boundary, the fact-passing drift (invalid count, top missing /
 * unknown keys, first divergence cycle) as deterministic facts. Read-only.
 */
final class AtlasLoopPhaseBoundaryDriftCommand extends Command
{
    protected $signature = 'atlas:loop:phase-boundary-drift {--window=20} {--json}';

    protected $description = 'Read-only phase-boundary fact-drift report over a window of cycles.';

    public function handle(AtlasLoopPhaseBoundaryFactDriftDetector $detector): int
    {
        $report = $detector->detect(max(0, (int) $this->option('window')));

        $this->line((string) json_encode(array_merge(
            ['schema_version' => 'atlas.loop.phase_boundary_drift.v1'],
            $report,
        ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
