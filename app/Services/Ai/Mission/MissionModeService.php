<?php

namespace App\Services\Ai\Mission;

use App\Models\AiMission;
use App\Models\AiMissionCertification;
use Illuminate\Support\Facades\DB;

/**
 * Mission Mode · orchestrator.
 *
 * Camada acima dos specialist flows e dos services Mission Foundation. Liga
 * detecção (keyword heuristics) → criação canônica (AiMission) → decomposição
 * em objetivos → planejamento de work_orders → transição draft→planned, sem
 * jamais executar o specialist flow em si.
 *
 * Reusa 100% Mission Foundation existente — não duplica MissionLifecycle,
 * MissionFactory, ObjectiveDecomposer, WorkOrderFactory, MissionEvidence,
 * MissionCertification, MissionCanonicalHash.
 *
 * Schema:
 *   - input: prompt do operador + contexto (surface_id, primary_domain, …)
 *   - output: {@see MissionModeResult} (skipped quando trivial/task sem
 *     persistence; created quando ativou)
 *
 * Hard rules:
 *   - NUNCA cria AiMission quando MissionSignal.shouldActivateMissionMode=false
 *     (pergunta simples não vira missão pesada)
 *   - NUNCA chama provider ou specialist flow
 *   - NUNCA marca completion sem passar pelo MissionCertificationService
 *   - certify() só transita para COMPLETED se cert.status === passed
 */
class MissionModeService
{
    public function __construct(
        private readonly MissionDetectionService $detection,
        private readonly MissionFactoryService $factory,
        private readonly MissionLifecycleService $lifecycle,
        private readonly ObjectiveDecomposerService $decomposer,
        private readonly WorkOrderFactoryService $workOrders,
        private readonly MissionEvidenceService $evidence,
        private readonly MissionCertificationService $certification,
    ) {}

    /**
     * Process an inbound prompt → detect → (optionally) create + plan mission.
     *
     * @param  array<string,mixed>  $context  surface_id, primary_domain,
     *                                        context_summary, actor_type, autonomy_level (opcional)
     */
    public function processIntent(string $rawPrompt, array $context = []): MissionModeResult
    {
        $signal = $this->detection->detect($rawPrompt, $context);

        if (! $signal->shouldActivateMissionMode) {
            return MissionModeResult::skipped($signal);
        }

        return DB::transaction(function () use ($rawPrompt, $context, $signal): MissionModeResult {
            $mission = $this->factory->create($rawPrompt, [
                'mission_type' => $signal->suggestedMissionType,
                'primary_domain' => $context['primary_domain'] ?? null,
                'secondary_domains' => $context['secondary_domains'] ?? null,
                'context_summary' => $context['context_summary'] ?? null,
                'autonomy_level' => $context['autonomy_level']
                    ?? MissionFactoryService::AUTONOMY_SUGGEST,
                'actor_type' => $context['actor_type'] ?? 'mission_mode',
                'title' => $context['title'] ?? null,
            ]);

            $objectives = $this->decomposer->decompose($mission);
            $plannedWorkOrders = $this->workOrders->plan($mission);

            $this->lifecycle->transition($mission, MissionLifecycleService::STATUS_PLANNED, [
                'actor_type' => $context['actor_type'] ?? 'mission_mode',
                'objectives_count' => $objectives->count(),
                'work_orders_count' => $plannedWorkOrders->count(),
                'signal' => $signal->toArray(),
            ]);

            return MissionModeResult::created($signal, $mission->refresh(), $objectives, $plannedWorkOrders);
        });
    }

    /**
     * Anexa uma referência de trace (AiTrace.uuid) como evidence_ref do tipo
     * receipt. Chamado pelo controller depois que o Hyperflow gera o trace
     * para a interação, ligando mission ←→ trace de forma auditável.
     *
     * Idempotente por design: chamadas duplicadas para o mesmo trace geram
     * múltiplos refs (cada um com hash determinístico da URI). Caller decide
     * deduplicação.
     */
    public function associateTrace(
        AiMission $mission,
        string $traceUuid,
        ?string $receiptHash = null,
        ?string $actorType = null,
    ): void {
        $this->evidence->attach($mission, [
            'evidence_type' => MissionEvidenceService::TYPE_RECEIPT,
            'evidence_ref' => 'ai_trace:'.$traceUuid,
            'metadata' => [
                'trace_uuid' => $traceUuid,
                'receipt_hash' => $receiptHash,
            ],
            'actor_type' => $actorType ?? 'mission_mode',
        ]);
    }

    /**
     * Mark mission ready for certification, run gate, transition to COMPLETED
     * iff gate PASSED. Failure leaves mission in CERTIFYING for repair flow.
     */
    public function certify(AiMission $mission): AiMissionCertification
    {
        if ($mission->status === MissionLifecycleService::STATUS_RUNNING) {
            $this->lifecycle->transition($mission, MissionLifecycleService::STATUS_CERTIFYING, [
                'actor_type' => 'mission_mode',
            ]);
            $mission->refresh();
        }

        $certification = $this->certification->certify($mission);

        if ($certification->status === MissionCertificationService::STATUS_PASSED) {
            $mission->refresh();
            $this->lifecycle->transition($mission, MissionLifecycleService::STATUS_COMPLETED, [
                'actor_type' => 'mission_mode',
                'certification_hash' => $certification->certification_hash,
                'certification_id' => $certification->id,
            ]);
        }

        return $certification;
    }

