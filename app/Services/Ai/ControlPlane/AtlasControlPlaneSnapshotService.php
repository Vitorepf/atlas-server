<?php

namespace App\Services\Ai\ControlPlane;

use App\Services\Ai\Support\DatabaseTableAvailability;
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
        private readonly AtlasAiControlPlaneService $runtime,
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
        $runtimeIntelligenceSummary = $this->runtimeIntelligenceSummary();
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
            'runtime_intelligence_summary' => $runtimeIntelligenceSummary,
            'recent_events' => $recentEvents,
            'next_actions' => $nextActions['items'] ?? [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeIntelligenceSummary(): array
    {
        try {
            $report = $this->runtime->report(24);
        } catch (Throwable $e) {
            return [
                'status' => AtlasControlPlaneStatus::DEGRADED,
                'detail' => $e->getMessage(),
            ];
        }

        return [
            'status' => $this->runtimeStatus((string) ($report['status'] ?? AtlasControlPlaneStatus::DEGRADED)),
            'schema_version' => (string) ($report['schema_version'] ?? AtlasAiControlPlaneService::SCHEMA_VERSION),
            'summary' => $report['summary'] ?? [],
            'persistent_context' => $this->runtimeSectionSummary($report['persistent_context'] ?? []),
            'aemor' => $this->runtimeSectionSummary($report['aemor'] ?? []),
            'intelligence_factory' => $this->runtimeSectionSummary($report['intelligence_factory'] ?? []),
            'swarm_company' => $this->runtimeSectionSummary($report['swarm_company'] ?? []),
            'external_execution' => $this->runtimeSectionSummary($report['external_execution'] ?? []),
            'action_queue' => $this->runtimeActionQueue($report),
            'claim_policy' => $report['claim_policy'] ?? [],
            'hash' => $report['hash'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<int,array<string,mixed>>
     */
    private function runtimeActionQueue(array $report): array
    {
        $items = [];

        foreach (array_slice(is_array($report['blockers'] ?? null) ? $report['blockers'] : [], 0, 8) as $blocker) {
            if (! is_array($blocker)) {
                continue;
            }
            $items[] = [
                'kind' => (string) ($blocker['kind'] ?? 'runtime_blocker'),
                'status' => AtlasControlPlaneStatus::BLOCKED,
                'detail' => (string) ($blocker['detail'] ?? $blocker['reason'] ?? 'runtime blocker'),
                'target_id' => $blocker['dispatch_id'] ?? $blocker['id'] ?? null,
            ];
        }

        $external = is_array($report['external_execution'] ?? null) ? $report['external_execution'] : [];
        $externalSummary = is_array($external['summary'] ?? null) ? $external['summary'] : [];
        $pending = (int) ($externalSummary['pending_operator_review'] ?? 0);
        if ($pending > 0) {
            $items[] = [
                'kind' => 'external_operator_review',
                'status' => AtlasControlPlaneStatus::DEGRADED,
                'detail' => "{$pending} external execution mandate(s) pending operator review",
                'target_id' => null,
            ];
        }

        $factory = is_array($report['intelligence_factory'] ?? null) ? $report['intelligence_factory'] : [];
        $factorySummary = is_array($factory['summary'] ?? null) ? $factory['summary'] : [];
        $openGaps = (int) ($factorySummary['open_gaps'] ?? 0);
        if ($openGaps > 0) {
            $items[] = [
                'kind' => 'capability_gap_review',
                'status' => AtlasControlPlaneStatus::DEGRADED,
                'detail' => "{$openGaps} Intelligence Factory capability gap(s) open",
                'target_id' => null,
            ];
        }

        return array_slice($items, 0, 12);
    }

    /**
     * @return array<string,mixed>
     */
    private function runtimeSectionSummary(mixed $section): array
    {
        if (! is_array($section)) {
            return ['status' => AtlasControlPlaneStatus::MISSING, 'summary' => []];
        }

        return [
            'status' => $this->runtimeStatus((string) ($section['status'] ?? AtlasControlPlaneStatus::DEGRADED)),
            'summary' => is_array($section['summary'] ?? null) ? $section['summary'] : [],
        ];
    }

    private function runtimeStatus(string $status): string
    {
        return match ($status) {
            AtlasAiControlPlaneService::STATUS_HEALTHY => AtlasControlPlaneStatus::READY,
            AtlasAiControlPlaneService::STATUS_WATCH, 'watch' => AtlasControlPlaneStatus::DEGRADED,
            AtlasAiControlPlaneService::STATUS_BLOCKED => AtlasControlPlaneStatus::BLOCKED,
            AtlasControlPlaneStatus::READY,
            AtlasControlPlaneStatus::DEGRADED,
            AtlasControlPlaneStatus::MISSING,
            AtlasControlPlaneStatus::BLOCKED => $status,
            default => AtlasControlPlaneStatus::DEGRADED,
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function approvalsSummary(): array
    {
        $model = '\\App\\Models\\AiApprovalRequest';
        if (! DatabaseTableAvailability::has('ai_approval_requests') || ! class_exists($model)) {
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
        if (! DatabaseTableAvailability::has('ai_operator_approvals') || ! class_exists($model)) {
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
        if (DatabaseTableAvailability::has('ai_audit_events') && class_exists($auditModel)) {
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
        if (DatabaseTableAvailability::has('ai_mission_events') && class_exists($missionEventModel)) {
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
        if (! DatabaseTableAvailability::has($table) || ! class_exists($modelClass)) {
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
