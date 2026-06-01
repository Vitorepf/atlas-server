<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasForgeProviderTopologyService;
use Illuminate\Console\Command;
use Throwable;

/**
 * CLI surface for the Forge provider topology read model — the doc described the
 * adaptive provider operating room runtime but the service had no operator
 * command. Thin wrapper over topology(); read-only.
 *
 * @see docs/engineering-knowledge-base/atlas-code-adaptive-provider-operating-room-v1.md
 */
class AtlasForgeProviderTopologyCommand extends Command
{
    protected $signature = 'atlas:forge:provider-topology {--json}';

    protected $description = 'Show the Forge adaptive provider topology read model.';

    public function handle(AtlasForgeProviderTopologyService $topology): int
    {
        try {
            $result = $topology->topology();
        } catch (Throwable $e) {
            $result = ['error' => $e::class, 'message' => $e->getMessage()];
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        return self::SUCCESS;
    }
}
