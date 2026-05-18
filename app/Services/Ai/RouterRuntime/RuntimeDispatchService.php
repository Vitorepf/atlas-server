<?php

namespace App\Services\Ai\RouterRuntime;

use App\Models\AiAtlasFlowRoute;
use App\Models\AiAtlasIntentClassification;
use App\Models\AiAtlasRouterDecision;
use App\Models\AiAtlasRuntimeDispatch;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;

class RuntimeDispatchService
{
    /**
     * Dispatch a router decision to a runtime. Meta 6 v1 NEVER executes a
     * provider, browser or external API: it only persists a simulated /
     * planned / blocked dispatch with a deterministic receipt_hash. Real
     * execution is owned by domain runtimes and tool runtime in later metas.
     */
    public function dispatch(
        AiAtlasRouterDecision $decision,
        AiAtlasFlowRoute $flowRoute,
        AiAtlasIntentClassification $intent,
    ): AiAtlasRuntimeDispatch {
        $blockers = $this->collectBlockers($decision);
        $dispatchStatus = $this->resolveDispatchStatus($decision, $blockers);
        $dispatchTarget = $flowRoute->flow_id;

        $dispatchPayload = [
            'intent_uuid' => $intent->uuid,
            'intent_type' => $intent->intent_type,
            'primary_domain' => $decision->primary_domain,
            'secondary_domains' => $decision->secondary_domains,
            'routing_mode' => $decision->routing_mode,
            'flow_id' => $flowRoute->flow_id,
            'flow_profile' => $flowRoute->flow_profile,
            'expected_capabilities' => $flowRoute->expected_capabilities,
            'required_gates' => $flowRoute->required_gates,
            'policy_required' => $decision->policy_required,
            'evidence_required' => $decision->evidence_required,
            'tool_plan_required' => $decision->tool_plan_required,
            'simulation' => true,
            'dispatch_mode' => 'v1_simulated_only',
        ];

        $dispatch = AiAtlasRuntimeDispatch::query()->create([
            'uuid' => (string) Str::uuid(),
            'router_decision_id' => $decision->id,
            'flow_route_id' => $flowRoute->id,
            'mission_id' => $decision->mission_id,
            'work_order_id' => $decision->work_order_id,
            'dispatch_target' => $dispatchTarget,
            'dispatch_status' => $dispatchStatus,
            'dispatch_payload' => $dispatchPayload,
            'evidence_refs' => null,
            'blockers' => $blockers === [] ? null : $blockers,
            'receipt_hash' => null,
        ]);

        $dispatch->receipt_hash = MissionCanonicalHash::sha256([
            'dispatch_uuid' => $dispatch->uuid,
            'router_decision_uuid' => $decision->uuid,
            'flow_route_uuid' => $flowRoute->uuid,
            'dispatch_target' => $dispatchTarget,
            'dispatch_status' => $dispatchStatus,
            'blockers' => $blockers,
        ]);
        $dispatch->save();

        return $dispatch;
    }

    /**
     * @return array<int,string>
     */
    private function collectBlockers(AiAtlasRouterDecision $decision): array
    {
        $blockers = [];
        if ($decision->routing_mode === RouterRuntimeCanon::MODE_BLOCKED) {
            $blockers[] = 'routing_mode_blocked';
        }
        $reasons = (array) ($decision->decision_reason['reasons'] ?? []);
        foreach ($reasons as $reason) {
            if (str_starts_with((string) $reason, 'clarification_needed')) {
                $blockers[] = (string) $reason;
            }
        }

        return array_values(array_unique($blockers));
    }

    /**
     * @param  array<int,string>  $blockers
     */
    private function resolveDispatchStatus(AiAtlasRouterDecision $decision, array $blockers): string
    {
        if ($blockers !== []) {
            return RouterRuntimeCanon::DISPATCH_BLOCKED;
        }
        if ($decision->policy_required || $decision->evidence_required || $decision->tool_plan_required) {
            return RouterRuntimeCanon::DISPATCH_PLANNED;
        }

        return RouterRuntimeCanon::DISPATCH_SIMULATED;
    }
}
