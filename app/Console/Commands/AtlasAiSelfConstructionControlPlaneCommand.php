<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Gap4.F5 — Self-Construction OS sub-command extraction (ControlPlane).
 *
 * Thin shell that exposes the canonical entry point for the AgentControlPlane
 * family (~56 services). The mother command remains runtime-active.
 */
class AtlasAiSelfConstructionControlPlaneCommand extends Command
{
    protected $signature = 'atlas:ai:self-construction:control-plane {--json : machine-readable}';

    protected $description = 'Atlas Self-Construction OS — AgentControlPlane family (56 services).';

    public function handle(): int
    {
        $payload = [
            'schema_version' => 'atlas.self_construction.sub_command_shell.v1',
            'family' => 'AgentControlPlane',
            'estimated_service_count' => 56,
            'status' => 'shell_ready',
            'detail' => 'Canonical entry point for AgentControlPlane. Full action set still served by atlas:ai:self-construction mother.',
            'mother_command' => 'atlas:ai:self-construction',
        ];
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

        return self::SUCCESS;
    }
}
