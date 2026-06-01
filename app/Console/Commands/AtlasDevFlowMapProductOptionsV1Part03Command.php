<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevFlowMapProductOptionsV1Part03Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Flow Map And Product Options v1 · Parte 3 — consolidated-decisions decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-dev-flow-map-product-options-v1-part03 [--json]
 *
 * Read-only, deterministic. Exercises the closed slice with safe defaults: an
 * R5 write attempt (forced plan-only), an incomplete-envelope write (blocked), a
 * complete R1 write (allowed), the three-speed routing for an Obra and for a
 * plain R2 task, the driver run gate (blocks with the documented pending reason),
 * a scope check on a frozen item, then emits the verdicts plus the manifest as JSON.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-flow-map-and-product-options-v1-part-03.md
 */
class AtlasDevFlowMapProductOptionsV1Part03Command extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-flow-map-product-options-v1-part03 {--json}';

    protected $description = 'Atlas Dev flow map and product options (Parte 3) · evaluate the write envelope gate, three-speed routing, the dedicated-driver pending gate and the post-scope-cut exclusions.';

    public function handle(AtlasDevFlowMapProductOptionsV1Part03Service $service): int
    {
        try {
            $payload = [
                'ok' => true,
                'manifest' => $service->manifest(),
                'consolidated_decisions' => $service->consolidatedDecisions(),
                'write_r5_plan_only' => $service->writeGate([
                    'risk_level' => 'R5',
                    'has_mini_spec' => true,
                    'has_task_contract' => true,
                    'has_scope_guard' => true,
                    'has_receipt' => true,
                ]),
                'write_incomplete_envelope' => $service->writeGate([
                    'risk_level' => 'R1',
                    'has_mini_spec' => true,
                ]),
                'write_allowed' => $service->writeGate([
                    'risk_level' => 'R1',
                    'has_mini_spec' => true,
                    'has_task_contract' => true,
                    'has_scope_guard' => true,
                    'has_receipt' => true,
                ]),
                'speed_obra' => $service->speedFor(['obra_declared' => true]),
                'speed_plain_r2' => $service->speedFor(['risk_level' => 'R2']),
                'speed_r4_plan_only' => $service->speedFor(['risk_level' => 'R4']),
                'driver_run_gate_pending' => $service->driverRunGate(false),
                'scope_check_frozen' => $service->scopeCheck('claim_of_win'),
                'scope_check_in_scope' => $service->scopeCheck('verification_receipt'),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_dev_flow_map_product_options_v1_part03_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
