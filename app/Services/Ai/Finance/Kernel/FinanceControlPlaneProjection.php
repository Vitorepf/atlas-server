<?php

namespace App\Services\Ai\Finance\Kernel;

use App\Models\AiDomainHandoff;
use App\Models\AiDomainRuntimeRecord;
use App\Models\AiMission;
use App\Models\AiMissionCertification;
use App\Models\AiMissionEvent;
use App\Models\AiMissionEvidenceRef;
use App\Models\AiWorkOrder;
use App\Services\Ai\Support\DatabaseTableAvailability;

class FinanceControlPlaneProjection
{
    public const SCHEMA = FinanceDomainCanon::SCHEMA_CONTROL_PLANE;

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $domainId = FinanceDomainCanon::DOMAIN_ID;
        $missionIds = $this->financeMissionIds();

        return [
            'schema' => self::SCHEMA,
            'generated_at' => now()->toJSON(),
            'domain_id' => $domainId,
            'invariants' => [
                'live_trading_blocked_default' => FinanceDomainCanon::liveTradingBlocked(),
                'forbidden_actions' => FinanceDomainCanon::FORBIDDEN_ACTIONS,
                'auto_rebalance_allowed' => false,
                'broker_execution_allowed' => false,
            ],
            'finance' => [
                'missions_total' => count($missionIds),
                'open_work_orders' => $this->countWorkOrders($missionIds, true),
                'total_work_orders' => $this->countWorkOrders($missionIds, false),
                'evidence_pack_count' => DatabaseTableAvailability::has('ai_mission_evidence_refs')
                    ? AiMissionEvidenceRef::query()->whereIn('mission_id', $missionIds)->count()
                    : 0,
                'certifications_total' => $this->countCertifications($missionIds, null),
                'certifications_passed' => $this->countCertifications($missionIds, 'passed'),
                'certification_pass_rate' => $this->certificationPassRate($missionIds),
                'handoffs_total' => DatabaseTableAvailability::has('ai_domain_handoffs')
                    ? AiDomainHandoff::query()->where('source_domain_id', $domainId)->count()
                    : 0,
                'live_trade_block_events' => DatabaseTableAvailability::has('ai_mission_events')
                    ? AiMissionEvent::query()
                        ->whereIn('mission_id', $missionIds)
                        ->where('event_type', 'finance.runtime.live_trade_blocked')
                        ->count()
                    : 0,
                'compliance_block_events' => DatabaseTableAvailability::has('ai_mission_events')
                    ? AiMissionEvent::query()
                        ->whereIn('mission_id', $missionIds)
                        ->where('event_type', 'finance.runtime.compliance_block')
                        ->count()
                    : 0,
                'runtime_records_by_status' => $this->runtimeRecordsByStatus($domainId),
            ],
            'capabilities' => FinanceDomainCanon::CAPABILITIES,
            'delivery_types' => FinanceDomainCanon::DELIVERY_TYPES,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function financeMissionIds(): array
    {
        if (! DatabaseTableAvailability::has('ai_missions')) {
            return [];
        }

        return AiMission::query()
            ->where('primary_domain', FinanceDomainCanon::DOMAIN_ID)
            ->pluck('id')
            ->all();
    }

    /**
     * @param  array<int,string>  $missionIds
     */
    private function countWorkOrders(array $missionIds, bool $openOnly): int
    {
        if (! DatabaseTableAvailability::has('ai_work_orders') || $missionIds === []) {
            return 0;
        }
        $q = AiWorkOrder::query()->whereIn('mission_id', $missionIds);
        if ($openOnly) {
            $q->whereNotIn('status', ['completed', 'cancelled', 'failed']);
        }

        return $q->count();
    }

    /**
     * @param  array<int,string>  $missionIds
     */
    private function countCertifications(array $missionIds, ?string $status): int
    {
        if (! DatabaseTableAvailability::has('ai_mission_certifications') || $missionIds === []) {
            return 0;
        }
        $q = AiMissionCertification::query()->whereIn('mission_id', $missionIds);
        if ($status !== null) {
            $q->where('status', $status);
        }

        return $q->count();
    }

    /**
     * @param  array<int,string>  $missionIds
     */
    private function certificationPassRate(array $missionIds): ?float
    {
        $total = $this->countCertifications($missionIds, null);
        if ($total === 0) {
            return null;
        }
        $passed = $this->countCertifications($missionIds, 'passed');

        return round($passed / $total, 4);
    }

    /**
     * @return array<string,int>
     */
    private function runtimeRecordsByStatus(string $domainId): array
    {
        if (! DatabaseTableAvailability::has('ai_domain_runtime_records')) {
            return [];
        }

        return AiDomainRuntimeRecord::query()
            ->where('domain_id', $domainId)
            ->selectRaw('runtime_status, COUNT(*) as total')
            ->groupBy('runtime_status')
            ->pluck('total', 'runtime_status')
            ->all();
    }
}
