<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevFlowMapProductOptionsV1Part05Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Flow Map And Product Options v1 · Parte 5 — flow/product decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-dev-flow-map-product-options-v1-part05 [--json]
 *
 * Read-only, deterministic, zero side effect. Exercises the documented
 * "Conteudo Extraido" slice with safe defaults: executor selection, quality-gate
 * attachment, the normalized repair contract (incl. debug max_iterations=3), the
 * Dev->Forge promotion target (incl. the thin_small_bug veto), the use-case flow
 * map and the Open Brain mode resolution, then emits the verdicts plus the
 * manifest as JSON.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-05.md
 */
class AtlasDevFlowMapProductOptionsV1Part05Command extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-flow-map-product-options-v1-part05 {--json}';

    protected $description = 'Atlas Dev flow map and product options (Parte 5) · decide executor, quality gate, repair contract, Dev->Forge target and use-case flow.';

    public function handle(AtlasDevFlowMapProductOptionsV1Part05Service $service): int
    {
        try {
            $payload = [
                'ok' => true,
                'manifest' => $service->manifest(),
                'executor_simple' => $service->decideExecutor(
                    $service::PROFILE_DEV,
                    false,
                    false,
                ),
                'executor_dev_repair' => $service->decideExecutor(
                    $service::PROFILE_DEV,
                    false,
                    true,
                ),
                'executor_harness_forge' => $service->decideExecutor(
                    $service::PROFILE_FORGE,
                    false,
                    true,
                ),
                'quality_gate_complete' => $service->decideQualityGate(true, 1),
                'quality_gate_single_shot' => $service->decideQualityGate(false, 1),
                'quality_gate_multi_iteration' => $service->decideQualityGate(false, 3),
                'repair_contract_overflow' => $service->repairContract(9, true, true),
                'repair_contract_floor' => $service->repairContract(0),
                'promotion_thin_small_bug' => $service->decidePromotionTarget([
                    'file_count' => 1,
                    'risk_or_production' => false,
                ]),
                'promotion_forge_obra' => $service->decidePromotionTarget([
                    'file_count' => 9,
                    'subsystem_count' => 3,
                    'risk_or_production' => true,
                    'architecture_or_refactor' => true,
                ]),
                'promotion_obra_candidate' => $service->decidePromotionTarget([
                    'file_count' => 4,
                    'recurrent_failure' => true,
                ]),
                'usecase_debug' => $service->mapUseCaseFlow('debug'),
                'usecase_review' => $service->mapUseCaseFlow('review'),
                'open_brain_forge' => $service->resolveOpenBrainMode(true),
                'open_brain_off' => $service->resolveOpenBrainMode(false, true),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }
    }
}
