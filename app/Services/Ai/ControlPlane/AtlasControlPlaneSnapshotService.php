<?php

namespace App\Services\Ai\ControlPlane;

use Illuminate\Support\Facades\Schema;
use Throwable;

class AtlasControlPlaneSnapshotService
{
    public const SCHEMA = 'atlas.ai.control_plane.snapshot.v1';

    public function __construct(
        private readonly AtlasControlPlaneMissionService $mission,
        private readonly AtlasControlPlaneDomainService $domain,
        private readonly AtlasControlPlanePolicyService $policy,
        private readonly AtlasControlPlaneEvidenceService $evidence,
        private readonly AtlasControlPlaneToolService $tool,
        private readonly AtlasControlPlaneRouterService $router,
        private readonly AtlasControlPlaneBlockerService $blockers,
        private readonly AtlasControlPlaneReadinessService $readiness,
        private readonly AtlasControlPlaneNextActionService $nextActions,
    ) {}

    /**
     * Aggregate snapshot. Tolerates absent runtimes by surfacing
     * status `missing` / `degraded` per component instead of throwing.
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $missionSummary = $this->mission->summary();
        $domainSummary = $this->domain->summary();
        $policySummary = $this->policy->summary();
        $evidenceSummary = $this->evidence->summary();
        $toolSummary = $this->tool->summary();
        $routerSummary = $this->router->summary();

        $readiness = $this->readiness->report();
        $blockersSnapshot = $this->blockers->snapshot(20);
        $nextActions = $this->nextActions->actions();

        $approvalsSummary = $this->approvalsSummary();
        $operatorApprovalsSummary = $this->operatorApprovalsSummary();
        $certificationsSummary = $this->certificationsSummary();
        $recentEvents = $this->recentEvents(20);

        $overallStatus = $readiness['status'];
        if (($blockersSnapshot['critical'] ?? 0) > 0 && $overallStatus === AtlasControlPlaneStatus::READY) {
            $overallStatus = AtlasControlPlaneStatus::BLOCKED;
        }

        return [
            'schema' => self::SCHEMA,
            'generated_at' => now()->toJSON(),
            'status' => $overallStatus,
            'readiness' => $readiness,
            'missions_summary' => $missionSummary,
            'domains_summary' => $domainSummary,
            'policies_summary' => $policySummary,
            'evidence_summary' => $evidenceSummary,
            'tools_summary' => $toolSummary,
            'router_summary' => $routerSummary,
            'approvals_summary' => $approvalsSummary,
            'operator_approvals_summary' => $operatorApprovalsSummary,
            'blockers_summary' => [
                'total' => $blockersSnapshot['total'] ?? 0,
                'critical' => $blockersSnapshot['critical'] ?? 0,
                'by_source' => $blockersSnapshot['by_source'] ?? [],
            ],
            'certifications_summary' => $certificationsSummary,
            'recent_events' => $recentEvents,
            'next_actions' => $nextActions['items'] ?? [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function approvalsSummary(): array
    {
        $model = '\\App\\Models\\AiApprovalRequest';
        if (! Schema::hasTable('ai_approval_requests') || ! class_exists($model)) {
            return ['status' => AtlasControlPlaneStatus::MISSING, 'total' => 0];
        }

        try {
            $byStatus = $model::query()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all();

            return [
                'status' => AtlasControlPlaneStatus::READY,
                'total' => (int) $model::query()->count(),
                'by_status' => $byStatus,
                'pending' => (int) ($byStatus['pending'] ?? 0),
            ];
        } catch (Throwable $e) {
            return ['status' => AtlasControlPlaneStatus::DEGRADED, 'detail' => $e->getMessage(), 'total' => 0];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function operatorApprovalsSummary(): array
    {
        $model = '\\App\\Models\\AiOperatorApproval';
        if (! Schema::hasTable('ai_operator_approvals') || ! class_exists($model)) {
            return ['status' => AtlasControlPlaneStatus::MISSING, 'total' => 0];
        }

        try {
            $byStatus = $model::query()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all();
            $byMode = $model::query()
                ->selectRaw('gate_mode, COUNT(*) as total')
                ->groupBy('gate_mode')
                ->pluck('total', 'gate_mode')
                ->all();
            $byRisk = $model::query()
                ->selectRaw('risk_level, COUNT(*) as total')
                ->groupBy('risk_level')
                ->pluck('total', 'risk_level')
                ->all();

            return [
                'status' => AtlasControlPlaneStatus::READY,
                'schema_version' => 'atlas.ai.operator_approval.v1',
                'total' => (int) $model::query()->count(),
                'pending' => (int) ($byStatus['pending'] ?? 0),
                'approved' => (int) ($byStatus['approved'] ?? 0),
                'denied' => (int) ($byStatus['denied'] ?? 0),
                'expired' => (int) ($byStatus['expired'] ?? 0),
                'auto_approved' => (int) ($byStatus['auto_approved'] ?? 0),
                'by_status' => $byStatus,
                'by_mode' => $byMode,
                'risk_distribution' => $byRisk,
            ];
        } catch (Throwable $e) {
            return ['status' => AtlasControlPlaneStatus::DEGRADED, 'detail' => $e->getMessage(), 'total' => 0];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function certificationsSummary(): array
    {
        $evidenceCertModel = '\\App\\Models\\AiCertification';
        $missionCertModel = '\\App\\Models\\AiMissionCertification';

        $summary = [
            'status' => AtlasControlPlaneStatus::READY,
            'evidence_runtime' => $this->safeStatusGroup($evidenceCertModel, 'ai_certifications'),
            'mission_foundation' => $this->safeStatusGroup($missionCertModel, 'ai_mission_certifications'),
        ];

        if ($summary['evidence_runtime']['status'] === AtlasControlPlaneStatus::MISSING
            && $summary['mission_foundation']['status'] === AtlasControlPlaneStatus::MISSING) {
            $summary['status'] = AtlasControlPlaneStatus::MISSING;
        } elseif (
            $summary['evidence_runtime']['status'] === AtlasControlPlaneStatus::MISSING
            || $summary['mission_foundation']['status'] === AtlasControlPlaneStatus::MISSING
        ) {
            $summary['status'] = AtlasControlPlaneStatus::DEGRADED;
        }

        return $summary;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function recentEvents(int $limit): array
    {
        $events = [];

        $auditModel = '\\App\\Models\\AiAuditEvent';
        if (Schema::hasTable('ai_audit_events') && class_exists($auditModel)) {
            try {
                foreach ($auditModel::query()->orderByDesc('created_at')->limit($limit)->get() as $e) {
                    $events[] = [
                        'source' => 'evidence',
                        'event_type' => $e->event_type,
                        'target_type' => $e->target_type,
                        'target_id' => $e->target_id,
                        'created_at' => optional($e->created_at)->toJSON(),
                    ];
                }
            } catch (Throwable) {
                // tolerate
            }
        }

        $missionEventModel = '\\App\\Models\\AiMissionEvent';
        if (Schema::hasTable('ai_mission_events') && class_exists($missionEventModel)) {
            try {
                foreach ($missionEventModel::query()->orderByDesc('created_at')->limit($limit)->get() as $e) {
                    $events[] = [
                        'source' => 'mission',
                        'event_type' => $e->event_type,
                        'target_type' => 'mission',
                        'target_id' => $e->mission_id,
                        'created_at' => optional($e->created_at)->toJSON(),
                    ];
                }
            } catch (Throwable) {
                // tolerate
            }
        }

        usort(
            $events,
            static fn ($a, $b): int => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')),
        );

        return array_slice($events, 0, $limit);
    }

    /**
     * @return array<string,mixed>
     */
    private function safeStatusGroup(string $modelClass, string $table): array
    {
        if (! Schema::hasTable($table) || ! class_exists($modelClass)) {
            return ['status' => AtlasControlPlaneStatus::MISSING, 'total' => 0];
        }
        try {
            $byStatus = $modelClass::query()
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status')
                ->all();

            return [
                'status' => AtlasControlPlaneStatus::READY,
                'total' => (int) $modelClass::query()->count(),
                'by_status' => $byStatus,
                'passed' => (int) ($byStatus['passed'] ?? 0),
            ];
        } catch (Throwable $e) {
            return ['status' => AtlasControlPlaneStatus::DEGRADED, 'total' => 0, 'detail' => $e->getMessage()];
        }
    }
}
