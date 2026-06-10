<?php

namespace App\Services\Ai\ProgrammingRuntime\ControlPlane;

use App\Models\AiForgeIntake;
use App\Models\AiForgeWorkPacket;
use App\Models\AiMandatoryRagGate;
use App\Models\AiMission;
use App\Models\AiMissionCertification;
use App\Models\AiRepairLoop;
use App\Models\AiRunOutcome;
use App\Models\AiTemporalCertification;
use App\Services\Ai\ProgrammingRuntime\ProgrammingRuntimeReadinessService;
use App\Services\Ai\ProgrammingRuntime\Telemetry\ProgrammingRuntimeTelemetryAggregator;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Support\AtlasSecurity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Aggregated read model for the Atlas AI Programming Runtime.
 *
 * Surfaces the operational state of Atlas Dev + Atlas Forge as a single,
 * deterministic, JSON-stable payload — useful both for operators and for
 * downstream automation. Read only: no mutation, no benchmark execution,
 * no rival comparisons. Tolerant when individual tables are missing.
 */
class ProgrammingRuntimeControlPlaneService
{
    public function __construct(
        private readonly ProgrammingRuntimeReadinessService $readinessService,
        private readonly ProgrammingRuntimeTelemetryAggregator $telemetryAggregator,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $generatedAt = CarbonImmutable::now();
        $readiness = $this->readinessService->report();
        $telemetry = $this->telemetryAggregator->aggregate();

        $activeMissions = $this->activeMissions();
        $devRuns = $this->devRunsSummary();
        $forgeObras = $this->forgeObrasSummary();
        $workPackets = $this->workPacketsSummary();
        $ragGates = $this->ragGateSummary();
        $repairLoops = $this->repairLoopSummary();
        $certificationSummary = $this->certificationSummary();
        $evidenceCompleteness = $this->evidenceCompleteness();
        $blockers = $this->blockers($readiness, $activeMissions, $ragGates, $telemetry);

        $runtimeStatus = $this->deriveRuntimeStatus($readiness, $blockers);
        $nextActions = $this->nextActions($readiness, $blockers, $ragGates, $repairLoops);

        $payload = [
            'schema_version' => ProgrammingRuntimeControlPlaneCanon::SCHEMA_VERSION,
            'generated_at' => $generatedAt->toISOString(),
            'runtime_status' => $runtimeStatus,
            'active_missions' => $activeMissions,
            'dev_runs_summary' => $devRuns,
            'forge_obras_summary' => $forgeObras,
            'work_packets_summary' => $workPackets,
            'rag_gate_summary' => $ragGates,
            'repair_loop_summary' => $repairLoops,
            'telemetry_summary' => $this->telemetryProjection($telemetry),
            'blockers' => $blockers,
            'evidence_completeness' => $evidenceCompleteness,
            'certification_summary' => $certificationSummary,
            'next_actions' => $nextActions,
            'benchmark_status' => $this->benchmarkStatus(),
            'claim_policy' => [
                'benchmark_not_run' => true,
                'rivals_compared' => false,
                'allows_external_superiority_claim' => false,
                'requires_human_authorization_to_run_benchmark' => true,
            ],
            'note' => 'Read-only Programming Runtime control plane. NOT a benchmark.',
        ];

        // Final safety: redact any string values that look like secrets. The
        // sources we read from are already provider-safe, but the contract is
        // "no secrets in the payload" — make it visibly enforced.
        return AtlasSecurity::redactArray($payload);
    }

    /**
     * @return array<string,mixed>
     */
    private function activeMissions(): array
    {
        if (! DatabaseTableAvailability::has('ai_missions')) {
            return $this->missingTable('ai_missions');
        }

        $query = AiMission::query()
            ->whereIn('status', ProgrammingRuntimeControlPlaneCanon::ACTIVE_MISSION_STATUSES);
        $count = $query->count();

        $byStatus = AiMission::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();
        ksort($byStatus);

        $top = (clone $query)
            ->orderByDesc('updated_at')
            ->limit(ProgrammingRuntimeControlPlaneCanon::RECENT_LIMIT)
            ->get()
            ->map(fn (AiMission $mission): array => [
                'id' => $mission->uuid ?? $mission->id,
                'title' => $mission->title,
                'status' => $mission->status,
                'risk_level' => $mission->risk_level,
                'primary_domain' => $mission->primary_domain,
                'current_step' => $mission->current_step,
                'blocker_reason' => $mission->blocker_reason,
                'updated_at' => $mission->updated_at?->toISOString(),
            ])
            ->all();

        return [
            'available' => true,
            'count_active' => $count,
            'count_total' => AiMission::query()->count(),
            'by_status' => $byStatus,
            'top' => $top,
        ];
    }

