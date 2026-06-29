<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Resilience\AtlasLoopProcessTopologyProbe;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopProcessTopologyProbe::snapshot()} at the operator surface: emits the current
 * loop process topology (supervisor / grind / hermes / watchdog / keepalive pids, their ages and parent tree)
 * as deterministic facts. Read-only — it only runs `ps` and parses the output; it never signals or kills.
 */
final class AtlasLoopProcessTopologyCommand extends Command
{
    protected $signature = 'atlas:loop:process-topology {--json}';

    protected $description = 'Read-only snapshot of the loop process topology (pids, ages, parent tree).';

    public function handle(AtlasLoopProcessTopologyProbe $probe): int
    {
        $this->line((string) json_encode($probe->snapshot(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
