<?php

namespace App\Services\Ai\RouterRuntime;

use App\Models\AiAtlasDecisionReceipt;
use App\Models\AiAtlasRouterDecision;
use App\Models\AiAtlasRuntimeDispatch;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;

class DecisionReceiptService
{
    public function recordRouterDecision(AiAtlasRouterDecision $decision): AiAtlasDecisionReceipt
    {
        $summary = [
            'router_decision_uuid' => $decision->uuid,
            'primary_domain' => $decision->primary_domain,
            'secondary_domains' => $decision->secondary_domains,
            'routing_mode' => $decision->routing_mode,
            'policy_required' => $decision->policy_required,
            'evidence_required' => $decision->evidence_required,
            'tool_plan_required' => $decision->tool_plan_required,
            'status' => $decision->status,
            'reason' => $decision->decision_reason,
        ];

        return AiAtlasDecisionReceipt::query()->create([
            'uuid' => (string) Str::uuid(),
            'router_decision_id' => $decision->id,
            'runtime_dispatch_id' => null,
            'receipt_type' => RouterRuntimeCanon::RECEIPT_ROUTER_DECISION,
            'decision_summary' => $summary,
            'evidence_refs' => null,
            'policy_refs' => $decision->policy_required
                ? ['policy_gate_required' => true]
                : null,
            'receipt_hash' => MissionCanonicalHash::sha256($summary),
        ]);
    }

    public function recordRuntimeDispatch(
        AiAtlasRuntimeDispatch $dispatch,
        AiAtlasRouterDecision $decision,
    ): AiAtlasDecisionReceipt {
        $summary = [
            'dispatch_uuid' => $dispatch->uuid,
            'router_decision_uuid' => $decision->uuid,
            'dispatch_target' => $dispatch->dispatch_target,
            'dispatch_status' => $dispatch->dispatch_status,
            'blockers' => $dispatch->blockers,
            'primary_domain' => $decision->primary_domain,
            'routing_mode' => $decision->routing_mode,
        ];

        return AiAtlasDecisionReceipt::query()->create([
            'uuid' => (string) Str::uuid(),
            'router_decision_id' => $decision->id,
            'runtime_dispatch_id' => $dispatch->id,
            'receipt_type' => RouterRuntimeCanon::RECEIPT_RUNTIME_DISPATCH,
            'decision_summary' => $summary,
            'evidence_refs' => $dispatch->evidence_refs,
            'policy_refs' => $decision->policy_required
                ? ['policy_gate_required' => true]
                : null,
            'receipt_hash' => MissionCanonicalHash::sha256($summary),
        ]);
    }
}
