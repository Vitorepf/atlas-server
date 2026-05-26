<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Gap4.F5 — Self-Construction OS sub-command extraction (Validation).
 *
 * Thin shell that exposes the canonical entry point for the AgentValidationGate
 * family (~7 services). The mother command remains runtime-active.
 */
class AtlasAiSelfConstructionValidationCommand extends Command
{
    protected $signature = 'atlas:ai:self-construction:validation {--json : machine-readable}';

    protected $description = 'Atlas Self-Construction OS — AgentValidationGate family (7 services).';

    public function handle(): int
    {
        $payload = [
            'schema_version' => 'atlas.self_construction.sub_command_shell.v1',
            'family' => 'AgentValidationGate',
            'estimated_service_count' => 7,
            'status' => 'shell_ready',
            'detail' => 'Canonical entry point for AgentValidationGate. Full action set still served by atlas:ai:self-construction mother.',
            'mother_command' => 'atlas:ai:self-construction',
        ];
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

        return self::SUCCESS;
    }
}
