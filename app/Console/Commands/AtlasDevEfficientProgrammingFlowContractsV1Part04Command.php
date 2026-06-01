<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevEfficientProgrammingFlowContractsV1Part04Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Efficient Programming Flow Contracts v1 · Parte 4 — invariant gate CLI.
 *
 *   php artisan atlas:aaeos:atlas-dev-efficient-programming-flow-contracts-v1-part04 [--json]
 *
 * Read-only, deterministic. Validates a known-good LightTaskContract (doc 4.4)
 * and a known-good ContextRetrievalPlan (doc 5.1) against their numbered
 * invariants and emits the verdicts as JSON.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-04.md
 */
class AtlasDevEfficientProgrammingFlowContractsV1Part04Command extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-efficient-programming-flow-contracts-v1-part04 {--json}';

    protected $description = 'Atlas Dev flow contracts (Parte 4) · validate LightTaskContract + ContextRetrievalPlan invariants.';

    public function handle(AtlasDevEfficientProgrammingFlowContractsV1Part04Service $service): int
    {
        try {
            $payload = $service->selfCheck();

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $payload['all_valid'] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_dev_flow_contracts_v1_part04_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
