<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasRecipesExternalMcpService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Cyber External MCP Tooling CLI.
 *
 *   php artisan atlas:aaeos:recipes-external-mcp [--json]
 *
 * Read-only, deterministic. Evaluates an external offensive MCP server
 * registration against the documented wrapped-recipe governance contract and
 * emits an admit|reject receipt. Defaults model the HexStrike candidate.
 *
 * @see docs/engineering-knowledge-base/cyber-security/recipes-external-mcp.md
 */
class AtlasRecipesExternalMcpCommand extends Command
{
    protected $signature = 'atlas:aaeos:recipes-external-mcp {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas cyber · external offensive MCP admission decider (wrapped recipe governance, admit|reject).';

    public function handle(AtlasRecipesExternalMcpService $service): int
    {
        try {
            // Safe default: the fully-compliant HexStrike candidate from the doc.
            $candidate = [
                'tool_slug' => 'hexstrike-mcp',
                'recipe_name' => 'pentest-orchestrate',
                'integration' => AtlasRecipesExternalMcpService::INTEGRATION_MCP_SERVER,
                'wrapper_skill' => 'cyber-hexstrike-runner',
                'sandbox' => AtlasRecipesExternalMcpService::SANDBOX_DEDICATED_VM,
                'adr_recorded' => true,
                'extra_approval' => true,
                'enforced_controls' => AtlasRecipesExternalMcpService::REQUIRED_CONTROLS,
            ];

            $result = $service->evaluateRegistration($candidate);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $result['verdict'] === AtlasRecipesExternalMcpService::VERDICT_ADMIT
                ? self::SUCCESS
                : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'external_mcp_admission_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
