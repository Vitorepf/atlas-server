<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Resilience\AtlasLoopProcessTopologyProbe;
use App\Services\Ai\AutonomousEvolution\Resilience\AtlasLoopZombieReaper;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopZombieReaper} at the operator surface (REPORT-ONLY): builds a live-pid
 * topology via {@see AtlasLoopProcessTopologyProbe} and asks the reaper, in FORCED dry-run, which claim-bearing
 * rows it WOULD release (pid absent from topology, claim past grace, status claimed) vs. spares, with reasons.
 *
 * STRICTLY observe-only: dry_run is forced true, so NO row is ever released and NO process is signalled — the
 * actual claim release is a separate operator-gated packet.
 */
final class AtlasLoopZombieReportCommand extends Command
{
    protected $signature = 'atlas:loop:zombie-report {--grace=60} {--json}';

    protected $description = 'Report-only stale (zombie) claim detection: would-release vs. spared rows, with reasons.';

    public function handle(): int
    {
        $grace = max(0, (int) $this->option('grace'));
        $app = $this->getLaravel();

        $snapshot = $app->make(AtlasLoopProcessTopologyProbe::class)->snapshot();

        // dry_run is FORCED true — this command never releases a claim. Tables default to the reaper's own set;
        // an optional config override exists only so a test can point at a throwaway table.
        $options = ['dry_run' => true, 'grace_seconds' => $grace];
        $tables = config('atlas.loop.zombie_reaper.tables');
        if (is_array($tables) && $tables !== []) {
            $options['tables'] = array_values($tables);
        }

        $report = $app->make(AtlasLoopZombieReaper::class)->reap($snapshot, $options);

        $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
