<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Ai\DualCore\DualCoreRouteDecisionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Atlas Code — Engineering Company Runtime HTTP entry.
 *
 * Implements the endpoint declared in
 * `docs/engineering-knowledge-base/atlas-engineering-company-runtime-http-promotion.md`:
 *
 *     POST /atlas-code/work/company
 *
 * Behavior is gated by the canonical feature flag
 * `ATLAS_HTTP_COMPANY_RUNTIME` (config: `atlas.http_company_runtime.mode`):
 *
 *   off       → 503 with rollback envelope (legacy path still serves /works).
 *   shadow    → 202 with shadow envelope; Company Runtime is NOT invoked yet
 *               (Phase 1 ships the entrypoint; actual fork to Company
 *               Runtime is Phase 2 with ParityRecorderService).
 *   on        → 200 after Company Runtime executes (Phase 3, not in scope
 *               of this controller stub).
 *   default   → identical to `on` but legacy fallback removed.
 *
 * This controller ships the routing decision + feature flag respect +
 * route_decision.v1 emission (Gap5 channel canon). It does NOT yet invoke
 * `AtlasRealEngineeringCompanyRuntimeService` because that requires Phase 1
 * AP shipping a `ParityRecorderService` first — declared as PRE-REQ in
 * the ADR. The stub is honest about its boundary.
 */
final class AtlasCodeWorkCompanyController extends Controller
{
    public const RESPONSE_SCHEMA = 'atlas.ai.engineering_company.http_entry_response.v1';

    public const FLAG_OFF = 'off';

    public const FLAG_SHADOW = 'shadow';

    public const FLAG_ON = 'on';

    public const FLAG_DEFAULT = 'default';

    public function store(Request $request, DualCoreRouteDecisionService $routeDecisions): JsonResponse
    {
        $validated = $request->validate([
            'goal_text' => ['required', 'string', 'min:1', 'max:8000'],
            'options' => ['sometimes', 'array'],
            'options.trace_id' => ['sometimes', 'string', 'max:128'],
            'options.operator_authority' => ['sometimes', 'string', 'max:128'],
        ]);

        $flag = (string) config('atlas.http_company_runtime.mode', self::FLAG_OFF);
        $traceId = (string) ($validated['options']['trace_id'] ?? (string) Str::uuid());

        // Emit canonical route_decision.v1 BEFORE branching on flag. This
        // makes every request auditable through the canonical channel
        // (Gap5 Phase 2 invariant) regardless of whether the flag is on.
        $routeDecision = $this->recordRouteDecision(
            $routeDecisions,
            $validated['goal_text'],
            $traceId,
            $flag,
        );

        return match ($flag) {
            self::FLAG_OFF => $this->respondOff($traceId, $routeDecision),
            self::FLAG_SHADOW => $this->respondShadow($traceId, $validated['goal_text'], $routeDecision),
            self::FLAG_ON, self::FLAG_DEFAULT => $this->respondOnStub($traceId, $validated['goal_text'], $routeDecision),
            default => $this->respondOff($traceId, $routeDecision),
        };
    }

    private function respondOff(string $traceId, array $routeDecision): JsonResponse
    {
        return response()->json([
            'schema_version' => self::RESPONSE_SCHEMA,
            'status' => 'feature_flag_off',
            'flag_mode' => self::FLAG_OFF,
            'trace_id' => $traceId,
            'detail' => 'ATLAS_HTTP_COMPANY_RUNTIME is off. Use POST /atlas-code/works (legacy path).',
            'operator_actions' => ['set_flag_to_shadow_to_start_dual_run'],
            'route_decision' => $routeDecision,
        ], 503);
    }

    private function respondShadow(string $traceId, string $goal, array $routeDecision): JsonResponse
    {
        return response()->json([
            'schema_version' => self::RESPONSE_SCHEMA,
            'status' => 'shadow_accepted',
            'flag_mode' => self::FLAG_SHADOW,
            'trace_id' => $traceId,
            'goal_text_length' => mb_strlen($goal),
            'detail' => 'Shadow mode accepted. Company Runtime fork + ParityRecorderService land in Phase 1 AP.',
            'operator_actions' => ['observe_parity_for_7d', 'review_evidence/company_runtime_shadow_run/'],
            'route_decision' => $routeDecision,
            'phase_status' => 'phase_1_pre_implementation_pre_requisite_gap1_f4_cutover',
        ], 202);
    }

    private function respondOnStub(string $traceId, string $goal, array $routeDecision): JsonResponse
    {
        return response()->json([
            'schema_version' => self::RESPONSE_SCHEMA,
            'status' => 'on_mode_not_implemented_yet',
            'flag_mode' => self::FLAG_ON,
            'trace_id' => $traceId,
            'detail' => 'Flag is on/default but Phase 3 implementation is not shipped. Set flag to shadow or off, or land Phase 1+2 first.',
            'operator_actions' => ['rollback_flag_to_off', 'ship_phase_1_ap'],
            'route_decision' => $routeDecision,
            'phase_status' => 'phase_3_blocked_phases_1_and_2_pending',
        ], 503);
    }

    /**
     * @return array<string,mixed>
     */
    private function recordRouteDecision(
        DualCoreRouteDecisionService $service,
        string $goalText,
        string $traceId,
        string $flagMode,
    ): array {
        // Canonical schema: atlas.dual_core.route_decision.v1
        // The recorder tolerates absent table and returns recorded=false;
        // we still embed the decision shape so the response carries audit.
        $decision = [
            'schema_version' => 'atlas.dual_core.route_decision.v1',
            'route' => 'company_runtime',
            'reason' => sprintf('http_entry_company_runtime_flag_%s', $flagMode),
            'trace_id' => $traceId,
            'goal_text_hash' => hash('sha256', $goalText),
            'recorded_at' => now()->toAtomString(),
        ];

        if (method_exists($service, 'recordHttp')) {
            $persisted = $service->recordHttp($decision);
            $decision['recorded'] = (bool) $persisted;

            return $decision;
        }

        $decision['recorded'] = false;
        $decision['recorder_missing_method'] = 'DualCoreRouteDecisionService::recordHttp not implemented yet; this is honest, not silent';

        return $decision;
    }
}
