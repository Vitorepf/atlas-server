<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasAgentsAndMcpContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas SDD Agents-and-MCP contract gate CLI.
 *
 *   php artisan atlas:aaeos:agents-and-mcp-contract
 *     [--tool=read_file_context]
 *     [--secrets]                 // secrets_in_context=true
 *     [--external]                // external_server=true
 *     [--wrapped]                 // wrapped_by_tool_runtime=true
 *     [--receipt=AP-123]          // decision_receipt id (write tools)
 *     [--human-gate]              // human_gate=true
 *     [--json]
 *
 * Read-only, deterministic. Emits the allow|block|requires_decision_receipt
 * verdict + receipt for one MCP tool invocation.
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/agents-and-mcp-contract.md
 */
class AtlasAgentsAndMcpContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:agents-and-mcp-contract
        {--tool= : the requested MCP tool name (read_file_context|apply_patch|...)}
        {--secrets : mark the request as carrying secrets in model context}
        {--external : tool is served by an external MCP server}
        {--wrapped : external server is wrapped by Atlas Tool Runtime}
        {--receipt= : dedicated Decision Receipt id authorizing a write tool}
        {--human-gate : a human gate approved the write receipt}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas SDD · MCP tool-invocation gate (allow|block|requires_decision_receipt) + agent/resource/tool manifest.';

    public function handle(AtlasAgentsAndMcpContractService $service): int
    {
        try {
            $tool = $this->option('tool');

            $decision = $service->decide([
                'tool' => is_string($tool) && trim($tool) !== '' ? $tool : 'read_file_context',
                'secrets_in_context' => (bool) $this->option('secrets'),
                'external_server' => (bool) $this->option('external'),
                'wrapped_by_tool_runtime' => (bool) $this->option('wrapped'),
                'decision_receipt' => $this->option('receipt'),
                'human_gate' => (bool) $this->option('human-gate'),
            ]);

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision, 'manifest' => $service->manifest()],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'agents_and_mcp_contract_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