    /**
     * Public read-only accessor for snapshotting a mission. Returns the full
     * shape commands and API endpoints serialize as JSON.
     *
     * @return array<string,mixed>
     */
    public function snapshot(AiMission $mission): array
    {
        $mission->loadMissing(['objectives', 'workOrders', 'evidenceRefs', 'events', 'certifications']);

        return [
            'schema_version' => 'atlas.ai.mission_mode_snapshot.v1',
            'mission' => [
                'id' => $mission->id,
                'uuid' => $mission->uuid,
                'title' => $mission->title,
                'raw_prompt' => $mission->raw_prompt,
                'normalized_intent' => $mission->normalized_intent,
                'mission_type' => $mission->mission_type,
                'status' => $mission->status,
                'autonomy_level' => $mission->autonomy_level,
                'risk_level' => $mission->risk_level,
                'primary_domain' => $mission->primary_domain,
                'secondary_domains' => $mission->secondary_domains,
                'blocker_reason' => $mission->blocker_reason,
                'definition_of_done' => $mission->definition_of_done,
                'evidence_pack_hash' => $mission->evidence_pack_hash,
                'certification_hash' => $mission->certification_hash,
                'completed_at' => $mission->completed_at?->toIso8601String(),
                'created_at' => $mission->created_at?->toIso8601String(),
            ],
            'objectives' => $mission->objectives->map(fn ($o) => [
                'id' => $o->id,
                'uuid' => $o->uuid,
                'title' => $o->title,
                'objective_type' => $o->objective_type,
                'priority' => $o->priority,
                'status' => $o->status,
                'success_criteria' => $o->success_criteria,
            ])->values()->all(),
            'work_orders' => $mission->workOrders->map(fn ($w) => [
                'id' => $w->id,
                'uuid' => $w->uuid,
                'objective_id' => $w->objective_id,
                'title' => $w->title,
                'status' => $w->status,
                'receipt_hash' => $w->receipt_hash,
                'expected_artifacts' => $w->expected_artifacts,
                'expected_tests' => $w->expected_tests,
            ])->values()->all(),
            'evidence_refs' => $mission->evidenceRefs->map(fn ($e) => [
                'id' => $e->id,
                'uuid' => $e->uuid,
                'evidence_type' => $e->evidence_type,
                'evidence_ref' => $e->evidence_ref,
                'evidence_hash' => $e->evidence_hash,
            ])->values()->all(),
            'events_count' => $mission->events->count(),
            'next_actions' => $this->nextActions($mission),
            'allowed_transitions' => $this->lifecycle->allowedNext((string) $mission->status),
        ];
    }

    /**
     * Compute the next operator-visible action for the mission based on its
     * lifecycle state + readiness checks. Used by Control Plane and CLI.
     *
     * @return array<string,mixed>
     */
    private function nextActions(AiMission $mission): array
    {
        $status = (string) $mission->status;

        return match ($status) {
            MissionLifecycleService::STATUS_DRAFT => [
                'action' => 'plan_mission',
                'description' => 'Decompose objectives + plan work_orders, then transition to PLANNED.',
            ],
            MissionLifecycleService::STATUS_PLANNED => [
                'action' => 'start_execution',
                'description' => 'Hand work_orders to specialist flow (Dev/Forge/specialist) and transition to RUNNING.',
            ],
            MissionLifecycleService::STATUS_RUNNING => [
                'action' => 'collect_evidence_then_certify',
                'description' => 'Attach evidence_refs for each DoD criterion, then certify().',
            ],
            MissionLifecycleService::STATUS_WAITING_APPROVAL => [
                'action' => 'wait_for_human_approval',
                'description' => 'Operator decision required before resuming.',
            ],
            MissionLifecycleService::STATUS_BLOCKED => [
                'action' => 'resolve_blocker',
                'description' => 'Resolve blocker_reason and transition to REPAIRING.',
                'blocker_reason' => $mission->blocker_reason,
            ],
            MissionLifecycleService::STATUS_REPAIRING => [
                'action' => 'resume_running',
                'description' => 'After repair, transition back to RUNNING.',
            ],
            MissionLifecycleService::STATUS_CERTIFYING => [
                'action' => 'run_certification_gate',
                'description' => 'Invoke MissionCertificationService::certify(); only PASSED can transition to COMPLETED.',
            ],
            MissionLifecycleService::STATUS_COMPLETED => [
                'action' => 'archive',
                'description' => 'Mission finished. certification_hash sealed.',
            ],
            MissionLifecycleService::STATUS_FAILED, MissionLifecycleService::STATUS_CANCELLED => [
                'action' => 'closed',
                'description' => 'Terminal state. Open a new mission to continue.',
            ],
            default => [
                'action' => 'unknown',
                'description' => 'Unknown lifecycle state.',
            ],
        };
    }
}
