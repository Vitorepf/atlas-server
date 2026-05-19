<?php

namespace App\Services\Ai\OperatorApproval;

use App\Models\AiMission;
use App\Models\AiOperatorApproval;
use App\Models\AiWorkOrder;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Atlas AI Operator Approval Gate Service.
 *
 * Camada de governança acima de Mission/Follow-Through/Hyperflow. Decide:
 *   - allow_auto              → Atlas pode agir sozinho
 *   - require_confirmation    → operador precisa confirmar (yes/no)
 *   - require_review          → operador precisa revisar (mais contexto)
 *   - block                   → ação proibida mesmo com aprovação
 *   - escalate_to_forge       → handoff para Atlas Forge
 *
 * Hard rules:
 *   - SEMPRE registra `AiOperatorApproval` quando approval_required=true.
 *   - SEMPRE produz `hash` determinístico sobre os campos canônicos.
 *   - SEMPRE produz `receipt_hash` ao registrar decisão (approve/deny).
 *   - NUNCA vaza raw text sensível: payload original é sanitizado em `options`/`reason`.
 *   - NUNCA permite decidir aprovação já decidida (idempotência).
 *   - NUNCA permite resume sem approval `approved`.
 *
 * Reusa: OperatorApprovalRiskPolicy, MissionCanonicalHash, AiOperatorApproval.
 */
class OperatorApprovalGateService
{
    /** Limit raw reason text written to DB. */
    private const REASON_MAX_LEN = 480;

    /** Limit raw operator note written to DB. */
    private const NOTE_MAX_LEN = 480;

    public function __construct(
        private readonly OperatorApprovalRiskPolicy $policy,
    ) {}

