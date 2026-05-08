<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationDecisionContract
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_decision_contract.v1';

    /** @var array<int,string> */
    private const ALLOWED_DECISIONS = [
        'accept_runtime_execution_activation',
        'request_runtime_execution_activation_changes',
        'reject_runtime_execution_activation',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationPreflight $runtimeExecutionActivationPreflight,
    ) {}

    /**
     * @param  array<int,string>  $intendedPaths
     * @param  array<string,mixed>  $validationEvidence
     * @param  array<int,mixed>  $traceSteps
     * @param  array<string,mixed>  $integratorEvidence
     * @param  array<string,mixed>  $integrationEvidence
     * @param  array<string,mixed>  $finalAuditEvidence
     * @param  array<string,mixed>  $preflightEvidence
     * @param  array<string,mixed>  $handoffEvidence
     * @param  array<string,mixed>  $candidateEvidence
     * @param  array<string,mixed>  $readinessEvidence
     * @param  array<string,mixed>  $dryRunEvidence
     * @param  array<string,mixed>  $resultEvidence
     * @param  array<string,mixed>  $postDryRunHandoffEvidence
     * @param  array<string,mixed>  $consumerReadinessEvidence
     * @param  array<string,mixed>  $authorizationEvidence
     * @param  array<string,mixed>  $executionAuthorizationHandoffEvidence
     * @param  array<string,mixed>  $implementationPreflightEvidence
     * @param  array<string,mixed>  $activationPreflightEvidence
     * @param  array<string,mixed>  $runtimePreflightEvidence
     * @param  array<string,mixed>  $runtimeExecutionHandoffEvidence
     * @param  array<string,mixed>  $runtimeExecutionImplementationPreflightEvidence
     * @param  array<string,mixed>  $runtimeExecutionActivationPreflightEvidence
     * @return array<string,mixed>
     */
    public function decide(
        string $workTitle,
        array $intendedPaths,
        array $validationEvidence,
        array $traceSteps,
        string $decision,
        array $integratorEvidence,
        array $integrationEvidence,
        array $finalAuditEvidence,
        string $closeoutDecision,
        array $preflightEvidence,
        array $handoffEvidence,
        array $candidateEvidence,
        string $candidateDecision,
        array $readinessEvidence,
        array $dryRunEvidence,
        string $dryRunReviewDecision,
        array $resultEvidence,
        string $resultReviewDecision,
        array $postDryRunHandoffEvidence,
        array $consumerReadinessEvidence,
        string $consumerReadinessDecision,
        array $authorizationEvidence,
        string $authorizationDecision,
        array $executionAuthorizationHandoffEvidence,
        array $implementationPreflightEvidence,
        string $implementationDecision,
        array $activationPreflightEvidence,
        string $activationDecision,
        array $runtimePreflightEvidence,
        string $runtimeExecutionDecision,
        array $runtimeExecutionHandoffEvidence,
        array $runtimeExecutionImplementationPreflightEvidence,
        string $runtimeExecutionImplementationDecision,
        array $runtimeExecutionActivationPreflightEvidence,
        string $runtimeExecutionActivationDecision,
        ?string $runtimeExecutionActivationDecisionReason = null,
        ?string $runtimeExecutionImplementationDecisionReason = null,
        ?string $runtimeExecutionDecisionReason = null,
        ?string $activationDecisionReason = null,
        ?string $implementationDecisionReason = null,
        ?string $authorizationDecisionReason = null,
        ?string $consumerReadinessDecisionReason = null,
        ?string $resultReviewReason = null,
        ?string $dryRunReviewReason = null,
        ?string $candidateDecisionReason = null,
        ?string $closeoutReason = null,
        ?string $reason = null,
        ?string $docsApPath = null,
        int|string|null $targetAp = null,
        ?int $requestedApNumber = null,
        ?string $proposedSlug = null,
    ): array {
        $preflight = $this->runtimeExecutionActivationPreflight->evaluate(
            workTitle: $workTitle,
            intendedPaths: $intendedPaths,
            validationEvidence: $validationEvidence,
            traceSteps: $traceSteps,
            decision: $decision,
            integratorEvidence: $integratorEvidence,
            integrationEvidence: $integrationEvidence,
            finalAuditEvidence: $finalAuditEvidence,
            closeoutDecision: $closeoutDecision,
            preflightEvidence: $preflightEvidence,
            handoffEvidence: $handoffEvidence,
            candidateEvidence: $candidateEvidence,
            candidateDecision: $candidateDecision,
            readinessEvidence: $readinessEvidence,
            dryRunEvidence: $dryRunEvidence,
            dryRunReviewDecision: $dryRunReviewDecision,
            resultEvidence: $resultEvidence,
            resultReviewDecision: $resultReviewDecision,
            postDryRunHandoffEvidence: $postDryRunHandoffEvidence,
            consumerReadinessEvidence: $consumerReadinessEvidence,
            consumerReadinessDecision: $consumerReadinessDecision,
            authorizationEvidence: $authorizationEvidence,
            authorizationDecision: $authorizationDecision,
            executionAuthorizationHandoffEvidence: $executionAuthorizationHandoffEvidence,
            implementationPreflightEvidence: $implementationPreflightEvidence,
            implementationDecision: $implementationDecision,
            activationPreflightEvidence: $activationPreflightEvidence,
            activationDecision: $activationDecision,
            runtimePreflightEvidence: $runtimePreflightEvidence,
            runtimeExecutionDecision: $runtimeExecutionDecision,
            runtimeExecutionHandoffEvidence: $runtimeExecutionHandoffEvidence,
            runtimeExecutionImplementationPreflightEvidence: $runtimeExecutionImplementationPreflightEvidence,
            runtimeExecutionImplementationDecision: $runtimeExecutionImplementationDecision,
            runtimeExecutionActivationPreflightEvidence: $runtimeExecutionActivationPreflightEvidence,
            runtimeExecutionImplementationDecisionReason: $runtimeExecutionImplementationDecisionReason,
            runtimeExecutionDecisionReason: $runtimeExecutionDecisionReason,
            implementationDecisionReason: $implementationDecisionReason,
            activationDecisionReason: $activationDecisionReason,
            authorizationDecisionReason: $authorizationDecisionReason,
            consumerReadinessDecisionReason: $consumerReadinessDecisionReason,
            resultReviewReason: $resultReviewReason,
            dryRunReviewReason: $dryRunReviewReason,
            candidateDecisionReason: $candidateDecisionReason,
            closeoutReason: $closeoutReason,
            reason: $reason,
            docsApPath: $docsApPath,
            targetAp: $targetAp,
            requestedApNumber: $requestedApNumber,
            proposedSlug: $proposedSlug,
        );
        $decisionShape = $this->decisionShape($runtimeExecutionActivationDecision, $runtimeExecutionActivationDecisionReason);
        $status = $this->status($preflight, $decisionShape);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_runtime_execution_activation_decision_contract',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_activation_decision_only_no_execution',
            'work_title' => $preflight['work_title'],
            'resolved_target_ap' => $preflight['resolved_target_ap'],
            'runtime_execution_activation_preflight_summary' => [
                'schema_version' => $preflight['schema_version'],
                'status' => $preflight['status'],
                'activation_surface' => data_get($preflight, 'runtime_execution_activation_target.activation_surface'),
                'activation_entrypoint' => data_get($preflight, 'runtime_execution_activation_target.activation_entrypoint'),
                'policy_receipt_source' => data_get($preflight, 'runtime_execution_activation_target.policy_receipt_source'),
                'operator_confirmation_surface' => data_get($preflight, 'runtime_execution_activation_target.operator_confirmation_surface'),
                'owner' => data_get($preflight, 'runtime_execution_activation_target.owner'),
                'replay_window' => data_get($preflight, 'runtime_execution_activation_target.replay_window'),
                'rollback_plan_ref' => data_get($preflight, 'runtime_execution_activation_target.rollback_plan_ref'),
                'observability_hooks' => data_get($preflight, 'runtime_execution_activation_target.observability_hooks'),
                'idempotency_key_strategy' => data_get($preflight, 'runtime_execution_activation_target.idempotency_key_strategy'),
            ],
            'runtime_execution_activation_decision' => $decisionShape,
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_decision' => false,
                'publishes_release' => false,
                'emits_evidence_event' => false,
                'writes_evidence_ledger' => false,
                'creates_runtime_job' => false,
                'runs_dry_run' => false,
                'executes_runtime_payload' => false,
                'performs_activation' => false,
                'accepts_without_runtime_execution_activation_preflight' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decisionShape(string $decision, ?string $reason): array
    {
        $validDecision = in_array($decision, self::ALLOWED_DECISIONS, true);
        $reasonText = is_string($reason) ? trim($reason) : '';

        return [
            'value' => $decision,
            'reason' => $reasonText === '' ? null : $reasonText,
            'allowed_values' => self::ALLOWED_DECISIONS,
            'valid' => $validDecision && $reasonText !== '',
            'reason_required' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $preflight
     * @param  array<string,mixed>  $decisionShape
     */
    private function status(array $preflight, array $decisionShape): string
    {
        if (($preflight['status'] ?? null) !== 'ready_for_runtime_execution_activation_review') {
            return 'blocked_by_runtime_execution_activation_preflight';
        }

        if (($decisionShape['valid'] ?? false) !== true) {
            return 'blocked_invalid_runtime_execution_activation_decision';
        }

        return match ($decisionShape['value']) {
            'accept_runtime_execution_activation' => 'runtime_execution_activation_accepted_by_human',
            'request_runtime_execution_activation_changes' => 'runtime_execution_activation_changes_requested_by_human',
            'reject_runtime_execution_activation' => 'runtime_execution_activation_rejected_by_human',
            default => 'blocked_invalid_runtime_execution_activation_decision',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'runtime_execution_activation_accepted_by_human' => 'future_runtime_execution_activation_receipt_ap_may_report_acceptance_without_activation',
            'runtime_execution_activation_changes_requested_by_human' => 'repair_runtime_execution_activation_preflight_then_request_new_human_decision',
            'runtime_execution_activation_rejected_by_human' => 'stop_runtime_execution_activation_flow_until_scope_reopens',
            'blocked_by_runtime_execution_activation_preflight' => 'repair_runtime_execution_activation_preflight_before_decision',
            'blocked_invalid_runtime_execution_activation_decision' => 'provide_valid_runtime_execution_activation_decision_and_reason',
            default => 'review_runtime_execution_activation_decision_status',
        };
    }
}
