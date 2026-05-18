<?php

namespace App\Services\Ai\Programming\Kernel;

use App\Models\AiDomainHandoff;
use App\Models\AiDomainRuntimeRecord;
use App\Models\AiMission;
use App\Models\AiMissionCertification;
use App\Models\AiMissionEvent;
use App\Models\AiMissionEvidenceRef;
use App\Models\AiWorkOrder;
use Illuminate\Support\Facades\Schema;

class ProgrammingControlPlaneProjection
{
    public const SCHEMA = ProgrammingDomainKernelCanon::SCHEMA_CONTROL_PLANE;

    public function __construct(
        private readonly ProgrammingPolicyBridge $policy,
        private readonly ProgrammingEvidenceBridge $evidence,
        private readonly ProgrammingToolBridge $tools,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $domainId = ProgrammingDomainKernelCanon::DOMAIN_ID;

        $programmingMissionIds = $this->programmingMissionIds();
        $missionsCount = count($programmingMissionIds);
        $missionsByStatus = $this->countMissionsByStatus($programmingMissionIds);

        $workOrders = Schema::hasTable('ai_work_orders')
            ? AiWorkOrder::query()->whereIn('mission_id', $programmingMissionIds)
            : null;
        $openWorkOrders = $workOrders?->clone()->whereNotIn('status', ['completed', 'cancelled', 'failed'])->count() ?? 0;
        $totalWorkOrders = $workOrders?->clone()->count() ?? 0;

        $blockedCount = Schema::hasTable('ai_mission_events')
            ? AiMissionEvent::query()
                ->whereIn('mission_id', $programmingMissionIds)
                ->where('status_after', 'blocked')
                ->count()
            : 0;

        $certificationsBase = Schema::hasTable('ai_mission_certifications')
            ? AiMissionCertification::query()->whereIn('mission_id', $programmingMissionIds)
            : null;
        $certificationsTotal = $certificationsBase?->clone()->count() ?? 0;
        $certificationsPassed = $certificationsBase?->clone()->where('status', 'passed')->count() ?? 0;
        $certificationPassRate = $certificationsTotal > 0 ? round($certificationsPassed / $certificationsTotal, 4) : null;

        $devToForgeEscalations = Schema::hasTable('ai_domain_handoffs')
            ? AiDomainHandoff::query()
                ->where('source_domain_id', $domainId)
                ->where('target_domain_id', $domainId)
                ->where('reason', 'like', 'forge_escalation:%')
                ->count()
            : 0;

        $evidencePackCount = Schema::hasTable('ai_mission_evidence_refs')
            ? AiMissionEvidenceRef::query()->whereIn('mission_id', $programmingMissionIds)->count()
            : 0;

        $policyBlockCount = Schema::hasTable('ai_mission_events')
            ? AiMissionEvent::query()
                ->whereIn('mission_id', $programmingMissionIds)
                ->where('event_type', 'like', 'programming.adapter.policy.%')
                ->count()
            : 0;

        $runtimeRecords = Schema::hasTable('ai_domain_runtime_records')
            ? AiDomainRuntimeRecord::query()->where('domain_id', $domainId)
            : null;
        $runtimeRecordsByStatus = $runtimeRecords
            ? $runtimeRecords->clone()->selectRaw('runtime_status, COUNT(*) as total')->groupBy('runtime_status')->pluck('total', 'runtime_status')->all()
            : [];

        return [
            'schema' => self::SCHEMA,
            'generated_at' => now()->toJSON(),
            'domain_id' => $domainId,
            'bridges' => [
                'policy_runtime_available' => $this->policy->bridgeAvailable(),
                'mission_evidence_available' => $this->evidence->missionEvidenceAvailable(),
                'evidence_runtime_available' => $this->evidence->evidenceRuntimeAvailable(),
                'tool_runtime_available' => $this->tools->bridgeAvailable(),
            ],
            'programming' => [
                'open_work_orders' => $openWorkOrders,
                'total_work_orders' => $totalWorkOrders,
                'missions_total' => $missionsCount,
                'missions_by_status' => $missionsByStatus,
                'blocked_count' => $blockedCount,
                'certification_pass_rate' => $certificationPassRate,
                'certifications_total' => $certificationsTotal,
                'certifications_passed' => $certificationsPassed,
                'dev_to_forge_escalations' => $devToForgeEscalations,
                'evidence_pack_count' => $evidencePackCount,
                'policy_block_count' => $policyBlockCount,
                'runtime_records_by_status' => $runtimeRecordsByStatus,
            ],
            'capabilities' => ProgrammingDomainKernelCanon::CAPABILITIES,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function programmingMissionIds(): array
    {
        if (! Schema::hasTable('ai_missions')) {
            return [];
        }

        return AiMission::query()
            ->where('primary_domain', ProgrammingDomainKernelCanon::DOMAIN_ID)
            ->pluck('id')
            ->all();
    }

    /**
     * @param  array<int,string>  $missionIds
     * @return array<string,int>
     */
    private function countMissionsByStatus(array $missionIds): array
    {
        if (! Schema::hasTable('ai_missions') || $missionIds === []) {
            return [];
        }

        return AiMission::query()
            ->whereIn('id', $missionIds)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();
    }
}
