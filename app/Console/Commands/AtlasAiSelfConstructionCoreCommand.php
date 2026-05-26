<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Gap4.F5 — Self-Construction OS sub-command extraction (Core).
 *
 * Thin shell that exposes the canonical entry point for the AtlasSelfConstructionCore
 * family (~69 services). The mother command remains runtime-active.
 */
class AtlasAiSelfConstructionCoreCommand extends Command
{
    protected $signature = 'atlas:ai:self-construction:core {--json : machine-readable}';

    protected $description = 'Atlas Self-Construction OS — AtlasSelfConstructionCore family (69 services).';

    public function handle(): int
    {
        $payload = [
            'schema_version' => 'atlas.self_construction.sub_command_shell.v1',
            'family' => 'AtlasSelfConstructionCore',
            'estimated_service_count' => 69,
            'status' => 'shell_ready',
            'detail' => 'Canonical entry point for AtlasSelfConstructionCore. Full action set still served by atlas:ai:self-construction mother.',
            'mother_command' => 'atlas:ai:self-construction',
        ];
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

        return self::SUCCESS;
    }
}
