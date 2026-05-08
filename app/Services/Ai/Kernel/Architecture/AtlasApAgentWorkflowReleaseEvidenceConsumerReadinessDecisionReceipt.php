<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessDecisionReceipt
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_consumer_readiness_decision_receipt.v1';

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessDecisionContract $consumerReadinessDecisionContract,
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
        $decisionPayload = $this->consumerReadinessDecisionContract->decide(
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
            'mode' => 'read_only_release_evidence_consumer_readiness_decision_receipt',
            'authority' => 'ap_agent_workflow_release_evidence_consumer_readiness_receipt_only_no_execution',
            'work_title' => $decisionPayload['work_title'],
            'resolved_target_ap' => $decisionPayload['resolved_target_ap'],
            'consumer_readiness_decision_summary' => [
                'schema_version' => $decisionPayload['schema_version'],
                'status' => $decisionPayload['status'],
                'decision' => data_get($decisionPayload, 'consumer_readiness_decision.decision'),
                'reason' => data_get($decisionPayload, 'consumer_readiness_decision.reason'),
                'target_surface' => data_get($decisionPayload, 'consumer_readiness_summary.target_surface'),
                'future_consumer_owner' => data_get($decisionPayload, 'consumer_readiness_summary.future_consumer_owner'),
                'payload_schema' => data_get($decisionPayload, 'consumer_readiness_summary.payload_schema'),
                'replay_or_rollback_plan' => data_get($decisionPayload, 'consumer_readiness_summary.replay_or_rollback_plan'),
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
                'bypasses_future_ap' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $decisionPayload
     */
    private function status(array $decisionPayload): string
    {
        return match ($decisionPayload['status'] ?? null) {
            'consumer_readiness_accepted_by_human' => 'consumer_readiness_acceptance_reported',
            'consumer_readiness_changes_requested_by_human' => 'consumer_readiness_returned_for_repair',
            'consumer_readiness_rejected_by_human' => 'consumer_readiness_stopped_by_rejection',
            default => 'blocked_by_consumer_readiness_decision_contract',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'consumer_readiness_acceptance_reported' => 'future_release_or_ledger_execution_ap_may_consume_receipt_without_auto_execution',
            'consumer_readiness_returned_for_repair' => 'repair_consumer_readiness_then_request_new_human_decision',
            'consumer_readiness_stopped_by_rejection' => 'stop_consumer_readiness_flow_until_scope_reopens',
            'blocked_by_consumer_readiness_decision_contract' => 'repair_consumer_readiness_decision_before_receipt',
            default => 'review_consumer_readiness_receipt_status',
        };
    }
}
