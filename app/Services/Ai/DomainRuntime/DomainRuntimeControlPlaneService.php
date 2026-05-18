<?php

namespace App\Services\Ai\DomainRuntime;

use App\Models\AiDomainHandoff;
use App\Models\AiDomainManifest;
use App\Models\AiDomainRuntimeRecord;

class DomainRuntimeControlPlaneService
{
    public const SCHEMA = 'atlas.ai.domain_runtime.control_plane.v1';

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $manifests = AiDomainManifest::query()->orderBy('domain_id')->get();
        $runtimeRecords = AiDomainRuntimeRecord::query()->orderBy('created_at')->get();
        $handoffs = AiDomainHandoff::query()->orderBy('created_at')->get();

        $manifestsPayload = $manifests->map(function (AiDomainManifest $manifest): array {
            $latestAssessment = $manifest->maturityAssessments()->latest('created_at')->first();

            return [
                'id' => $manifest->id,
                'uuid' => $manifest->uuid,
                'domain_id' => $manifest->domain_id,
                'name' => $manifest->name,
                'status' => $manifest->status,
                'maturity_stage' => $manifest->maturity_stage,
                'owner' => $manifest->owner,
                'manifest_hash' => $manifest->manifest_hash,
                'counters' => [
                    'capabilities' => $manifest->capabilities()->count(),
                    'runtime_records' => $manifest->runtimeRecords()->count(),
                    'maturity_assessments' => $manifest->maturityAssessments()->count(),
                ],
                'latest_maturity_assessment' => $latestAssessment ? [
                    'maturity_stage' => $latestAssessment->maturity_stage,
                    'status' => $latestAssessment->status,
                    'assessment_hash' => $latestAssessment->assessment_hash,
                    'assessed_at' => optional($latestAssessment->assessed_at)->toJSON(),
                ] : null,
            ];
        })->all();

        $capabilities = collect();
        foreach ($manifests as $manifest) {
            foreach ($manifest->capabilities as $capability) {
                $capabilities->push([
                    'capability_id' => $capability->capability_id,
                    'domain_id' => $manifest->domain_id,
                    'risk_level' => $capability->risk_level,
                    'maturity_level' => $capability->maturity_level,
                    'status' => $capability->status,
                ]);
            }
        }

        return [
            'schema' => self::SCHEMA,
            'generated_at' => now()->toJSON(),
            'summary' => [
                'manifests' => $manifests->count(),
                'capabilities' => $capabilities->count(),
                'runtime_records' => $runtimeRecords->count(),
                'handoffs' => $handoffs->count(),
            ],
            'manifests' => $manifestsPayload,
            'capabilities' => $capabilities->all(),
            'runtime_records' => $runtimeRecords->map(static fn (AiDomainRuntimeRecord $r): array => [
                'id' => $r->id,
                'uuid' => $r->uuid,
                'domain_id' => $r->domain_id,
                'mission_id' => $r->mission_id,
                'work_order_id' => $r->work_order_id,
                'runtime_status' => $r->runtime_status,
                'receipt_hash' => $r->receipt_hash,
            ])->all(),
            'handoffs' => $handoffs->map(static fn (AiDomainHandoff $h): array => [
                'id' => $h->id,
                'uuid' => $h->uuid,
                'source_domain_id' => $h->source_domain_id,
                'target_domain_id' => $h->target_domain_id,
                'status' => $h->status,
                'receipt_hash' => $h->receipt_hash,
                'reason' => $h->reason,
            ])->all(),
        ];
    }
}
