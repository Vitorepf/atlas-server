<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasMultiProviderAgentOrchestrationContractService;
use Illuminate\Console\Command;
use Throwable;

/**
 * CLI surface for the Multi-Provider Agent Orchestration Contract. Read-only:
 * it demonstrates the L5 readiness gate (which must stay BLOCKED without signed
 * receipts) using safe defaults. Thin wrapper; never dispatches a provider.
 *
 * @see docs/engineering-knowledge-base/self-construction/multi-provider-agent-orchestration-contract.md
 */
class AtlasMultiProviderAgentOrchestrationContractCommand extends Command
{
    protected $signature = 'atlas:aaeos:multi-provider-agent-orchestration-contract {--json}';

    protected $description = 'Show the multi-provider orchestration contract: L5 readiness gate (blocked without receipts).';

    public function handle(AtlasMultiProviderAgentOrchestrationContractService $service): int
    {
        try {
            // Safe default: request the future L5 level with NO receipts; the
            // contract must report it as blocked.
            $result = $service->gateReadiness('L5', [
                'signed_authority' => false,
                'reservations' => false,
                'gates_green' => false,
            ]);
        } catch (Throwable $e) {
            $result = ['error' => $e::class, 'message' => $e->getMessage()];
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        return self::SUCCESS;
    }
}
