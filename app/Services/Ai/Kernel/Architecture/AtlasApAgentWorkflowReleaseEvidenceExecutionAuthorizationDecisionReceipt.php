<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationDecisionReceipt
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_execution_authorization_decision_receipt.v1';

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationDecisionContract $executionAuthorizationDecisionContract,
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
        $decisionPayload = $this->executionAuthorizationDecisionContract->decide(
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
            'mode' => 'read_only_release_evidence_execution_authorization_decision_receipt',
            'authority' => 'ap_agent_workflow_release_evidence_execution_authorization_receipt_only_no_execution',
            'work_title' => $decisionPayload['work_title'],
            'resolved_target_ap' => $decisionPayload['resolved_target_ap'],
            'execution_authorization_decision_summary' => [
                'schema_version' => $decisionPayload['schema_version'],
                'status' => $decisionPayload['status'],
                'decision' => data_get($decisionPayload, 'execution_authorization_decision.value'),
                'reason' => data_get($decisionPayload, 'execution_authorization_decision.reason'),
                'authorization_surface' => data_get($decisionPayload, 'authorization_preflight_summary.authorization_surface'),
                'authorization_scope' => data_get($decisionPayload, 'authorization_preflight_summary.authorization_scope'),
                'execution_owner' => data_get($decisionPayload, 'authorization_preflight_summary.execution_owner'),
                'payload_schema' => data_get($decisionPayload, 'authorization_preflight_summary.payload_schema'),
                'rollback_reference' => data_get($decisionPayload, 'authorization_preflight_summary.rollback_reference'),
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
                'bypasses_future_execution_ap' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $decisionPayload
     */
    private function status(array $decisionPayload): string
    {
        return match ($decisionPayload['status'] ?? null) {
            'execution_authorization_approved_by_human' => 'execution_authorization_approval_reported',
            'execution_authorization_changes_requested_by_human' => 'execution_authorization_returned_for_repair',
            'execution_authorization_rejected_by_human' => 'execution_authorization_stopped_by_rejection',
            default => 'blocked_by_execution_authorization_decision_contract',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'execution_authorization_approval_reported' => 'future_release_or_ledger_execution_ap_may_consume_authorization_receipt_without_auto_execution',
            'execution_authorization_returned_for_repair' => 'repair_execution_authorization_then_request_new_human_decision',
            'execution_authorization_stopped_by_rejection' => 'stop_execution_authorization_flow_until_scope_reopens',
            'blocked_by_execution_authorization_decision_contract' => 'repair_execution_authorization_decision_before_receipt',
            default => 'review_execution_authorization_receipt_status',
        };
    }
}
