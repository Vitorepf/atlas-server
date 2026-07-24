<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Gap4.F5 — Self-Construction OS sub-command extraction (Provider).
 *
 * Thin shell that exposes the canonical entry point for the AgentProviderAdapter
 * family (~2 services). The mother command remains runtime-active.
 */
class AtlasAiSelfConstructionProviderCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:self-construction:provider {--json : machine-readable}';

    protected $description = 'Atlas Self-Construction OS — AgentProviderAdapter family (2 services).';

    public function handle(): int
    {
        $payload = [
            'schema_version' => 'atlas.self_construction.sub_command_shell.v1',
            'family' => 'AgentProviderAdapter',
            'estimated_service_count' => 2,
            'status' => 'shell_ready',
            'detail' => 'Canonical entry point for AgentProviderAdapter. Full action set still served by atlas:ai:self-construction mother.',
            'mother_command' => 'atlas:ai:self-construction',
        ];
        $this->jsonLine($payload);

        return self::SUCCESS;
    }
}
