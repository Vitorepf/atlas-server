<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevFlowMapProductOptionsV1Part07Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Flow Map And Product Options v1 · Parte 7 — "Fatia 7" entry-gate and
 * "Regra Final" continuation-classifier CLI.
 *
 *   php artisan atlas:aaeos:atlas-dev-flow-map-product-options-v1-part07 [--json]
 *
 * Read-only, deterministic. Runs the entry-gate decider over a not-ready payload
 * (no local results → blocked on ordering) and a fully-ready payload (gate open),
 * then runs the continuation classifier over the three documented outcomes, and
 * emits everything plus the manifest as JSON. It never executes, routes, creates
 * an arm, plans, calls a provider or touches a DB.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-07.md
 */
class AtlasDevFlowMapProductOptionsV1Part07Command extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-flow-map-product-options-v1-part07 {--json}';

    protected $description = 'Atlas Dev flow map & product options (Parte 7) · evaluate the comparison-arm entry gate and the Regra Final continuation classifier.';

    public function handle(AtlasDevFlowMapProductOptionsV1Part07Service $service): int
    {
        try {
            // Not ready: no local results → ordering breach blocks the gate even
            // though some later boxes are ticked.
            $notReady = [
                'local_results_present' => false,
                'real_arm_active' => true,
                'contract_frozen' => true,
                'pure_baselines_run' => true,
                'baselines_run_set' => ['sonnet_pure', 'opus_pure'],
                'metrics_registered' => true,
                'metrics_registered_set' => ['cost', 'time', 'quality', 'failures'],
            ];

            // Fully ready: every documented step satisfied with complete evidence.
            $ready = [
                'local_results_present' => true,
                'real_arm_active' => true,
                'contract_frozen' => true,
                'pure_baselines_run' => true,
                'baselines_run_set' => ['sonnet_pure', 'opus_pure'],
                'metrics_registered' => true,
                'metrics_registered_set' => ['cost', 'time', 'quality', 'failures'],
            ];

            $payload = [
                'ok' => true,
                'manifest' => $service->manifest(),
                'entry_gate_not_ready' => $service->evaluateEntryGate($notReady),
                'entry_gate_ready' => $service->evaluateEntryGate($ready),
                'continuation' => [
                    'escalate' => $service->classifyContinuation(['risk_imminent' => true, 'common_work' => true]),
                    'stop_early' => $service->classifyContinuation(['should_not_continue' => true]),
                    'proceed' => $service->classifyContinuation(['common_work' => true, 'confidence' => 'high']),
                ],
                'success_criterion' => [
                    'cheaper_forge' => $service->judgeSuccessCriterion('fazer tudo que o Forge faz, mais barato'),
                    'detect_early' => $service->judgeSuccessCriterion('detectar cedo quando nao deve continuar'),
                ],
                'mode_taxonomy' => $service->modeTaxonomy(),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_dev_flow_map_product_options_v1_part07_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