    /**
     * Evaluate a request. Persists a row when approval_required.
     *
     * Expected $request shape:
     *   [
     *     'requested_action'  => 'mission.handoff_forge',          // required
     *     'risk_level'        => 'medium',                          // optional, default low
     *     'mission_id'        => uuid|null,
     *     'work_order_id'     => uuid|null,
     *     'trace_id'          => string|null,
     *     'job_id'            => string|null,
     *     'reason'            => 'human readable reason',           // optional
     *     'options'           => ['approve','deny'] or [...],        // optional
     *     'evidence_refs'     => [uuid, uuid, ...] or [{type,ref}], // optional
     *     'context'           => [autonomy_level, evidence_count, ...],
     *     'expires_in_minutes'=> 60,
     *   ]
     *
     * @param  array<string,mixed>  $request
     */
    public function evaluate(array $request): OperatorApprovalDecision
    {
        $action = trim((string) ($request['requested_action'] ?? ''));
        if ($action === '') {
            throw new InvalidArgumentException('requested_action is required');
        }

        $requestedRisk = strtolower(trim((string) ($request['risk_level'] ?? OperatorApprovalCanon::RISK_LOW)));
        if (! OperatorApprovalCanon::isValidRisk($requestedRisk)) {
            $requestedRisk = OperatorApprovalCanon::RISK_LOW;
        }

        $context = (array) ($request['context'] ?? []);
        $policyResult = $this->policy->resolve($action, $requestedRisk, $context);
        $gateMode = $policyResult['gate_mode'];
        $riskLevel = $policyResult['risk_level'];
        $reasons = $policyResult['reasons'];

        // If gate would normally require operator interaction, but there's a
        // recent APPROVED + unconsumed approval matching (mission, action),
        // consume it and treat as allow_auto. Trail recorded by consumed_at.
        if ($gateMode !== OperatorApprovalCanon::MODE_ALLOW_AUTO
            && $gateMode !== OperatorApprovalCanon::MODE_BLOCK
            && ! empty($request['mission_id'])) {
            $reusable = AiOperatorApproval::query()
                ->where('mission_id', $request['mission_id'])
                ->where('requested_action', $action)
                ->where('status', OperatorApprovalCanon::STATUS_APPROVED)
                ->whereNull('consumed_at')
                ->orderByDesc('decided_at')
                ->first();
            if ($reusable !== null) {
                $reusable->consumed_at = Carbon::now();
                $reusable->save();
                $reasons[] = 'reused_existing_approval:'.$reusable->uuid;

                return new OperatorApprovalDecision(
                    requestedAction: $action,
                    gateMode: OperatorApprovalCanon::MODE_ALLOW_AUTO,
                    riskLevel: $riskLevel,
                    approvalRequired: false,
                    reason: 'consumed_existing_approval:'.$reusable->uuid,
                    reasons: $reasons,
                    options: [],
                    evidenceRefs: (array) ($reusable->evidence_refs ?? []),
                    approval: $reusable,
                    hash: $reusable->hash,
                );
            }
        }

        $approvalRequired = $gateMode !== OperatorApprovalCanon::MODE_ALLOW_AUTO;
        $reasonText = $this->sanitizeReason((string) ($request['reason'] ?? $this->defaultReason($action, $gateMode)));
        $options = $this->resolveOptions((array) ($request['options'] ?? []), $gateMode);
        $evidenceRefs = $this->sanitizeEvidenceRefs((array) ($request['evidence_refs'] ?? []));
        $expiresAt = $this->resolveExpiry($gateMode, $request['expires_in_minutes'] ?? null);

        $hashPayload = [
            'requested_action' => $action,
            'risk_level' => $riskLevel,
            'gate_mode' => $gateMode,
            'mission_id' => $request['mission_id'] ?? null,
            'work_order_id' => $request['work_order_id'] ?? null,
            'trace_id' => $request['trace_id'] ?? null,
            'job_id' => $request['job_id'] ?? null,
            'options' => $options,
            'evidence_refs' => $evidenceRefs,
            'expires_at' => $expiresAt?->toIso8601String(),
        ];
        $hash = MissionCanonicalHash::sha256($hashPayload);

        $approval = null;
        if ($approvalRequired) {
            $approval = AiOperatorApproval::query()->create([
                'uuid' => (string) Str::uuid(),
                'mission_id' => $request['mission_id'] ?? null,
                'work_order_id' => $request['work_order_id'] ?? null,
                'trace_id' => $request['trace_id'] ?? null,
                'job_id' => $request['job_id'] ?? null,
                'requested_action' => $action,
                'risk_level' => $riskLevel,
                'gate_mode' => $gateMode,
                'approval_required' => true,
                'reason' => $reasonText,
                'options' => $options,
                'status' => $this->initialStatusForMode($gateMode),
                'operator_decision' => null,
                'operator' => null,
                'operator_note' => null,
                'expires_at' => $expiresAt,
                'decided_at' => null,
                'evidence_refs' => $evidenceRefs,
                'receipt_hash' => null,
                'hash' => $hash,
            ]);
        }

        return new OperatorApprovalDecision(
            requestedAction: $action,
            gateMode: $gateMode,
            riskLevel: $riskLevel,
            approvalRequired: $approvalRequired,
            reason: $reasonText,
            reasons: $reasons,
            options: $options,
            evidenceRefs: $evidenceRefs,
            approval: $approval,
            hash: $hash,
        );
    }

    /**
     * Convenience helper for Follow-Through cycles. Mapeia (flow, action) → requested_action canônico
     * + risk_level derivado de mission. Retorna decisão.
     */
    public function evaluateForFollowThrough(
        AiMission $mission,
        AiWorkOrder $workOrder,
        string $flow,
        string $cycleAction,
    ): OperatorApprovalDecision {
        $requestedAction = match ($cycleAction) {
            'handoff_to_atlas_forge' => OperatorApprovalCanon::ACTION_MISSION_HANDOFF_FORGE,
            'handoff_to_atlas_dev' => OperatorApprovalCanon::ACTION_MISSION_HANDOFF_DEV,
            'safe_simulated_dispatch' => OperatorApprovalCanon::ACTION_MISSION_SIMULATED,
            default => 'mission.cycle:'.$cycleAction,
        };

        return $this->evaluate([
            'requested_action' => $requestedAction,
            'risk_level' => (string) ($mission->risk_level ?? OperatorApprovalCanon::RISK_LOW),
            'mission_id' => $mission->id,
            'work_order_id' => $workOrder->id,
            'reason' => sprintf('follow_through cycle flow=%s action=%s', $flow, $cycleAction),
            'context' => [
                'autonomy_level' => (string) ($mission->autonomy_level ?? ''),
                'mission_type' => (string) ($mission->mission_type ?? ''),
                'primary_domain' => (string) ($mission->primary_domain ?? ''),
                'flow' => $flow,
            ],
            'options' => [OperatorApprovalCanon::DECISION_APPROVE, OperatorApprovalCanon::DECISION_DENY],
            'evidence_refs' => [],
        ]);
    }

