<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionReceipt
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_candidate_decision_receipt.v1';

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionContract $candidateDecisionContract,
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
        ?string $candidateDecisionReason = null,
        ?string $closeoutReason = null,
        ?string $reason = null,
        ?string $docsApPath = null,
        int|string|null $targetAp = null,
        ?int $requestedApNumber = null,
        ?string $proposedSlug = null,
    ): array {
        $decisionPayload = $this->candidateDecisionContract->decide(
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
            'mode' => 'read_only_release_evidence_candidate_decision_receipt',
            'authority' => 'ap_agent_workflow_release_evidence_candidate_receipt_only_no_execution',
            'work_title' => $decisionPayload['work_title'],
            'resolved_target_ap' => $decisionPayload['resolved_target_ap'],
            'candidate_decision_summary' => [
                'schema_version' => $decisionPayload['schema_version'],
                'status' => $decisionPayload['status'],
                'decision' => data_get($decisionPayload, 'candidate_decision.value'),
                'reason' => data_get($decisionPayload, 'candidate_decision.reason'),
                'candidate_kind' => data_get($decisionPayload, 'release_evidence_candidate_summary.candidate_kind'),
                'payload_schema' => data_get($decisionPayload, 'release_evidence_candidate_summary.payload_schema'),
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
            'release_evidence_candidate_accepted_by_human' => 'release_evidence_candidate_acceptance_reported',
            'release_evidence_candidate_changes_requested_by_human' => 'release_evidence_candidate_returned_for_repair',
            'release_evidence_candidate_rejected_by_human' => 'release_evidence_candidate_stopped_by_rejection',
            default => 'blocked_by_candidate_decision_contract',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'release_evidence_candidate_acceptance_reported' => 'future_release_or_ledger_ap_may_consume_receipt_without_auto_execution',
            'release_evidence_candidate_returned_for_repair' => 'repair_candidate_then_request_new_human_decision',
            'release_evidence_candidate_stopped_by_rejection' => 'stop_candidate_flow_until_scope_reopens',
            'blocked_by_candidate_decision_contract' => 'repair_candidate_decision_before_receipt',
            default => 'review_release_evidence_candidate_receipt_status',
        };
    }
}
