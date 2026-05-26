<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Gap4.F5 — Self-Construction OS sub-command extraction (DispatchPlanner).
 *
 * Thin shell that exposes the canonical entry point for the AgentDispatchPlanner
 * family (~17 services). The mother command remains runtime-active.
 */
class AtlasAiSelfConstructionDispatchPlannerCommand extends Command
{
    protected $signature = 'atlas:ai:self-construction:dispatch-planner {--json : machine-readable}';

    protected $description = 'Atlas Self-Construction OS — AgentDispatchPlanner family (17 services).';

    public function handle(): int
    {
        $payload = [
            'schema_version' => 'atlas.self_construction.sub_command_shell.v1',
            'family' => 'AgentDispatchPlanner',
            'estimated_service_count' => 17,
            'status' => 'shell_ready',
            'detail' => 'Canonical entry point for AgentDispatchPlanner. Full action set still served by atlas:ai:self-construction mother.',
            'mother_command' => 'atlas:ai:self-construction',
        ];
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

        return self::SUCCESS;
    }
}