    /**
     * Approve a pending approval. Idempotent — throws if already decided.
     */
    public function approve(AiOperatorApproval $approval, string $operator, ?string $note = null): AiOperatorApproval
    {
        return $this->decide($approval, OperatorApprovalCanon::DECISION_APPROVE, $operator, $note);
    }

    /**
     * Deny a pending approval. Idempotent — throws if already decided.
     */
    public function deny(AiOperatorApproval $approval, string $operator, ?string $note = null): AiOperatorApproval
    {
        return $this->decide($approval, OperatorApprovalCanon::DECISION_DENY, $operator, $note);
    }

    /**
     * Expire all approvals with expires_at in the past. Returns count expired.
     */
    public function expireDue(?Carbon $now = null): int
    {
        $now ??= Carbon::now();
        $pending = AiOperatorApproval::query()
            ->where('status', OperatorApprovalCanon::STATUS_PENDING)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->get();

        $count = 0;
        foreach ($pending as $approval) {
            $approval->status = OperatorApprovalCanon::STATUS_EXPIRED;
            $approval->decided_at = $now;
            $approval->receipt_hash = MissionCanonicalHash::sha256([
                'uuid' => $approval->uuid,
                'requested_action' => $approval->requested_action,
                'status' => OperatorApprovalCanon::STATUS_EXPIRED,
                'decided_at' => $now->toJSON(),
            ]);
            $approval->save();
            $count++;
        }

        return $count;
    }

    /**
     * Read-only: get a pending approval for (mission, action). Used by
     * Follow-Through to know if resume is allowed without re-evaluating.
     */
    public function latestForMission(string $missionId, ?string $requestedAction = null): ?AiOperatorApproval
    {
        $query = AiOperatorApproval::query()
            ->where('mission_id', $missionId)
            ->orderByDesc('created_at');

        if ($requestedAction !== null) {
            $query->where('requested_action', $requestedAction);
        }

        return $query->first();
    }

    /**
     * @return array<string,int>
     */
    public function statusCounts(): array
    {
        return AiOperatorApproval::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(static fn ($v): int => (int) $v)
            ->all();
    }

