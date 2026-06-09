<?php

namespace App\Services\Ai\Mission;

use App\Models\AiMission;
use App\Models\AiMissionEvent;
use App\Models\AiMissionEvidenceRef;
use App\Models\AiOperatorApproval;
use App\Models\AiWorkOrder;
use App\Services\Ai\OperatorApproval\OperatorApprovalCanon;
use App\Services\Ai\OperatorApproval\OperatorApprovalGateService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Atlas AI Autonomous Follow-Through Loop.
 *
 * Camada acima de Mission Mode. Pega uma mission planejada/running e
 * conduz o próximo ciclo: selecionar step → resolver flow → execução
 * SEGURA (simulated / handoff Dev/Forge) → registrar evidence/outcome →
 * decidir repair/blocker/certify → emitir cycle result + next_action.
 *
 * Hard rules:
 *   - NUNCA executa provider (sem side effect perigoso).
 *   - NUNCA marca COMPLETED sem MissionCertificationService PASSED.
 *   - NUNCA repete infinitamente: maxCycles é hard cap.
 *   - SEMPRE registra AiMissionEvent canônico para cada ciclo (audit trail).
 *   - SEMPRE anexa AiMissionEvidenceRef tipo receipt quando ciclo avança.
 *   - SEMPRE retorna `next_action` honesto, mesmo em estado terminal.
 *
 * Reusa: WorkOrderSelectionService (puro), MissionLifecycleService,
 *   MissionEvidenceService, MissionCertificationService, MissionCanonicalHash.
 */
class MissionFollowThroughService
{
    public const SCHEMA_RUN = 'atlas.ai.mission_follow_through.run.v1';

    public const MAX_CYCLES_HARD_CAP = 20;

    public const DEFAULT_MAX_CYCLES = 3;

    /**
     * Tabela canônica primary_domain → specialist flow_id. Mantém paridade
     * com RouterRuntimeCanon::CANON_FLOWS sem precisar importar (back-compat).
     */
    private const DOMAIN_TO_FLOW = [
        'programming' => 'atlas_dev',
        'research' => 'atlas_research',
        'finance' => 'atlas_finance',
        'marketing' => 'atlas_marketing',
        'strategy' => 'atlas_strategy',
        'cyber' => 'atlas_cyber',
        'personal_development' => 'atlas_personal_development',
        'automation' => 'atlas_automation',
        'conversation' => 'atlas_conversation',
        'general' => 'atlas_conversation',
        'operational' => 'atlas_review',
    ];

    public function __construct(
        private readonly WorkOrderSelectionService $selector,
        private readonly MissionLifecycleService $lifecycle,
        private readonly MissionEvidenceService $evidence,
        private readonly MissionCertificationService $certification,
        private readonly ?OperatorApprovalGateService $approvalGate = null,
    ) {}

    /**
     * Run a single Follow-Through cycle.
     *
     * @param  array<string,mixed>  $options  reservado para extensão futura
     */
    public function runNext(AiMission $mission, array $options = []): MissionFollowThroughResult
    {
        $cycleId = (string) Str::uuid();
        $mission->refresh();
        $statusBefore = (string) $mission->status;

        // Estado terminal → noop, retorna result honesto.
        if (in_array($statusBefore, [
            MissionLifecycleService::STATUS_COMPLETED,
            MissionLifecycleService::STATUS_FAILED,
            MissionLifecycleService::STATUS_CANCELLED,
        ], true)) {
            return $this->buildResult(
                cycleId: $cycleId,
                mission: $mission,
                statusBefore: $statusBefore,
                statusAfter: $statusBefore,
                selectedWorkOrder: null,
                selectedFlow: null,
                actionTaken: 'noop_terminal_state',
                outcome: MissionFollowThroughResult::OUTCOME_MISSION_ALREADY_TERMINAL,
                evidenceRefs: [],
                receiptHash: null,
                blockers: [],
                repairCreated: false,
                certificationStatus: null,
                certificationHash: null,
                nextAction: 'mission_is_terminal_no_action_needed',
            );
        }

        // WAITING_APPROVAL: tenta resumir automaticamente baseado em approval decidido.
        if ($statusBefore === MissionLifecycleService::STATUS_WAITING_APPROVAL) {
            $resume = $this->resumeFromWaitingApproval($cycleId, $mission, $statusBefore);
            if ($resume !== null) {
                return $resume;
            }
        }

        // BLOCKED: aguarda ação humana.
        if ($statusBefore === MissionLifecycleService::STATUS_BLOCKED) {
            return $this->buildResult(
                cycleId: $cycleId,
                mission: $mission,
                statusBefore: $statusBefore,
                statusAfter: $statusBefore,
                selectedWorkOrder: null,
                selectedFlow: null,
                actionTaken: 'noop_awaiting_human',
                outcome: MissionFollowThroughResult::OUTCOME_NOOP_INVALID_STATE,
                evidenceRefs: [],
                receiptHash: null,
                blockers: [(string) ($mission->blocker_reason ?? 'awaiting_human_input')],
                repairCreated: false,
                certificationStatus: null,
                certificationHash: null,
                nextAction: 'human_must_resolve_blocker_then_transition_repairing',
            );
        }

        // Transição automática: PLANNED → RUNNING quando o loop entra a primeira vez.
        if ($statusBefore === MissionLifecycleService::STATUS_PLANNED) {
            try {
                $this->lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING, [
                    'actor_type' => 'follow_through',
                    'cycle_id' => $cycleId,
                ]);
                $mission->refresh();
            } catch (Throwable $e) {
                return $this->blockedResult(
                    cycleId: $cycleId,
                    mission: $mission,
                    statusBefore: $statusBefore,
                    blocker: 'failed_transition_planned_to_running:'.$e->getMessage(),
                );
            }
        }

