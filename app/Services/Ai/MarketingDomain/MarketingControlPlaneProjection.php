<?php

namespace App\Services\Ai\MarketingDomain;

use App\Models\AiMarketingApprovalGate;
use App\Models\AiMarketingArtifact;
use App\Models\AiMarketingExperiment;
use App\Models\AiMarketingRun;

class MarketingControlPlaneProjection
{
    /**
     * @return array<string,mixed>
     */
    public function snapshot(int $limit = 20): array
    {
        return [
            'schema' => 'atlas.ai.marketing_domain.control_plane.v1',
            'runs' => $this->runsSection($limit),
            'artifacts' => $this->artifactsSection($limit),
            'experiments' => $this->experimentsSection($limit),
            'approval_gates' => $this->approvalGatesSection($limit),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runsSection(int $limit): array
    {
        $runs = AiMarketingRun::query()->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => AiMarketingRun::query()->count(),
            'by_status' => AiMarketingRun::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all(),
            'by_certification_status' => AiMarketingRun::query()
                ->whereNotNull('certification_status')
                ->selectRaw('certification_status, count(*) as total')
                ->groupBy('certification_status')
                ->pluck('total', 'certification_status')
                ->all(),
            'recent' => $runs->map(static fn (AiMarketingRun $r): array => [
                'uuid' => $r->uuid,
                'product' => $r->product,
                'status' => $r->status,
                'certification_status' => $r->certification_status,
                'certification_hash' => $r->certification_hash,
                'evidence_pack_hash' => $r->evidence_pack_hash,
                'completed_at' => $r->completed_at?->toJSON(),
            ])->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function artifactsSection(int $limit): array
    {
        $items = AiMarketingArtifact::query()->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => AiMarketingArtifact::query()->count(),
            'by_type' => AiMarketingArtifact::query()
                ->selectRaw('artifact_type, count(*) as total')
                ->groupBy('artifact_type')
                ->pluck('total', 'artifact_type')
                ->all(),
            'by_status' => AiMarketingArtifact::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all(),
            'recent' => $items->map(static fn (AiMarketingArtifact $a): array => [
                'uuid' => $a->uuid,
                'artifact_type' => $a->artifact_type,
                'title' => $a->title,
                'status' => $a->status,
                'artifact_hash' => $a->artifact_hash,
            ])->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function experimentsSection(int $limit): array
    {
        $items = AiMarketingExperiment::query()->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => AiMarketingExperiment::query()->count(),
            'by_status' => AiMarketingExperiment::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all(),
            'recent' => $items->map(static fn (AiMarketingExperiment $e): array => [
                'uuid' => $e->uuid,
                'name' => $e->name,
                'primary_metric' => $e->primary_metric,
                'status' => $e->status,
                'experiment_hash' => $e->experiment_hash,
            ])->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function approvalGatesSection(int $limit): array
    {
        $items = AiMarketingApprovalGate::query()->orderByDesc('created_at')->limit($limit)->get();

        return [
            'count' => AiMarketingApprovalGate::query()->count(),
            'by_type' => AiMarketingApprovalGate::query()
                ->selectRaw('gate_type, count(*) as total')
                ->groupBy('gate_type')
                ->pluck('total', 'gate_type')
                ->all(),
            'by_status' => AiMarketingApprovalGate::query()
                ->selectRaw('status, count(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all(),
            'recent' => $items->map(static fn (AiMarketingApprovalGate $g): array => [
                'uuid' => $g->uuid,
                'gate_type' => $g->gate_type,
                'status' => $g->status,
                'requested_action' => $g->requested_action,
                'proposed_budget' => $g->proposed_budget,
                'currency' => $g->currency,
                'policy_approval_request_id' => $g->policy_approval_request_id,
                'receipt_hash' => $g->receipt_hash,
            ])->all(),
        ];
    }
}