    /**
     * Aggregated snapshot for Control Plane.
     *
     * @return array<string,mixed>
     */
    public function controlPlaneSnapshot(int $limitRecent = 20): array
    {
        $this->expireDue(); // best-effort: refresh expirations before snapshot.

        $byStatus = $this->statusCounts();
        $byMode = AiOperatorApproval::query()
            ->selectRaw('gate_mode, count(*) as total')
            ->groupBy('gate_mode')
            ->pluck('total', 'gate_mode')
            ->map(static fn ($v): int => (int) $v)
            ->all();
        $byRisk = AiOperatorApproval::query()
            ->selectRaw('risk_level, count(*) as total')
            ->groupBy('risk_level')
            ->pluck('total', 'risk_level')
            ->map(static fn ($v): int => (int) $v)
            ->all();

        $pending = AiOperatorApproval::query()
            ->where('status', OperatorApprovalCanon::STATUS_PENDING)
            ->orderByDesc('created_at')
            ->limit($limitRecent)
            ->get();

        $expired = AiOperatorApproval::query()
            ->where('status', OperatorApprovalCanon::STATUS_EXPIRED)
            ->orderByDesc('decided_at')
            ->limit($limitRecent)
            ->get();

        $recentDecisions = AiOperatorApproval::query()
            ->whereIn('status', [
                OperatorApprovalCanon::STATUS_APPROVED,
                OperatorApprovalCanon::STATUS_DENIED,
            ])
            ->orderByDesc('decided_at')
            ->limit($limitRecent)
            ->get();

        $blockers = AiOperatorApproval::query()
            ->where('gate_mode', OperatorApprovalCanon::MODE_BLOCK)
            ->orWhere(function ($q): void {
                $q->where('status', OperatorApprovalCanon::STATUS_DENIED);
            })
            ->orderByDesc('created_at')
            ->limit($limitRecent)
            ->get();

        return [
            'schema_version' => 'atlas.ai.operator_approval.control_plane.v1',
            'totals' => [
                'all' => AiOperatorApproval::query()->count(),
                'pending' => (int) ($byStatus[OperatorApprovalCanon::STATUS_PENDING] ?? 0),
                'approved' => (int) ($byStatus[OperatorApprovalCanon::STATUS_APPROVED] ?? 0),
                'denied' => (int) ($byStatus[OperatorApprovalCanon::STATUS_DENIED] ?? 0),
                'expired' => (int) ($byStatus[OperatorApprovalCanon::STATUS_EXPIRED] ?? 0),
                'cancelled' => (int) ($byStatus[OperatorApprovalCanon::STATUS_CANCELLED] ?? 0),
                'auto_approved' => (int) ($byStatus[OperatorApprovalCanon::STATUS_AUTO_APPROVED] ?? 0),
            ],
            'by_status' => $byStatus,
            'by_mode' => $byMode,
            'by_risk' => $byRisk,
            'pending' => $pending->map(fn (AiOperatorApproval $a) => $this->serialize($a))->all(),
            'expired' => $expired->map(fn (AiOperatorApproval $a) => $this->serialize($a))->all(),
            'recent_decisions' => $recentDecisions->map(fn (AiOperatorApproval $a) => $this->serialize($a))->all(),
            'blockers' => $blockers->map(fn (AiOperatorApproval $a) => $this->serialize($a))->all(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function serialize(AiOperatorApproval $approval): array
    {
        return [
            'uuid' => $approval->uuid,
            'mission_id' => $approval->mission_id,
            'work_order_id' => $approval->work_order_id,
            'trace_id' => $approval->trace_id,
            'job_id' => $approval->job_id,
            'requested_action' => $approval->requested_action,
            'risk_level' => $approval->risk_level,
            'gate_mode' => $approval->gate_mode,
            'approval_required' => (bool) $approval->approval_required,
            'reason' => $approval->reason,
            'options' => (array) ($approval->options ?? []),
            'status' => $approval->status,
            'operator_decision' => $approval->operator_decision,
            'operator' => $approval->operator,
            'operator_note' => $approval->operator_note,
            'expires_at' => $approval->expires_at?->toJSON(),
            'decided_at' => $approval->decided_at?->toJSON(),
            'consumed_at' => $approval->consumed_at?->toJSON(),
            'evidence_refs' => (array) ($approval->evidence_refs ?? []),
            'receipt_hash' => $approval->receipt_hash,
            'hash' => $approval->hash,
            'created_at' => $approval->created_at?->toJSON(),
        ];
    }

    private function decide(AiOperatorApproval $approval, string $decision, string $operator, ?string $note): AiOperatorApproval
    {
        if (! in_array($decision, OperatorApprovalCanon::DECISIONS, true)) {
            throw new InvalidArgumentException("invalid decision [{$decision}]");
        }
        if ($approval->status !== OperatorApprovalCanon::STATUS_PENDING) {
            throw new InvalidArgumentException("approval already decided as [{$approval->status}]");
        }
        if ($approval->expires_at !== null && $approval->expires_at->isPast()) {
            $this->expireDue();
            $approval->refresh();
            throw new InvalidArgumentException('approval expired before decision');
        }

        $status = $decision === OperatorApprovalCanon::DECISION_APPROVE
            ? OperatorApprovalCanon::STATUS_APPROVED
            : OperatorApprovalCanon::STATUS_DENIED;

        $sanitizedNote = $note === null ? null : $this->truncate($note, self::NOTE_MAX_LEN);
        $now = Carbon::now();
        $receiptPayload = [
            'uuid' => $approval->uuid,
            'requested_action' => $approval->requested_action,
            'gate_mode' => $approval->gate_mode,
            'status' => $status,
            'operator' => $operator,
            'operator_decision' => $decision,
            'decided_at' => $now->toJSON(),
        ];

        $approval->status = $status;
        $approval->operator_decision = $decision;
        $approval->operator = $this->truncate($operator, 160);
        $approval->operator_note = $sanitizedNote;
        $approval->decided_at = $now;
        $approval->receipt_hash = MissionCanonicalHash::sha256($receiptPayload);
        $approval->save();

        return $approval;
    }

    private function initialStatusForMode(string $gateMode): string
    {
        return match ($gateMode) {
            OperatorApprovalCanon::MODE_BLOCK => OperatorApprovalCanon::STATUS_PENDING,
            OperatorApprovalCanon::MODE_ALLOW_AUTO => OperatorApprovalCanon::STATUS_AUTO_APPROVED,
            default => OperatorApprovalCanon::STATUS_PENDING,
        };
    }

    private function defaultReason(string $action, string $gateMode): string
    {
        return sprintf('operator_approval_gate:%s mode=%s', $action, $gateMode);
    }

    /**
     * @param  array<int,string>  $options
     * @return array<int,string>
     */
    private function resolveOptions(array $options, string $gateMode): array
    {
        if ($options !== []) {
            $clean = [];
            foreach ($options as $option) {
                if (! is_string($option)) {
                    continue;
                }
                $clean[] = $this->truncate($option, 80);
            }

            return array_values(array_unique($clean));
        }

        return match ($gateMode) {
            OperatorApprovalCanon::MODE_BLOCK => [OperatorApprovalCanon::DECISION_DENY],
            OperatorApprovalCanon::MODE_ALLOW_AUTO => [],
            default => [OperatorApprovalCanon::DECISION_APPROVE, OperatorApprovalCanon::DECISION_DENY],
        };
    }

    /**
     * @param  array<int,mixed>  $refs
     * @return array<int,string>
     */
    private function sanitizeEvidenceRefs(array $refs): array
    {
        $clean = [];
        foreach ($refs as $ref) {
            if (is_array($ref)) {
                $type = (string) ($ref['type'] ?? 'ref');
                $value = (string) ($ref['ref'] ?? $ref['value'] ?? '');
                if ($value === '') {
                    continue;
                }
                $clean[] = sprintf('%s:%s', $this->truncate($type, 40), $this->truncate($value, 200));

                continue;
            }
            if (is_string($ref) && $ref !== '') {
                $clean[] = $this->truncate($ref, 240);
            }
        }

        return array_values(array_unique($clean));
    }

    private function resolveExpiry(string $gateMode, mixed $minutes): ?Carbon
    {
        if ($gateMode === OperatorApprovalCanon::MODE_ALLOW_AUTO) {
            return null;
        }
        if ($gateMode === OperatorApprovalCanon::MODE_BLOCK) {
            return null;
        }

        $effectiveMinutes = is_numeric($minutes) ? (int) $minutes : ($gateMode === OperatorApprovalCanon::MODE_REQUIRE_REVIEW
            ? OperatorApprovalCanon::DEFAULT_REVIEW_EXPIRY_MINUTES
            : OperatorApprovalCanon::DEFAULT_EXPIRY_MINUTES);

        return Carbon::now()->addMinutes(max(1, $effectiveMinutes));
    }

    private function sanitizeReason(string $reason): string
    {
        return $this->truncate($reason, self::REASON_MAX_LEN);
    }

    private function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        return mb_substr($value, 0, $max);
    }
}