    /**
     * Dev runs summary: derive from `ai_run_outcomes` where flow_id is in the
     * dev set, plus from runtime telemetry. We surface counts and recent
     * runs so operators can see what shipped recently.
     *
     * @return array<string,mixed>
     */
    private function devRunsSummary(): array
    {
        if (! DatabaseTableAvailability::has('ai_run_outcomes')) {
            return $this->missingTable('ai_run_outcomes');
        }

        $devOutcomes = AiRunOutcome::query()
            ->whereIn('flow_id', ProgrammingRuntimeControlPlaneCanon::DEV_FLOW_IDS)
            ->get();

        return $this->outcomeProjection($devOutcomes, 'atlas_dev');
    }

    /**
     * @return array<string,mixed>
     */
    private function forgeObrasSummary(): array
    {
        if (! DatabaseTableAvailability::has('ai_forge_intakes')) {
            return $this->missingTable('ai_forge_intakes');
        }

        $byStatus = AiForgeIntake::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();
        ksort($byStatus);

        $byRisk = AiForgeIntake::query()
            ->selectRaw('risk_band, count(*) as c')
            ->groupBy('risk_band')
            ->pluck('c', 'risk_band')
            ->all();
        ksort($byRisk);

        $recent = AiForgeIntake::query()
            ->orderByDesc('updated_at')
            ->limit(ProgrammingRuntimeControlPlaneCanon::RECENT_LIMIT)
            ->get()
            ->map(fn (AiForgeIntake $intake): array => [
                'id' => $intake->uuid ?? $intake->id,
                'obra_title' => $intake->obra_title,
                'status' => $intake->status,
                'risk_band' => $intake->risk_band,
                'recommended_forge_mode' => $intake->recommended_forge_mode,
                'mission_id' => $intake->mission_id,
                'blocker_reason' => $intake->blocker_reason,
                'updated_at' => $intake->updated_at?->toISOString(),
            ])
            ->all();

        return [
            'available' => true,
            'count' => AiForgeIntake::query()->count(),
            'by_status' => $byStatus,
            'by_risk_band' => $byRisk,
            'recent' => $recent,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function workPacketsSummary(): array
    {
        if (! DatabaseTableAvailability::has('ai_forge_work_packets')) {
            return $this->missingTable('ai_forge_work_packets');
        }

        $byStatus = AiForgeWorkPacket::query()
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status')
            ->all();
        ksort($byStatus);

        $byRisk = AiForgeWorkPacket::query()
            ->selectRaw('risk_band, count(*) as c')
            ->groupBy('risk_band')
            ->pluck('c', 'risk_band')
            ->all();
        ksort($byRisk);

        $recent = AiForgeWorkPacket::query()
            ->orderByDesc('updated_at')
            ->limit(ProgrammingRuntimeControlPlaneCanon::RECENT_LIMIT)
            ->get()
            ->map(fn (AiForgeWorkPacket $packet): array => [
                'id' => $packet->uuid ?? $packet->id,
                'packet_id' => $packet->packet_id,
                'title' => $packet->title,
                'status' => $packet->status,
                'risk_band' => $packet->risk_band,
                'role_slot' => $packet->role_slot,
                'intake_id' => $packet->intake_id,
                'updated_at' => $packet->updated_at?->toISOString(),
            ])
            ->all();

        return [
            'available' => true,
            'count' => AiForgeWorkPacket::query()->count(),
            'by_status' => $byStatus,
            'by_risk_band' => $byRisk,
            'recent' => $recent,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function ragGateSummary(): array
    {
        if (! DatabaseTableAvailability::has('ai_mandatory_rag_gates')) {
            return $this->missingTable('ai_mandatory_rag_gates');
        }

        $gates = AiMandatoryRagGate::query()->get();
        $byStatus = $gates->groupBy('status')->map->count()->all();
        ksort($byStatus);

        $sufficiencyValues = $gates->pluck('context_sufficiency')->filter(fn ($v): bool => is_numeric($v));
        $missedRequiredTotal = $gates->sum(function ($gate): int {
            $missed = $gate->missed_required_sources;

            return is_array($missed) ? count($missed) : 0;
        });

        return [
            'available' => true,
            'count' => $gates->count(),
            'by_status' => $byStatus,
            'avg_context_sufficiency' => $sufficiencyValues->isNotEmpty()
                ? round($sufficiencyValues->avg(), 2)
                : null,
            'missed_required_total' => $missedRequiredTotal,
            'failed_closed_count' => (int) ($byStatus['failed_closed'] ?? 0),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function repairLoopSummary(): array
    {
        if (! DatabaseTableAvailability::has('ai_repair_loops')) {
            return $this->missingTable('ai_repair_loops');
        }

        $loops = AiRepairLoop::query()->get();
        $byStatus = $loops->groupBy('status')->map->count()->all();
        ksort($byStatus);
        $byClass = $loops->groupBy('failure_class')->map->count()->filter(fn ($_, $key): bool => is_string($key))->all();
        ksort($byClass);

        return [
            'available' => true,
            'count' => $loops->count(),
            'by_status' => $byStatus,
            'by_failure_class' => $byClass,
        ];
    }

    /**
     * @param  array<string,mixed>  $telemetry
     * @return array<string,mixed>
     */
    private function telemetryProjection(array $telemetry): array
    {
        return [
            'available' => ! array_key_exists('reason', $telemetry) || $telemetry['reason'] !== 'telemetry_table_missing',
            'total_events' => (int) ($telemetry['total_events'] ?? 0),
            'distinct_runs' => (int) ($telemetry['distinct_runs'] ?? 0),
            'by_flow' => $telemetry['by_flow'] ?? [],
            'by_core' => $telemetry['by_core'] ?? [],
            'by_execution_status' => $telemetry['by_execution_status'] ?? [],
            'by_rag_gate_status' => $telemetry['by_rag_gate_status'] ?? [],
            'by_certification_status' => $telemetry['by_certification_status'] ?? [],
            'duration_summary' => $telemetry['duration_summary'] ?? null,
            'cost_summary' => $telemetry['cost_summary'] ?? null,
            'reason' => $telemetry['reason'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function evidenceCompleteness(): array
    {
        if (! DatabaseTableAvailability::has('ai_run_outcomes')) {
            return $this->missingTable('ai_run_outcomes');
        }

        $outcomes = AiRunOutcome::query()->get();
        $total = $outcomes->count();
        $withEvidence = $outcomes->filter(function ($outcome): bool {
            $refs = $outcome->evidence_refs;

            return is_array($refs) && $refs !== [];
        })->count();
        $avg = $total === 0
            ? null
            : round($outcomes->avg('evidence_quality') ?? 0.0, 2);

        return [
            'available' => true,
            'outcomes_total' => $total,
            'outcomes_with_evidence' => $withEvidence,
            'outcomes_without_evidence' => max(0, $total - $withEvidence),
            'evidence_present_ratio' => $total === 0 ? null : round($withEvidence / $total, 4),
            'avg_evidence_quality' => $avg,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function certificationSummary(): array
    {
        $missionCertAvailable = DatabaseTableAvailability::has('ai_mission_certifications');
        $temporalCertAvailable = DatabaseTableAvailability::has('ai_temporal_certifications');

        $missionCertifications = $missionCertAvailable
            ? AiMissionCertification::query()
                ->selectRaw('status, count(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status')
                ->all()
            : [];
        ksort($missionCertifications);

        $temporal = null;
        if ($temporalCertAvailable) {
            /** @var AiTemporalCertification|null $latest */
            $latest = AiTemporalCertification::query()->orderByDesc('certified_at')->first();
            if ($latest !== null) {
                $temporal = [
                    'id' => $latest->id,
                    'status' => $latest->status,
                    'certified_at' => $latest->certified_at?->toISOString(),
                    'blockers' => is_array($latest->blockers) ? $latest->blockers : [],
                    'claim_policy' => is_array($latest->claim_policy) ? $latest->claim_policy : [],
                ];
            }
        }

        return [
            'available' => $missionCertAvailable || $temporalCertAvailable,
            'mission_certifications_by_status' => $missionCertifications,
            'temporal_certification' => $temporal,
        ];
    }

    /**
     * @param  array<string,mixed>  $readiness
     * @param  array<string,mixed>  $activeMissions
     * @param  array<string,mixed>  $ragGates
     * @param  array<string,mixed>  $telemetry
     * @return array<string,mixed>
     */
    private function blockers(array $readiness, array $activeMissions, array $ragGates, array $telemetry): array
    {
        $items = [];

        foreach ((array) ($readiness['blockers'] ?? []) as $blocker) {
            $items[] = [
                'source' => 'readiness',
                'severity' => $this->mapReadinessSeverity($blocker['severity'] ?? 'P2'),
                'id' => $blocker['id'] ?? 'unknown_check',
                'message' => $blocker['detail'] ?? 'readiness check failed',
                'evidence_refs' => array_values((array) ($blocker['evidence'] ?? [])),
                'remediation' => $blocker['remediation'] ?? null,
            ];
        }

        foreach ((array) ($activeMissions['top'] ?? []) as $mission) {
            if (! empty($mission['blocker_reason'])) {
                $items[] = [
                    'source' => 'mission',
                    'severity' => ProgrammingRuntimeControlPlaneCanon::SEVERITY_BLOCKER,
                    'id' => 'mission:'.(string) ($mission['id'] ?? ''),
                    'message' => (string) $mission['blocker_reason'],
                    'evidence_refs' => ['mission:'.(string) ($mission['id'] ?? '')],
                    'remediation' => 'Review mission '.($mission['title'] ?? $mission['id'] ?? '').' and resolve blocker_reason.',
                ];
            }
        }

        if (($ragGates['available'] ?? false) === true && ($ragGates['failed_closed_count'] ?? 0) > 0) {
            $items[] = [
                'source' => 'rag_gate',
                'severity' => ProgrammingRuntimeControlPlaneCanon::SEVERITY_BLOCKER,
                'id' => 'rag_gate:failed_closed',
                'message' => $ragGates['failed_closed_count'].' mandatory RAG gates are failed_closed.',
                'evidence_refs' => ['ai_mandatory_rag_gates'],
                'remediation' => 'Repromote missed sources via learning_proposal (kind=retrieval_hint) and rerun the gate.',
            ];
        }

        if (($telemetry['reason'] ?? null) === 'telemetry_table_missing') {
            $items[] = [
                'source' => 'telemetry',
                'severity' => ProgrammingRuntimeControlPlaneCanon::SEVERITY_WARN,
                'id' => 'telemetry:table_missing',
                'message' => 'Programming Runtime telemetry table is missing — runtime is not being measured.',
                'evidence_refs' => ['ai_programming_runtime_telemetry_events'],
                'remediation' => 'Run migrations; the recorder is a no-op until the table exists.',
            ];
        }

        $bySource = [];
        $bySeverity = [];
        foreach ($items as $item) {
            $bySource[$item['source']] = ($bySource[$item['source']] ?? 0) + 1;
            $bySeverity[$item['severity']] = ($bySeverity[$item['severity']] ?? 0) + 1;
        }
        ksort($bySource);
        ksort($bySeverity);

        return [
            'total' => count($items),
            'by_source' => $bySource,
            'by_severity' => $bySeverity,
            'items' => $items,
        ];
    }

    /**
     * @param  array<string,mixed>  $readiness
     * @param  array<string,mixed>  $blockers
     * @param  array<string,mixed>  $ragGates
     * @param  array<string,mixed>  $repairLoops
     * @return list<array<string,mixed>>
     */
    private function nextActions(array $readiness, array $blockers, array $ragGates, array $repairLoops): array
    {
        $actions = [];

        foreach ((array) ($readiness['next_actions'] ?? []) as $position => $action) {
            $actions[] = [
                'priority' => 'P0',
                'source' => 'readiness',
                'description' => is_string($action) ? $action : (string) ($action['description'] ?? json_encode($action)),
                'position' => $position,
            ];
        }

        if (($ragGates['avg_context_sufficiency'] ?? null) !== null
            && (float) $ragGates['avg_context_sufficiency'] < 60.0) {
            $actions[] = [
                'priority' => 'P1',
                'source' => 'rag_gate',
                'description' => 'Average RAG context_sufficiency is below 60. Audit retrieval planning and repromote missed sources.',
                'evidence_refs' => ['ai_mandatory_rag_gates'],
            ];
        }

        $repairBlocked = (int) (($repairLoops['by_status']['blocked_max_attempts'] ?? 0)
            + ($repairLoops['by_status']['blocked_no_progress'] ?? 0));
        if ($repairBlocked > 0) {
            $actions[] = [
                'priority' => 'P1',
                'source' => 'repair_loop',
                'description' => $repairBlocked.' repair loops are blocked. Escalate to human review or restructure the failing capsule.',
                'evidence_refs' => ['ai_repair_loops'],
            ];
        }

        $missionBlockerCount = (int) ($blockers['by_source']['mission'] ?? 0);
        if ($missionBlockerCount > 0) {
            $actions[] = [
                'priority' => 'P1',
                'source' => 'mission',
                'description' => $missionBlockerCount.' active missions report a blocker_reason. Resolve before declaring Programming Runtime green.',
                'evidence_refs' => ['ai_missions'],
            ];
        }

        $actions[] = [
            'priority' => 'P2',
            'source' => 'benchmark',
            'description' => 'Benchmark is not run. To unlock external superiority claims, schedule a human-authorised external rivals battery.',
            'evidence_refs' => ['docs/engineering-knowledge-base/atlas-programming-superiority-architecture.md'],
            'note' => 'control_plane_will_never_auto_run_benchmark',
        ];

        return $actions;
    }

    /**
     * @return array<string,mixed>
     */
    private function benchmarkStatus(): array
    {
        return [
            'not_run' => true,
            'last_run_at' => null,
            'rivals_compared' => false,
            'requires_human_authorization' => true,
            'authorisation_doc' => 'docs/engineering-knowledge-base/atlas-programming-superiority-roadmap.md',
        ];
    }

    /**
     * @param  array<string,mixed>  $readiness
     * @param  array<string,mixed>  $blockers
     */
    private function deriveRuntimeStatus(array $readiness, array $blockers): string
    {
        $bySeverity = (array) ($blockers['by_severity'] ?? []);
        if (($bySeverity['critical'] ?? 0) > 0 || ($bySeverity['blocker'] ?? 0) > 0) {
            return ProgrammingRuntimeControlPlaneCanon::STATUS_BLOCKED;
        }
        if (($readiness['status'] ?? null) === 'blocked') {
            return ProgrammingRuntimeControlPlaneCanon::STATUS_BLOCKED;
        }
        if (($readiness['status'] ?? null) === 'partial' || ($bySeverity['warn'] ?? 0) > 0) {
            return ProgrammingRuntimeControlPlaneCanon::STATUS_PARTIAL;
        }
        if (($readiness['status'] ?? null) === 'green') {
            return ProgrammingRuntimeControlPlaneCanon::STATUS_GREEN;
        }

        return ProgrammingRuntimeControlPlaneCanon::STATUS_PARTIAL;
    }

    private function mapReadinessSeverity(string $severity): string
    {
        return match (strtoupper($severity)) {
            'P0', 'CRITICAL' => ProgrammingRuntimeControlPlaneCanon::SEVERITY_CRITICAL,
            'P1' => ProgrammingRuntimeControlPlaneCanon::SEVERITY_BLOCKER,
            'P2', 'WARN' => ProgrammingRuntimeControlPlaneCanon::SEVERITY_WARN,
            default => ProgrammingRuntimeControlPlaneCanon::SEVERITY_INFO,
        };
    }

    /**
     * @param  Collection<int,AiRunOutcome>  $outcomes
     * @return array<string,mixed>
     */
    private function outcomeProjection(Collection $outcomes, string $coreLabel): array
    {
        $byStatus = $outcomes->groupBy('outcome_status')->map->count()->all();
        ksort($byStatus);
        $recent = $outcomes
            ->sortByDesc(fn (AiRunOutcome $outcome) => $outcome->created_at)
            ->take(ProgrammingRuntimeControlPlaneCanon::RECENT_LIMIT)
            ->values()
            ->map(fn (AiRunOutcome $outcome): array => [
                'id' => $outcome->id,
                'run_id' => $outcome->run_id,
                'flow_id' => $outcome->flow_id,
                'outcome_status' => $outcome->outcome_status,
                'evidence_quality' => $outcome->evidence_quality,
                'retrieval_quality' => $outcome->retrieval_quality,
                'execution_quality' => $outcome->execution_quality,
                'created_at' => $outcome->created_at?->toISOString(),
            ])
            ->all();

        return [
            'available' => true,
            'count' => $outcomes->count(),
            'by_status' => $byStatus,
            'avg_execution_quality' => $outcomes->isEmpty() ? null : round($outcomes->avg('execution_quality') ?? 0.0, 2),
            'avg_retrieval_quality' => $outcomes->isEmpty() ? null : round($outcomes->avg('retrieval_quality') ?? 0.0, 2),
            'recent' => $recent,
            'core_label' => $coreLabel,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function missingTable(string $table): array
    {
        return [
            'available' => false,
            'reason' => 'table_missing',
            'table' => $table,
        ];
    }
}
