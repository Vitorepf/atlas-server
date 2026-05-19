<?php

namespace App\Services\Ai\OperatorApproval;

use App\Models\AiOperatorApproval;

/**
 * DTO canônico de uma decisão de Operator Approval Gate.
 *
 * Schema: atlas.ai.operator_approval.decision.v1
 *
 * Produzido por OperatorApprovalGateService::evaluate(). Carrega:
 *   - gate_mode (allow_auto/require_confirmation/require_review/block/escalate_to_forge)
 *   - risk_level
 *   - approval_required (bool — atalho para "preciso pausar?")
 *   - reasons (array de strings — por que esse modo foi escolhido)
 *   - approval (AiOperatorApproval | null — o record persistido, quando aplicável)
 *
 * `proceed()` é `true` somente para allow_auto. Para WAITING_MODES e
 * BLOCKING_MODES retorna `false` e o caller deve interromper a execução.
 */
final class OperatorApprovalDecision
{
    public const SCHEMA_VERSION = 'atlas.ai.operator_approval.decision.v1';

    /**
     * @param  array<int,string>  $reasons
     * @param  array<int,string>  $options
     * @param  array<int,string>  $evidenceRefs
     */
    public function __construct(
        public readonly string $requestedAction,
        public readonly string $gateMode,
        public readonly string $riskLevel,
        public readonly bool $approvalRequired,
        public readonly string $reason,
        public readonly array $reasons,
        public readonly array $options,
        public readonly array $evidenceRefs,
        public readonly ?AiOperatorApproval $approval,
        public readonly string $hash,
    ) {}

    public function proceed(): bool
    {
        return $this->gateMode === OperatorApprovalCanon::MODE_ALLOW_AUTO;
    }

    public function waitingForOperator(): bool
    {
        return in_array($this->gateMode, OperatorApprovalCanon::WAITING_MODES, true);
    }

    public function blocked(): bool
    {
        return in_array($this->gateMode, OperatorApprovalCanon::BLOCKING_MODES, true);
    }

    public function escalatedToForge(): bool
    {
        return $this->gateMode === OperatorApprovalCanon::MODE_ESCALATE_TO_FORGE;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'requested_action' => $this->requestedAction,
            'gate_mode' => $this->gateMode,
            'risk_level' => $this->riskLevel,
            'approval_required' => $this->approvalRequired,
            'reason' => $this->reason,
            'reasons' => $this->reasons,
            'options' => $this->options,
            'evidence_refs' => $this->evidenceRefs,
            'approval' => $this->approval === null
                ? null
                : [
                    'uuid' => $this->approval->uuid,
                    'status' => $this->approval->status,
                    'gate_mode' => $this->approval->gate_mode,
                    'expires_at' => $this->approval->expires_at?->toJSON(),
                    'receipt_hash' => $this->approval->receipt_hash,
                    'hash' => $this->approval->hash,
                ],
            'hash' => $this->hash,
        ];
    }
}
