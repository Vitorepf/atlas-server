<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevEffProgFlowContractsV1Part05Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Efficient Programming Flow Contracts v1 · Parte 5 — invariant gate CLI.
 *
 *   php artisan atlas:aaeos:atlas-dev-eff-prog-flow-contracts-v1-part05 [--json]
 *
 * Read-only, deterministic. Validates a known-good CodeDiscoveryManifest (doc
 * 5.2), OpenBrainProgrammingProjection (doc 5.3) and ProviderPromptProjection
 * (doc 5.4) against their numbered invariants and emits the verdicts as JSON.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-05.md
 */
class AtlasDevEffProgFlowContractsV1Part05Command extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-eff-prog-flow-contracts-v1-part05 {--json}';

    protected $description = 'Atlas Dev flow contracts (Parte 5) · validate CodeDiscoveryManifest + OpenBrain + ProviderPrompt projection invariants.';

    public function handle(AtlasDevEffProgFlowContractsV1Part05Service $service): int
    {
        try {
            $payload = $service->selfCheck();

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $payload['all_valid'] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_dev_eff_prog_flow_contracts_v1_part05_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