        // Mission deve estar RUNNING/REPAIRING para selecionar step.
        if (! in_array($mission->status, [
            MissionLifecycleService::STATUS_RUNNING,
            MissionLifecycleService::STATUS_REPAIRING,
            MissionLifecycleService::STATUS_CERTIFYING,
        ], true)) {
            return $this->blockedResult(
                cycleId: $cycleId,
                mission: $mission,
                statusBefore: $statusBefore,
                blocker: 'mission_status_not_actionable:'.$mission->status,
            );
        }

        // Seleciona próximo WO selecionável.
        $selectionSummary = $this->selector->summary($mission);
        $selected = $this->selector->selectNext($mission);

        if ($selected === null) {
            // Sem WO selecionável. Pode ser: (a) todos terminais → tentar certify;
            // (b) blocked → mission virou blocked; (c) sem WO algum.
            if ($selectionSummary['all_terminal']) {
                return $this->tryCertify(
                    cycleId: $cycleId,
                    mission: $mission,
                    statusBefore: $statusBefore,
                );
            }

            return $this->blockedResult(
                cycleId: $cycleId,
                mission: $mission,
                statusBefore: $statusBefore,
                blocker: 'no_selectable_work_order:'.json_encode($selectionSummary),
                outcome: MissionFollowThroughResult::OUTCOME_NO_SELECTABLE_STEP,
            );
        }

