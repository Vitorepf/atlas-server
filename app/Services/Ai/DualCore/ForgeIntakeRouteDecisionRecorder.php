<?php

declare(strict_types=1);

namespace App\Services\Ai\DualCore;

use App\Models\AiDualCoreRouteDecision;
use Illuminate\Support\Facades\Log;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Throwable;

/**
 * Records `atlas.dual_core.route_decision.v1` for the Forge HTTP-direct intake
 * path (Mechanism 4 of the Dev→Forge consolidation plan). The legacy Forge
 * HTTP routes — `POST /works/{project}/forge/live-executions` and its async
 * variant — dispatched to the Forge runtime without emitting a route decision,
 * which made the spine readiness service correctly flag them as a P1 blocker
 * in the Runtime Spine Completion Audit (2026-05-18).
 *
 * This recorder is intentionally thin:
 *   - it sits in front of the Forge dispatch, never inside it;
 *   - it never modifies Forge execution logic;
 *   - it is tolerant: when `ai_dual_core_route_decisions` is absent (early
 *     environments) it returns null and emits a structured warning log so
 *     operators see the degradation, instead of silently bypassing the
 *     contract.
 *
 * Canonical caller: `AtlasCodeForgeExecutionController::store` /
 * `AtlasCodeForgeExecutionController::startAsync`. Other Forge HTTP entry
 * points (operating room, fast path, async status) can adopt the same call.
 *
 * Mode: `forge` (canonical). Reason carries the HTTP route path so the audit
 * trail makes it obvious which controller emitted the decision.
 */
final class ForgeIntakeRouteDecisionRecorder
{
    public function __construct(private readonly DualCoreRouteDecisionService $routeDecisions) {}

    /**
     * Record a forge intake decision. Returns the persisted row on success,
     * or null when the spine table is not yet migrated (tolerant mode).
     *
     * @param  array<string,mixed>  $context  optional extras:
     *                                        - mission_id, work_order_id, conversation_id (strings)
     *                                        - reason (overrides default)
     *                                        - intent_summary (overrides default)
     *                                        - routing_signals (array merged into signals)
     *                                        - rejected_routes (array; defaults to ['dev'])
     *                                        - actor_type (defaults to 'forge_http_intake')
     */
    public function record(
        string $route,
        string $httpRoutePath,
        ?string $projectId = null,
        array $context = [],
    ): ?AiDualCoreRouteDecision {
        if ($route === '') {
            $route = DualCoreRouteDecisionCanon::ROUTE_FORGE;
        }
        if (! DatabaseTableAvailability::has('ai_dual_core_route_decisions')) {
            Log::warning('atlas.dual_core.forge_intake_recorder.table_missing', [
                'http_route' => $httpRoutePath,
                'project_id' => $projectId,
                'route' => $route,
            ]);

            return null;
        }

        $reason = (string) ($context['reason']
            ?? sprintf('forge_http_direct_intake:%s', $httpRoutePath));
        $intentSummary = (string) ($context['intent_summary']
            ?? sprintf('Forge HTTP intake via %s', $httpRoutePath));

        $signals = array_merge([
            'http_route' => $httpRoutePath,
            'project_id' => $projectId,
            'source' => 'forge_http_direct',
            'mechanism' => 4,
        ], (array) ($context['routing_signals'] ?? []));

        $rejected = (array) ($context['rejected_routes'] ?? [DualCoreRouteDecisionCanon::ROUTE_DEV]);

        // Forge default: high risk + low ambiguity (the path is explicit) +
        // duration in days (Forge obras are multi-step). Callers may override
        // any of these via $context.
        $options = [
            'mission_id' => $context['mission_id'] ?? null,
            'work_order_id' => $context['work_order_id'] ?? null,
            'conversation_id' => $context['conversation_id'] ?? null,
            'routing_signals' => $signals,
            'rejected_routes' => $rejected,
            'risk_level' => $context['risk_level'] ?? DualCoreRouteDecisionCanon::RISK_HIGH,
            'expected_duration' => $context['expected_duration'] ?? DualCoreRouteDecisionCanon::DURATION_DAYS,
            'ambiguity_level' => $context['ambiguity_level'] ?? DualCoreRouteDecisionCanon::AMBIGUITY_LOW,
            'operator_visible' => $context['operator_visible'] ?? true,
            'actor_type' => $context['actor_type'] ?? 'forge_http_intake',
        ];
        // Pass through nullable extras only when caller provided them, so
        // record()'s own defaults (evidence_required, sdd_required, etc.)
        // take effect when omitted.
        foreach (['modules_touched_estimate', 'sdd_required', 'evidence_required', 'confidence'] as $optional) {
            if (array_key_exists($optional, $context)) {
                $options[$optional] = $context[$optional];
            }
        }

        try {
            return $this->routeDecisions->record($route, $reason, $intentSummary, $options);
        } catch (Throwable $e) {
            Log::warning('atlas.dual_core.forge_intake_recorder.record_failed', [
                'http_route' => $httpRoutePath,
                'project_id' => $projectId,
                'route' => $route,
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
