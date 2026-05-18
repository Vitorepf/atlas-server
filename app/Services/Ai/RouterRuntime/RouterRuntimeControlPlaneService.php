<?php

namespace App\Services\Ai\RouterRuntime;

use App\Models\AiAtlasDecisionReceipt;
use App\Models\AiAtlasFlowRoute;
use App\Models\AiAtlasIntentClassification;
use App\Models\AiAtlasRouterDecision;
use App\Models\AiAtlasRuntimeDispatch;

class RouterRuntimeControlPlaneService
{
    /**
     * Aggregated read-only snapshot of the router/runtime plane. Returns a
     * stable JSON shape consumable by Desktop, REST and downstream IAs.
     *
     * @return array<string,mixed>
     */
    public function snapshot(int $limitRecent = 20): array
    {
        return [
            'schema' => 'atlas.ai.router_runtime.control_plane.v1',
            'classifications' => $this->classificationsSection($limitRecent),
            'decisions' => $this->decisionsSection($limitRecent),
            'routes' => $this->routesSection($limitRecent),
            'dispatches' => $this->dispatchesSection($limitRecent),
            'receipts' => $this->receiptsSection($limitRecent),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function classificationsSection(int $limit): array
    {
        $items = AiAtlasIntentClassification::query()->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => AiAtlasIntentClassification::query()->count(),
            'by_intent_type' => AiAtlasIntentClassification::query()
                ->selectRaw('intent_type, count(*) as total')
                ->groupBy('intent_type')
                ->pluck('total', 'intent_type')
                ->all(),
            'recent' => $items->map(static fn (AiAtlasIntentClassification $c): array => [
                'uuid' => $c->uuid,
                'intent_type' => $c->intent_type,
                'confidence' => (float) ($c->confidence ?? 0.0),
                'ambiguity_score' => (float) ($c->ambiguity_score ?? 0.0),
                'created_at' => $c->created_at?->toJSON(),
            ])->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decisionsSection(int $limit): array
    {
        $items = AiAtlasRouterDecision::query()->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => AiAtlasRouterDecision::query()->count(),
            'by_primary_domain' => AiAtlasRouterDecision::query()
                ->selectRaw('primary_domain, count(*) as total')
                ->groupBy('primary_domain')
                ->pluck('total', 'primary_domain')
                ->all(),
            'by_routing_mode' => AiAtlasRouterDecision::query()
                ->selectRaw('routing_mode, count(*) as total')
                ->groupBy('routing_mode')
                ->pluck('total', 'routing_mode')
                ->all(),
            'recent' => $items->map(static fn (AiAtlasRouterDecision $d): array => [
                'uuid' => $d->uuid,
                'primary_domain' => $d->primary_domain,
                'routing_mode' => $d->routing_mode,
                'policy_required' => (bool) $d->policy_required,
                'evidence_required' => (bool) $d->evidence_required,
                'tool_plan_required' => (bool) $d->tool_plan_required,
                'receipt_hash' => $d->receipt_hash,
                'created_at' => $d->created_at?->toJSON(),
            ])->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function routesSection(int $limit): array
    {
        $items = AiAtlasFlowRoute::query()->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => AiAtlasFlowRoute::query()->count(),
            'by_flow_id' => AiAtlasFlowRoute::query()
                ->selectRaw('flow_id, count(*) as total')
                ->groupBy('flow_id')
                ->pluck('total', 'flow_id')
                ->all(),
            'recent' => $items->map(static fn (AiAtlasFlowRoute $r): array => [
                'uuid' => $r->uuid,
                'flow_id' => $r->flow_id,
                'flow_profile' => $r->flow_profile,
                'runtime_mode' => $r->runtime_mode,
                'required_gate_count' => is_array($r->required_gates) ? count($r->required_gates) : 0,
                'expected_capability_count' => is_array($r->expected_capabilities) ? count($r->expected_capabilities) : 0,
            ])->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function dispatchesSection(int $limit): array
    {
        $items = AiAtlasRuntimeDispatch::query()->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => AiAtlasRuntimeDispatch::query()->count(),
            'by_status' => AiAtlasRuntimeDispatch::query()
                ->selectRaw('dispatch_status, count(*) as total')
                ->groupBy('dispatch_status')
                ->pluck('total', 'dispatch_status')
                ->all(),
            'by_target' => AiAtlasRuntimeDispatch::query()
                ->selectRaw('dispatch_target, count(*) as total')
                ->groupBy('dispatch_target')
                ->pluck('total', 'dispatch_target')
                ->all(),
            'recent' => $items->map(static fn (AiAtlasRuntimeDispatch $d): array => [
                'uuid' => $d->uuid,
                'dispatch_target' => $d->dispatch_target,
                'dispatch_status' => $d->dispatch_status,
                'receipt_hash' => $d->receipt_hash,
                'blocker_count' => is_array($d->blockers) ? count($d->blockers) : 0,
            ])->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function receiptsSection(int $limit): array
    {
        $items = AiAtlasDecisionReceipt::query()->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => AiAtlasDecisionReceipt::query()->count(),
            'by_type' => AiAtlasDecisionReceipt::query()
                ->selectRaw('receipt_type, count(*) as total')
                ->groupBy('receipt_type')
                ->pluck('total', 'receipt_type')
                ->all(),
            'recent' => $items->map(static fn (AiAtlasDecisionReceipt $r): array => [
                'uuid' => $r->uuid,
                'receipt_type' => $r->receipt_type,
                'receipt_hash' => $r->receipt_hash,
                'created_at' => $r->created_at?->toJSON(),
            ])->all(),
        ];
    }
}