        // Tem WO selecionável → resolver flow + execução segura.
        return $this->executeCycle(
            cycleId: $cycleId,
            mission: $mission,
            statusBefore: $statusBefore,
            workOrder: $selected,
        );
    }

    /**
     * Run cycles in a loop até mission alcançar estado terminal ou
     * `maxCycles` cap. Retorna o ÚLTIMO ciclo (histórico fica em
     * AiMissionEvent canon).
     *
     * @param  array<string,mixed>  $options
     */
    public function runUntilBlockedOrComplete(
        AiMission $mission,
        int $maxCycles = self::DEFAULT_MAX_CYCLES,
        array $options = [],
    ): MissionFollowThroughResult {
        $effectiveMax = max(1, min($maxCycles, self::MAX_CYCLES_HARD_CAP));
        $last = null;

        for ($i = 0; $i < $effectiveMax; $i++) {
            $cycle = $this->runNext($mission, $options);
            $last = $cycle;

            if (! $cycle->shouldContinue()) {
                break;
            }
        }

        return $last ?? $this->buildResult(
            cycleId: (string) Str::uuid(),
            mission: $mission->refresh(),
            statusBefore: (string) $mission->status,
            statusAfter: (string) $mission->status,
            selectedWorkOrder: null,
            selectedFlow: null,
            actionTaken: 'noop_max_cycles_zero',
            outcome: MissionFollowThroughResult::OUTCOME_NOOP_INVALID_STATE,
            evidenceRefs: [],
            receiptHash: null,
            blockers: ['max_cycles_invalid'],
            repairCreated: false,
            certificationStatus: null,
            certificationHash: null,
            nextAction: 'increase_max_cycles_and_retry',
        );
    }

    /**
     * Resolve flow_id canônico para o WorkOrder selecionado.
     */
    private function resolveFlow(AiMission $mission, AiWorkOrder $workOrder): string
    {
        if (! empty($workOrder->flow_profile)) {
            return (string) $workOrder->flow_profile;
        }

        if (! empty($workOrder->domain_runtime)) {
            $mapped = self::DOMAIN_TO_FLOW[strtolower((string) $workOrder->domain_runtime)] ?? null;
            if ($mapped !== null) {
                return $this->refineProgrammingFlow($mission, $mapped);
            }
        }

        $domain = strtolower((string) ($mission->primary_domain ?? ''));
        if ($domain !== '' && isset(self::DOMAIN_TO_FLOW[$domain])) {
            return $this->refineProgrammingFlow($mission, self::DOMAIN_TO_FLOW[$domain]);
        }

        // Default safe flow.
        return 'atlas_conversation';
    }

    /**
     * Programming flows refinam entre atlas_dev (default) e atlas_forge
     * (mission_type=obra) — preserva contrato de handoff.
     */
    private function refineProgrammingFlow(AiMission $mission, string $flowId): string
    {
        if ($flowId !== 'atlas_dev') {
            return $flowId;
        }

        return $mission->mission_type === MissionFactoryService::TYPE_OBRA
            ? 'atlas_forge'
            : 'atlas_dev';
    }

    /**
     * Execute the cycle in a safe, no-side-effect way: marca WO com status
     * adequado (simulated/handoff_dev/handoff_forge), anexa evidence_ref
     * receipt, registra AiMissionEvent canônico.
     */
    private function executeCycle(
        string $cycleId,
        AiMission $mission,
        string $statusBefore,
        AiWorkOrder $workOrder,
    ): MissionFollowThroughResult {
        $flow = $this->resolveFlow($mission, $workOrder);
        $action = $this->actionForFlow($flow);
        $newStatus = $this->workOrderStatusForFlow($flow);

        $gateCheck = $this->checkOperatorApprovalGate(
            cycleId: $cycleId,
            mission: $mission,
            statusBefore: $statusBefore,
            workOrder: $workOrder,
            flow: $flow,
            action: $action,
        );
        if ($gateCheck !== null) {
            return $gateCheck;
        }

        return DB::transaction(function () use (
            $cycleId,
            $mission,
            $statusBefore,
            $workOrder,
            $flow,
            $action,
            $newStatus,
        ) {
            // Marca WO in_progress brevemente (audit), depois status final.
            $workOrder->status = 'in_progress';
            $workOrder->save();

            $receiptPayload = [
                'cycle_id' => $cycleId,
                'mission_uuid' => $mission->uuid,
                'work_order_uuid' => $workOrder->uuid,
                'flow' => $flow,
                'action' => $action,
                'status_after' => $newStatus,
            ];
            $receiptHash = MissionCanonicalHash::sha256($receiptPayload);

            // Anexa evidence_ref tipo receipt (loop produz audit trail).
            $evidenceRef = $this->evidence->attach($mission, [
                'evidence_type' => MissionEvidenceService::TYPE_RECEIPT,
                'evidence_ref' => 'follow_through_cycle:'.$cycleId,
                'work_order_id' => $workOrder->id,
                'metadata' => [
                    'cycle_id' => $cycleId,
                    'flow' => $flow,
                    'action' => $action,
                    'receipt_hash' => $receiptHash,
                ],
                'actor_type' => 'follow_through',
            ]);

            $workOrder->status = $newStatus;
            $workOrder->save();

            // Registra cycle event canônico (sem usar transition — não muda mission status).
            $this->lifecycle->recordEvent(
                $mission,
                'follow_through.cycle.completed',
                'follow_through',
                array_merge($receiptPayload, [
                    'evidence_ref_id' => $evidenceRef->id,
                    'evidence_ref_uuid' => $evidenceRef->uuid,
                ]),
                receiptHash: $receiptHash,
            );

            $mission->refresh();
            $outcome = match ($flow) {
                'atlas_forge' => MissionFollowThroughResult::OUTCOME_HANDOFF_FORGE,
                'atlas_dev', 'atlas_debug', 'atlas_review' => MissionFollowThroughResult::OUTCOME_HANDOFF_DEV,
                default => MissionFollowThroughResult::OUTCOME_SIMULATED_SAFE,
            };

            return $this->buildResult(
                cycleId: $cycleId,
                mission: $mission,
                statusBefore: $statusBefore,
                statusAfter: (string) $mission->status,
                selectedWorkOrder: $workOrder->fresh(),
                selectedFlow: $flow,
                actionTaken: $action,
                outcome: $outcome,
                evidenceRefs: [$evidenceRef],
                receiptHash: $receiptHash,
                blockers: [],
                repairCreated: false,
                certificationStatus: null,
                certificationHash: null,
                nextAction: $this->nextActionForOutcome($outcome, $mission),
            );
        });
    }

    /**
     * Tenta certificar a mission quando todos WO estão terminais.
     */
    private function tryCertify(
        string $cycleId,
        AiMission $mission,
        string $statusBefore,
    ): MissionFollowThroughResult {
        $mission->refresh();

        // Mission precisa estar em estado certifiable.
        if (! in_array($mission->status, [
            MissionLifecycleService::STATUS_RUNNING,
            MissionLifecycleService::STATUS_REPAIRING,
            MissionLifecycleService::STATUS_CERTIFYING,
        ], true)) {
            return $this->buildResult(
                cycleId: $cycleId,
                mission: $mission,
                statusBefore: $statusBefore,
                statusAfter: (string) $mission->status,
                selectedWorkOrder: null,
                selectedFlow: null,
                actionTaken: 'noop_status_not_certifiable',
                outcome: MissionFollowThroughResult::OUTCOME_NOOP_INVALID_STATE,
                evidenceRefs: [],
                receiptHash: null,
                blockers: ['mission_status_not_certifiable:'.$mission->status],
                repairCreated: false,
                certificationStatus: null,
                certificationHash: null,
                nextAction: 'transition_mission_to_running_or_certifying_before_retry',
            );
        }

        // Transit RUNNING → CERTIFYING se ainda não está.
        if ($mission->status === MissionLifecycleService::STATUS_RUNNING) {
            try {
                $this->lifecycle->transition($mission, MissionLifecycleService::STATUS_CERTIFYING, [
                    'actor_type' => 'follow_through',
                    'cycle_id' => $cycleId,
                ]);
                $mission->refresh();
            } catch (Throwable $e) {
                return $this->blockedResult(
                    cycleId: $cycleId,
                    mission: $mission,
                    statusBefore: $statusBefore,
                    blocker: 'failed_transition_certifying:'.$e->getMessage(),
                );
            }
        }

        $cert = $this->certification->certify($mission);

        if ($cert->status === MissionCertificationService::STATUS_PASSED) {
            try {
                $this->lifecycle->transition($mission, MissionLifecycleService::STATUS_COMPLETED, [
                    'actor_type' => 'follow_through',
                    'cycle_id' => $cycleId,
                    'certification_hash' => $cert->certification_hash,
                ]);
                $mission->refresh();

                return $this->buildResult(
                    cycleId: $cycleId,
                    mission: $mission,
                    statusBefore: $statusBefore,
                    statusAfter: (string) $mission->status,
                    selectedWorkOrder: null,
                    selectedFlow: null,
                    actionTaken: 'certify_and_complete',
                    outcome: MissionFollowThroughResult::OUTCOME_MISSION_CERTIFIED,
                    evidenceRefs: [],
                    receiptHash: $cert->certification_hash,
                    blockers: [],
                    repairCreated: false,
                    certificationStatus: $cert->status,
                    certificationHash: $cert->certification_hash,
                    nextAction: 'mission_completed_archive_or_open_new_one',
                );
            } catch (Throwable $e) {
                return $this->blockedResult(
                    cycleId: $cycleId,
                    mission: $mission,
                    statusBefore: $statusBefore,
                    blocker: 'failed_transition_completed:'.$e->getMessage(),
                );
            }
        }

        // Certify FAILED — mission fica CERTIFYING (lifecycle permite repair).
        $this->lifecycle->recordEvent(
            $mission,
            'follow_through.cycle.certify_failed',
            'follow_through',
            [
                'cycle_id' => $cycleId,
                'certification_id' => $cert->id,
                'certification_hash' => $cert->certification_hash,
                'missing_count' => count((array) $cert->missing_requirements),
            ],
            receiptHash: $cert->certification_hash,
        );

        return $this->buildResult(
            cycleId: $cycleId,
            mission: $mission->refresh(),
            statusBefore: $statusBefore,
            statusAfter: (string) $mission->status,
            selectedWorkOrder: null,
            selectedFlow: null,
            actionTaken: 'certify_failed',
            outcome: MissionFollowThroughResult::OUTCOME_MISSION_COMPLETED_PENDING_CERT,
            evidenceRefs: [],
            receiptHash: $cert->certification_hash,
            blockers: array_map(
                static fn (array $c): string => (string) ($c['requirement'] ?? 'unknown'),
                (array) $cert->missing_requirements,
            ),
            repairCreated: false,
            certificationStatus: $cert->status,
            certificationHash: $cert->certification_hash,
            nextAction: 'fix_missing_certification_requirements_and_retry',
        );
    }

    /**
     * Evaluate the Operator Approval Gate before executing a cycle. Retorna
     * `null` quando `allow_auto` (cycle pode prosseguir). Caso contrário
     * transita a mission para WAITING_APPROVAL/BLOCKED e retorna o result.
     */
    private function checkOperatorApprovalGate(
        string $cycleId,
        AiMission $mission,
        string $statusBefore,
        AiWorkOrder $workOrder,
        string $flow,
        string $action,
    ): ?MissionFollowThroughResult {
        if (! Schema::hasTable('ai_operator_approvals')) {
            return null; // compatibility mode: gate inert when persistence is absent.
        }

        $gate = $this->approvalGate ?? app(OperatorApprovalGateService::class);
        $decision = $gate->evaluateForFollowThrough($mission, $workOrder, $flow, $action);

        if ($decision->proceed()) {
            return null;
        }

        $blocked = $decision->blocked();
        $repairCreated = false;
        $mission->refresh();

        $targetStatus = $blocked
            ? MissionLifecycleService::STATUS_BLOCKED
            : MissionLifecycleService::STATUS_WAITING_APPROVAL;

        if (in_array($mission->status, [
            MissionLifecycleService::STATUS_RUNNING,
            MissionLifecycleService::STATUS_REPAIRING,
        ], true)) {
            try {
                $this->lifecycle->transition($mission, $targetStatus, [
                    'actor_type' => 'follow_through',
                    'cycle_id' => $cycleId,
                    'blocker_reason' => $blocked
                        ? 'operator_approval_block:'.$decision->requestedAction
                        : null,
                ]);
                $mission->refresh();
                $repairCreated = true;
            } catch (Throwable) {
                // tolerate
            }
        }

        $this->lifecycle->recordEvent(
            $mission,
            $blocked ? 'follow_through.cycle.approval_blocked' : 'follow_through.cycle.approval_required',
            'follow_through',
            [
                'cycle_id' => $cycleId,
                'work_order_uuid' => $workOrder->uuid,
                'flow' => $flow,
                'action' => $action,
                'requested_action' => $decision->requestedAction,
                'gate_mode' => $decision->gateMode,
                'risk_level' => $decision->riskLevel,
                'approval_uuid' => $decision->approval?->uuid,
                'approval_hash' => $decision->hash,
            ],
            receiptHash: $decision->hash,
        );

        $outcome = $blocked
            ? MissionFollowThroughResult::OUTCOME_BLOCKED_BY_APPROVAL
            : MissionFollowThroughResult::OUTCOME_WAITING_APPROVAL;

        $nextAction = $blocked
            ? 'operator_must_resolve_block:'.$decision->requestedAction
            : 'operator_must_decide_approval:'.((string) ($decision->approval?->uuid ?? ''));

        return $this->buildResult(
            cycleId: $cycleId,
            mission: $mission,
            statusBefore: $statusBefore,
            statusAfter: (string) $mission->status,
            selectedWorkOrder: $workOrder,
            selectedFlow: $flow,
            actionTaken: $blocked ? 'approval_blocked' : 'approval_required',
            outcome: $outcome,
            evidenceRefs: [],
            receiptHash: $decision->hash,
            blockers: $blocked ? [(string) $decision->requestedAction] : [],
            repairCreated: $repairCreated,
            certificationStatus: null,
            certificationHash: null,
            nextAction: $nextAction,
        );
    }

    /**
     * Mission em WAITING_APPROVAL: tenta resumir baseado em última approval.
     * - approved → transita para RUNNING e retorna null (caller segue com runNext normal)
     * - denied   → transita para BLOCKED e retorna blockedResult
     * - expired  → transita para BLOCKED e retorna blockedResult
     * - pending  → retorna noop_awaiting_human
     */
    private function resumeFromWaitingApproval(
        string $cycleId,
        AiMission $mission,
        string $statusBefore,
    ): ?MissionFollowThroughResult {
        if (! Schema::hasTable('ai_operator_approvals')) {
            return $this->noopAwaitingHuman($cycleId, $mission, $statusBefore);
        }

        $gate = $this->approvalGate ?? app(OperatorApprovalGateService::class);
        $gate->expireDue(); // best-effort: actualize expirations

        $approval = AiOperatorApproval::query()
            ->where('mission_id', $mission->id)
            ->orderByDesc('created_at')
            ->first();

        if ($approval === null) {
            // Sem approval associada — comportamento legado: noop awaiting human.
            return $this->noopAwaitingHuman($cycleId, $mission, $statusBefore);
        }

        $approval->refresh();

        if ($approval->status === OperatorApprovalCanon::STATUS_APPROVED) {
            try {
                $this->lifecycle->transition($mission, MissionLifecycleService::STATUS_RUNNING, [
                    'actor_type' => 'follow_through',
                    'cycle_id' => $cycleId,
                    'approval_uuid' => $approval->uuid,
                ]);
                $mission->refresh();
            } catch (Throwable $e) {
                return $this->blockedResult(
                    cycleId: $cycleId,
                    mission: $mission,
                    statusBefore: $statusBefore,
                    blocker: 'failed_resume_from_approved:'.$e->getMessage(),
                );
            }

            $this->lifecycle->recordEvent(
                $mission,
                'follow_through.cycle.approval_resumed',
                'follow_through',
                [
                    'cycle_id' => $cycleId,
                    'approval_uuid' => $approval->uuid,
                    'operator' => $approval->operator,
                ],
                receiptHash: $approval->receipt_hash,
            );

            return null; // continue with the rest of runNext
        }

        if (in_array($approval->status, [
            OperatorApprovalCanon::STATUS_DENIED,
            OperatorApprovalCanon::STATUS_EXPIRED,
            OperatorApprovalCanon::STATUS_CANCELLED,
        ], true)) {
            try {
                $this->lifecycle->transition($mission, MissionLifecycleService::STATUS_BLOCKED, [
                    'actor_type' => 'follow_through',
                    'cycle_id' => $cycleId,
                    'blocker_reason' => 'operator_approval_'.$approval->status.':'.$approval->requested_action,
                ]);
                $mission->refresh();
            } catch (Throwable) {
                // tolerate
            }

            $this->lifecycle->recordEvent(
                $mission,
                'follow_through.cycle.approval_blocked',
                'follow_through',
                [
                    'cycle_id' => $cycleId,
                    'approval_uuid' => $approval->uuid,
                    'approval_status' => $approval->status,
                ],
                receiptHash: $approval->receipt_hash,
            );

            return $this->buildResult(
                cycleId: $cycleId,
                mission: $mission,
                statusBefore: $statusBefore,
                statusAfter: (string) $mission->status,
                selectedWorkOrder: null,
                selectedFlow: null,
                actionTaken: 'approval_'.$approval->status,
                outcome: MissionFollowThroughResult::OUTCOME_BLOCKED_BY_APPROVAL,
                evidenceRefs: [],
                receiptHash: $approval->receipt_hash,
                blockers: [(string) $approval->requested_action.':'.$approval->status],
                repairCreated: true,
                certificationStatus: null,
                certificationHash: null,
                nextAction: 'operator_must_open_new_approval_or_repair',
            );
        }

        // Still pending — noop wait.
        return $this->buildResult(
            cycleId: $cycleId,
            mission: $mission,
            statusBefore: $statusBefore,
            statusAfter: $statusBefore,
            selectedWorkOrder: null,
            selectedFlow: null,
            actionTaken: 'awaiting_operator_decision',
            outcome: MissionFollowThroughResult::OUTCOME_WAITING_APPROVAL,
            evidenceRefs: [],
            receiptHash: $approval->hash,
            blockers: [],
            repairCreated: false,
            certificationStatus: null,
            certificationHash: null,
            nextAction: 'operator_must_decide_approval:'.$approval->uuid,
        );
    }

    private function noopAwaitingHuman(
        string $cycleId,
        AiMission $mission,
        string $statusBefore,
    ): MissionFollowThroughResult {
        return $this->buildResult(
            cycleId: $cycleId,
            mission: $mission,
            statusBefore: $statusBefore,
            statusAfter: $statusBefore,
            selectedWorkOrder: null,
            selectedFlow: null,
            actionTaken: 'noop_awaiting_human',
            outcome: MissionFollowThroughResult::OUTCOME_NOOP_INVALID_STATE,
            evidenceRefs: [],
            receiptHash: null,
            blockers: [(string) ($mission->blocker_reason ?? 'awaiting_human_input')],
            repairCreated: false,
            certificationStatus: null,
            certificationHash: null,
            nextAction: 'human_must_approve_to_resume',
        );
    }

    private function actionForFlow(string $flow): string
    {
        return match ($flow) {
            'atlas_dev', 'atlas_debug', 'atlas_review' => 'handoff_to_atlas_dev',
            'atlas_forge' => 'handoff_to_atlas_forge',
            default => 'safe_simulated_dispatch',
        };
    }

    private function workOrderStatusForFlow(string $flow): string
    {
        return match ($flow) {
            'atlas_dev', 'atlas_debug', 'atlas_review' => 'handoff_dev',
            'atlas_forge' => 'handoff_forge',
            default => 'simulated',
        };
    }

    private function nextActionForOutcome(string $outcome, AiMission $mission): string
    {
        return match ($outcome) {
            MissionFollowThroughResult::OUTCOME_SIMULATED_SAFE => 'collect_evidence_then_try_certify',
            MissionFollowThroughResult::OUTCOME_HANDOFF_DEV => 'await_atlas_dev_to_report_outcome',
            MissionFollowThroughResult::OUTCOME_HANDOFF_FORGE => 'await_atlas_forge_to_report_outcome',
            MissionFollowThroughResult::OUTCOME_MISSION_CERTIFIED => 'mission_completed',
            MissionFollowThroughResult::OUTCOME_MISSION_BLOCKED => 'resolve_blocker:'.((string) ($mission->blocker_reason ?? 'unspecified')),
            MissionFollowThroughResult::OUTCOME_MISSION_COMPLETED_PENDING_CERT => 'fix_missing_certification_requirements_and_retry',
            default => 'inspect_mission_state',
        };
    }

    /**
     * Marca mission como BLOCKED e produz result canônico. Não throws.
     */
    private function blockedResult(
        string $cycleId,
        AiMission $mission,
        string $statusBefore,
        string $blocker,
        string $outcome = MissionFollowThroughResult::OUTCOME_MISSION_BLOCKED,
    ): MissionFollowThroughResult {
        $repairCreated = false;
        $mission->refresh();

        // Transit RUNNING → BLOCKED se for válido. Outros estados → record event apenas.
        if (in_array($mission->status, [
            MissionLifecycleService::STATUS_RUNNING,
            MissionLifecycleService::STATUS_REPAIRING,
        ], true)) {
            try {
                $this->lifecycle->transition($mission, MissionLifecycleService::STATUS_BLOCKED, [
                    'actor_type' => 'follow_through',
                    'cycle_id' => $cycleId,
                    'blocker_reason' => Str::limit($blocker, 480, ''),
                ]);
                $mission->refresh();
                $repairCreated = true;
            } catch (Throwable) {
                // se transition não permitida (estado terminal), só registra event abaixo
            }
        }

        $this->lifecycle->recordEvent(
            $mission,
            'follow_through.cycle.blocked',
            'follow_through',
            [
                'cycle_id' => $cycleId,
                'blocker' => $blocker,
                'outcome' => $outcome,
            ],
        );

        return $this->buildResult(
            cycleId: $cycleId,
            mission: $mission,
            statusBefore: $statusBefore,
            statusAfter: (string) $mission->status,
            selectedWorkOrder: null,
            selectedFlow: null,
            actionTaken: 'block_and_record',
            outcome: $outcome,
            evidenceRefs: [],
            receiptHash: null,
            blockers: [$blocker],
            repairCreated: $repairCreated,
            certificationStatus: null,
            certificationHash: null,
            nextAction: 'human_must_resolve_blocker_then_transition_repairing',
        );
    }

    /**
     * @param  array<int,AiMissionEvidenceRef>  $evidenceRefs
     * @param  array<int,string>  $blockers
     */
    private function buildResult(
        string $cycleId,
        AiMission $mission,
        string $statusBefore,
        string $statusAfter,
        ?AiWorkOrder $selectedWorkOrder,
        ?string $selectedFlow,
        string $actionTaken,
        string $outcome,
        array $evidenceRefs,
        ?string $receiptHash,
        array $blockers,
        bool $repairCreated,
        ?string $certificationStatus,
        ?string $certificationHash,
        string $nextAction,
    ): MissionFollowThroughResult {
        $hash = MissionFollowThroughResult::buildHash(
            cycleId: $cycleId,
            missionUuid: (string) $mission->uuid,
            statusBefore: $statusBefore,
            statusAfter: $statusAfter,
            selectedWorkOrderUuid: $selectedWorkOrder?->uuid,
            selectedFlow: $selectedFlow,
            outcome: $outcome,
            receiptHash: $receiptHash,
        );

        return new MissionFollowThroughResult(
            cycleId: $cycleId,
            missionUuid: (string) $mission->uuid,
            statusBefore: $statusBefore,
            statusAfter: $statusAfter,
            selectedWorkOrder: $selectedWorkOrder,
            selectedFlow: $selectedFlow,
            actionTaken: $actionTaken,
            outcome: $outcome,
            evidenceRefs: $evidenceRefs,
            receiptHash: $receiptHash,
            blockers: $blockers,
            repairCreated: $repairCreated,
            certificationStatus: $certificationStatus,
            certificationHash: $certificationHash,
            nextAction: $nextAction,
            hash: $hash,
        );
    }

    /**
     * Snapshot do estado follow-through de uma mission. Lido pelo Control Plane.
     *
     * @return array<string,mixed>
     */
    public function snapshot(AiMission $mission): array
    {
        $mission->refresh();

        $cycleEvents = $mission->events()
            ->where('event_type', 'like', 'follow_through.cycle.%')
            ->latest('created_at')
            ->limit(20)
            ->get()
            ->reverse()
            ->values();

        $lastCycle = $cycleEvents->last();
        $lastOutcome = $lastCycle === null ? null : (array) ($lastCycle->payload ?? []);

        $blockerEvents = $cycleEvents->filter(
            static fn (AiMissionEvent $e) => $e->event_type === 'follow_through.cycle.blocked',
        );

        $selectionSummary = $this->selector->summary($mission);

        $pendingApprovals = Schema::hasTable('ai_operator_approvals')
            ? AiOperatorApproval::query()
                ->where('mission_id', $mission->id)
                ->where('status', OperatorApprovalCanon::STATUS_PENDING)
                ->orderByDesc('created_at')
                ->limit(10)
                ->get()
            : collect();

        return [
            'schema_version' => 'atlas.ai.control_plane.follow_through.v1',
            'mission_uuid' => $mission->uuid,
            'mission_status' => $mission->status,
            'cycles_count' => $cycleEvents->count(),
            'last_cycle' => $lastCycle === null
                ? null
                : [
                    'event_type' => $lastCycle->event_type,
                    'cycle_id' => $lastOutcome['cycle_id'] ?? null,
                    'flow' => $lastOutcome['flow'] ?? null,
                    'action' => $lastOutcome['action'] ?? null,
                    'status_after' => $lastOutcome['status_after'] ?? null,
                    'receipt_hash' => $lastCycle->receipt_hash,
                    'created_at' => optional($lastCycle->created_at)->toJSON(),
                ],
            'active_blockers' => $blockerEvents->map(static fn (AiMissionEvent $e) => [
                'cycle_id' => (string) ($e->payload['cycle_id'] ?? ''),
                'blocker' => (string) ($e->payload['blocker'] ?? ''),
                'created_at' => optional($e->created_at)->toJSON(),
            ])->values()->all(),
            'pending_approvals' => $pendingApprovals->map(static fn (AiOperatorApproval $a) => [
                'uuid' => $a->uuid,
                'requested_action' => $a->requested_action,
                'gate_mode' => $a->gate_mode,
                'risk_level' => $a->risk_level,
                'expires_at' => $a->expires_at?->toJSON(),
                'hash' => $a->hash,
            ])->all(),
            'work_order_summary' => $selectionSummary,
            'next_action' => $this->nextActionForOutcome(
                $this->mostRecentOutcome($cycleEvents),
                $mission,
            ),
        ];
    }

    /**
     * @param  Collection<int,AiMissionEvent>  $cycleEvents
     */
    private function mostRecentOutcome($cycleEvents): string
    {
        $last = $cycleEvents->last();
        if ($last === null) {
            return MissionFollowThroughResult::OUTCOME_NO_SELECTABLE_STEP;
        }

        return (string) ($last->payload['outcome'] ?? MissionFollowThroughResult::OUTCOME_SIMULATED_SAFE);
    }
}
