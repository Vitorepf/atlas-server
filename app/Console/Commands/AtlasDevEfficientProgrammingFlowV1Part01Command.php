<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevEfficientProgrammingFlowV1Part01Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Efficient Programming Flow v1 · Parte 1 — scope/boundary decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-dev-efficient-programming-flow-v1-part01 [--json]
 *
 * Read-only, deterministic. Exercises the documented slice (§1–§8) with safe
 * defaults: classify an in-scope workspace-bound patch (fast path), an in-scope
 * kind with no workspace (delegates to Research), an out-of-scope sensitive
 * change (escalates to Forge), a surface-agnostic violation, a forbidden
 * ui_hints decision and a pipeline adjacency check, then emits the verdicts plus
 * the manifest as JSON.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1-part-01.md
 */
class AtlasDevEfficientProgrammingFlowV1Part01Command extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-efficient-programming-flow-v1-part01 {--json}';

    protected $description = 'Atlas Dev efficient programming flow (Parte 1) · classify scope/boundary routing, surface-agnostic invariant and pipeline order.';

    public function handle(AtlasDevEfficientProgrammingFlowV1Part01Service $service): int
    {
        try {
            $payload = [
                'ok' => true,
                'manifest' => $service->manifest(),
                'classify_workspace_patch' => $service->classifyRequest('patch', true, true, 'atlas_ai_router'),
                'classify_in_scope_no_workspace' => $service->classifyRequest('workspace_question', false, false, 'direct'),
                'classify_sensitive_change' => $service->classifyRequest('sensitive_change', true, true, 'direct'),
                'surface_agnostic_violation' => $service->surfaceAgnosticCheck('Pipeline', true),
                'surface_segment_ok' => $service->surfaceAgnosticCheck('Surface', true),
                'ui_hints_route_forbidden' => $service->uiHintsMayDecide('route'),
                'pipeline_routing_to_provider' => $service->pipelineOrder('routing_decision', 'provider_decision'),
                'provider_lock' => $service->providerLock(),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_dev_flow_v1_part01_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
