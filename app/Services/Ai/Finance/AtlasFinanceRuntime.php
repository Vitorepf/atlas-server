<?php

namespace App\Services\Ai\Finance;

use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;

class AtlasFinanceRuntime
{
    /**
     * @var array<int,string>
     */
    public const FORBIDDEN_MARKET_ACTIONS = AtlasFinanceDomainContract::FORBIDDEN_MARKET_ACTIONS;

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
        private readonly AtlasFinanceSafetyPolicy $safety,
        private readonly AtlasFinanceComplianceGate $complianceGate,
    ) {}

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    public function review(array $plan): array
    {
        $envelopeId = 'finance_review:'.(string) ($plan['plan_id'] ?? 'ad_hoc');
        $blockedRequestedActions = $this->blockedRequestedActions((string) data_get($plan, 'operator_options.requested_action', ''));
        $compliance = $this->complianceGate->evaluate($plan, $blockedRequestedActions);

        $this->record(LedgerEventType::ExecutionStarted, $envelopeId, [
            'domain' => AtlasFinanceDomainContract::DOMAIN_ID,
            'flow' => $plan['flow'] ?? null,
            'output_mode' => AtlasFinanceDomainContract::OUTPUT_MODE,
            'market_execution_allowed' => false,
            'blocked_requested_actions' => $blockedRequestedActions,
        ]);

        $this->record(LedgerEventType::GateEvaluated, $envelopeId, [
            'gate_type' => 'finance_compliance_review',
            'passed' => (bool) $compliance['passed'],
            'required' => true,
            'reasons' => $compliance['reasons'],
            'market_execution_allowed' => false,
        ]);

        if (! $compliance['passed']) {
            $this->record(LedgerEventType::GateBlocked, $envelopeId, [
                'gate_type' => $blockedRequestedActions !== [] ? 'market_execution_forbidden' : 'finance_compliance_review',
                'reasons' => $compliance['reasons'],
                'blocked_requested_actions' => $blockedRequestedActions,
                'market_execution_allowed' => false,
            ]);
        }

        $status = $this->statusFor($plan, $blockedRequestedActions, $compliance);

        $packet = [
            'schema_version' => 1,
            'status' => $status,
            'domain' => AtlasFinanceDomainContract::DOMAIN_ID,
            'flow' => $plan['flow'] ?? null,
            'runtime' => AtlasFinanceDomainContract::RUNTIME_ID,
            'output_mode' => AtlasFinanceDomainContract::OUTPUT_MODE,
            'analysis_only' => true,
            'market_execution_allowed' => false,
            'executable_market_actions' => [],
            'trade_order_payload' => null,
            'broker_instructions' => [],
            'blocked_requested_actions' => $blockedRequestedActions,
            'human_approval_required' => (bool) ($plan['human_approval_required'] ?? false),
            'approval_status' => $this->approvalStatus($plan, $blockedRequestedActions),
            'compliance' => [
                'required' => true,
                'gate' => 'finance_compliance_review',
                'passed' => (bool) $compliance['passed'],
                'status' => $this->complianceStatus($blockedRequestedActions, $compliance),
                'reasons' => $compliance['reasons'],
                'scope' => 'No personalized investment advice, fiduciary determination, or market order execution.',
            ],
            'non_execution_contract' => [
                'market_execution_allowed' => false,
                'order_generation_allowed' => false,
                'broker_connection_allowed' => false,
                'cash_movement_allowed' => false,
                'requires_separate_human_operated_execution_surface' => true,
                'forbidden_actions' => self::FORBIDDEN_MARKET_ACTIONS,
            ],
            'review_packet' => [
                'summary_slots' => ['objective', 'evidence', 'risks', 'countercase', 'open_questions'],
                'required_evidence' => (array) ($plan['required_evidence'] ?? []),
                'required_gates' => (array) ($plan['required_gates'] ?? []),
                'forbidden_actions' => self::FORBIDDEN_MARKET_ACTIONS,
            ],
            'plan' => $plan,
            'evidence_refs' => array_values(array_filter([
                'ledger:'.$envelopeId.':execution_started',
                'ledger:'.$envelopeId.':finance_compliance_review',
                ! $compliance['passed'] ? 'ledger:'.$envelopeId.':'.($blockedRequestedActions !== [] ? 'market_execution_forbidden' : 'finance_compliance_review_blocked') : null,
            ])),
            'completed_at' => now()->toJSON(),
        ];

        $this->record(
            $status === 'analysis_ready' ? LedgerEventType::OperationCompleted : LedgerEventType::OperationNeedsReview,
            $envelopeId,
            [
                'status' => $status,
                'market_execution_allowed' => false,
                'executable_market_actions_count' => 0,
                'human_approval_required' => (bool) ($plan['human_approval_required'] ?? false),
                'compliance_reasons' => $compliance['reasons'],
                'blocked_requested_actions' => $blockedRequestedActions,
            ],
        );

        return $packet;
    }

    /**
     * @return array<int,string>
     */
    private function blockedRequestedActions(string $requestedAction): array
    {
        return $this->safety->blockedActions($requestedAction);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<int,string>  $blockedRequestedActions
     */
    private function approvalStatus(array $plan, array $blockedRequestedActions): string
    {
        if ($blockedRequestedActions !== []) {
            return 'blocked_market_execution_request_cannot_be_approved_by_finance_runtime';
        }

        return (bool) ($plan['human_approval_required'] ?? false)
            ? 'required_before_any_downstream_action'
            : 'not_required_for_read_only_review';
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<int,string>  $blockedRequestedActions
     * @param  array{passed:bool,status:string,reasons:array<int,string>}  $compliance
     */
    private function statusFor(array $plan, array $blockedRequestedActions, array $compliance): string
    {
        if ($blockedRequestedActions !== []) {
            return 'blocked_for_market_execution_request';
        }

        if (! $compliance['passed']) {
            return 'blocked_for_finance_compliance';
        }

        return (bool) ($plan['human_approval_required'] ?? false)
            ? 'needs_human_approval'
            : 'analysis_ready';
    }

    /**
     * @param  array<int,string>  $blockedRequestedActions
     * @param  array{passed:bool,status:string,reasons:array<int,string>}  $compliance
     */
    private function complianceStatus(array $blockedRequestedActions, array $compliance): string
    {
        if ($blockedRequestedActions !== []) {
            return 'blocked_market_execution_request';
        }

        return (string) $compliance['status'];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function record(LedgerEventType $type, string $envelopeId, array $payload): void
    {
        $this->ledger->record($type, [
            'envelope_id' => $envelopeId,
            ...$payload,
        ], [
            'tenant_id' => 'default',
            'operator_id' => 'system',
            'envelope_id' => $envelopeId,
            'correlation_id' => $envelopeId,
            'emitter_stage' => 'atlas.finance',
            'emitter_version' => 'finance-domain-v1',
        ]);
    }
}
