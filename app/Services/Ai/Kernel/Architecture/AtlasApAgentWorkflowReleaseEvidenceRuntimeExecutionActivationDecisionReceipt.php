<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationDecisionReceipt
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_decision_receipt.v1';

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationDecisionContract $runtimeExecutionActivationDecisionContract,
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
    public function receipt(
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
        $decisionPayload = $this->runtimeExecutionActivationDecisionContract->decide(
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
            runtimeExecutionActivationDecision: $runtimeExecutionActivationDecision,
            runtimeExecutionActivationDecisionReason: $runtimeExecutionActivationDecisionReason,
            runtimeExecutionImplementationDecisionReason: $runtimeExecutionImplementationDecisionReason,
            runtimeExecutionDecisionReason: $runtimeExecutionDecisionReason,
            activationDecisionReason: $activationDecisionReason,
            implementationDecisionReason: $implementationDecisionReason,
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
        $status = $this->status($decisionPayload);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_runtime_execution_activation_decision_receipt',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_activation_receipt_only_no_execution',
            'work_title' => $decisionPayload['work_title'],
            'resolved_target_ap' => $decisionPayload['resolved_target_ap'],
            'runtime_execution_activation_decision_summary' => [
                'schema_version' => $decisionPayload['schema_version'],
                'status' => $decisionPayload['status'],
                'decision' => data_get($decisionPayload, 'runtime_execution_activation_decision.value'),
                'reason' => data_get($decisionPayload, 'runtime_execution_activation_decision.reason'),
                'activation_surface' => data_get($decisionPayload, 'runtime_execution_activation_preflight_summary.activation_surface'),
                'activation_entrypoint' => data_get($decisionPayload, 'runtime_execution_activation_preflight_summary.activation_entrypoint'),
                'policy_receipt_source' => data_get($decisionPayload, 'runtime_execution_activation_preflight_summary.policy_receipt_source'),
                'operator_confirmation_surface' => data_get($decisionPayload, 'runtime_execution_activation_preflight_summary.operator_confirmation_surface'),
                'owner' => data_get($decisionPayload, 'runtime_execution_activation_preflight_summary.owner'),
                'replay_window' => data_get($decisionPayload, 'runtime_execution_activation_preflight_summary.replay_window'),
                'rollback_plan_ref' => data_get($decisionPayload, 'runtime_execution_activation_preflight_summary.rollback_plan_ref'),
                'observability_hooks' => data_get($decisionPayload, 'runtime_execution_activation_preflight_summary.observability_hooks'),
                'idempotency_key_strategy' => data_get($decisionPayload, 'runtime_execution_activation_preflight_summary.idempotency_key_strategy'),
            ],
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_receipt' => false,
                'publishes_release' => false,
                'emits_evidence_event' => false,
                'writes_evidence_ledger' => false,
                'creates_runtime_job' => false,
                'runs_dry_run' => false,
                'executes_authorized_work' => false,
                'performs_activation' => false,
                'executes_runtime_payload' => false,
                'bypasses_future_runtime_execution_activation_ap' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $decisionPayload
     */
    private function status(array $decisionPayload): string
    {
        return match ($decisionPayload['status'] ?? null) {
            'runtime_execution_activation_accepted_by_human' => 'runtime_execution_activation_acceptance_reported',
            'runtime_execution_activation_changes_requested_by_human' => 'runtime_execution_activation_returned_for_repair',
            'runtime_execution_activation_rejected_by_human' => 'runtime_execution_activation_stopped_by_rejection',
            default => 'blocked_by_runtime_execution_activation_decision_contract',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'runtime_execution_activation_acceptance_reported' => 'future_runtime_execution_activation_ap_may_consume_decision_receipt_without_activation',
            'runtime_execution_activation_returned_for_repair' => 'repair_runtime_execution_activation_preflight_then_request_new_human_decision',
            'runtime_execution_activation_stopped_by_rejection' => 'stop_runtime_execution_activation_flow_until_scope_reopens',
            'blocked_by_runtime_execution_activation_decision_contract' => 'repair_runtime_execution_activation_decision_before_receipt',
            default => 'review_runtime_execution_activation_decision_receipt_status',
        };
    }
}
