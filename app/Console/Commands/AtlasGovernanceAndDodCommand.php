<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasGovernanceAndDodService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI OS - Governance And DoD decider CLI.
 *
 *   php artisan atlas:aaeos:governance-and-dod [--json]
 *
 * Read-only and deterministic. With safe defaults it demonstrates the contract:
 * an empty flow is NOT mature (all 11 DoD parts missing), the horizontal-layer
 * pipeline is listed in doc order, and a sample multi-surface capability routes
 * to Core (anti-duplication rule 1).
 *
 * @see docs/engineering-knowledge-base/operating-system/governance-and-dod.md
 */
class AtlasGovernanceAndDodCommand extends Command
{
    protected $signature = 'atlas:aaeos:governance-and-dod {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas AI OS governance: anti-duplication routing, ownership and the 11-part Flow Definition of Done.';

    public function handle(AtlasGovernanceAndDodService $service): int
    {
        try {
            // Safe default flow: nothing declared — every required DoD part is
            // missing, so the flow is not mature.
            $dod = $service->evaluateFlowDod(['parts' => [], 'learning_applicable' => false]);

            // Sample capability that serves two surfaces => routes to Core.
            $routing = $service->classifyCapability([
                'surfaces' => ['desktop', 'mobile'],
            ]);

            $decision = [
                'horizontal_layers' => $service->horizontalLayers(),
                'sample_routing' => $routing,
                'empty_flow_dod' => $dod,
            ];

            $this->line((string) json_encode(
                ['ok' => true, 'decision' => $decision],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'governance_and_dod_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
