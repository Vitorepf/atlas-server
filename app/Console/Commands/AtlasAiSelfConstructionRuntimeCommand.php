<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Gap4.F5 — Self-Construction OS sub-command extraction (Runtime).
 *
 * Thin shell that exposes the canonical entry point for the AgentRuntime
 * family (~28 services). The mother command remains runtime-active.
 */
class AtlasAiSelfConstructionRuntimeCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:self-construction:runtime {--json : machine-readable}';

    protected $description = 'Atlas Self-Construction OS — AgentRuntime family (28 services).';

    public function handle(): int
    {
        $payload = [
            'schema_version' => 'atlas.self_construction.sub_command_shell.v1',
            'family' => 'AgentRuntime',
            'estimated_service_count' => 28,
            'status' => 'shell_ready',
            'detail' => 'Canonical entry point for AgentRuntime. Full action set still served by atlas:ai:self-construction mother.',
            'mother_command' => 'atlas:ai:self-construction',
        ];
        $this->jsonLine($payload);

        return self::SUCCESS;
    }
}
