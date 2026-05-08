<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionContract
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_candidate_decision_contract.v1';

    /** @var array<int,string> */
    private const ALLOWED_DECISIONS = [
        'accept_candidate',
        'request_candidate_changes',
        'reject_candidate',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidenceCandidateContract $releaseEvidenceCandidateContract,
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
        ?string $candidateDecisionReason = null,
        ?string $closeoutReason = null,
        ?string $reason = null,
        ?string $docsApPath = null,
        int|string|null $targetAp = null,
        ?int $requestedApNumber = null,
        ?string $proposedSlug = null,
    ): array {
        $candidate = $this->releaseEvidenceCandidateContract->candidate(
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
            closeoutReason: $closeoutReason,
            reason: $reason,
            docsApPath: $docsApPath,
            targetAp: $targetAp,
            requestedApNumber: $requestedApNumber,
            proposedSlug: $proposedSlug,
        );
        $decisionPayload = $this->decisionPayload($candidateDecision, $candidateDecisionReason);
        $status = $this->status($candidate, $decisionPayload);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_candidate_decision',
            'authority' => 'ap_agent_workflow_release_evidence_candidate_decision_only_no_execution',
            'work_title' => $candidate['work_title'],
            'resolved_target_ap' => $candidate['resolved_target_ap'],
            'release_evidence_candidate_summary' => [
                'schema_version' => $candidate['schema_version'],
                'status' => $candidate['status'],
                'candidate_kind' => data_get($candidate, 'candidate.kind'),
                'payload_schema' => data_get($candidate, 'candidate.payload_schema'),
                'handoff_status' => data_get($candidate, 'release_evidence_handoff_summary.status'),
            ],
            'candidate_decision' => $decisionPayload,
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_decision' => false,
                'publishes_release' => false,
                'emits_evidence_event' => false,
                'writes_evidence_ledger' => false,
                'creates_runtime_job' => false,
                'accepts_without_ready_candidate' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decisionPayload(string $candidateDecision, ?string $reason): array
    {
        $normalized = trim($candidateDecision);
        $normalizedReason = $reason === null ? null : trim($reason);
        $errors = [];

        if (! in_array($normalized, self::ALLOWED_DECISIONS, true)) {
            $errors[] = [
                'id' => 'candidate_decision_not_allowed',
                'decision' => $normalized,
            ];
        }

        if (in_array($normalized, ['request_candidate_changes', 'reject_candidate'], true) && ($normalizedReason === null || $normalizedReason === '')) {
            $errors[] = [
                'id' => 'candidate_decision_reason_required',
                'decision' => $normalized,
            ];
        }

        return [
            'value' => $normalized,
            'reason' => $normalizedReason,
            'allowed_values' => self::ALLOWED_DECISIONS,
            'status' => $errors === [] ? 'valid' : 'invalid',
            'error_count' => count($errors),
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $decisionPayload
     */
    private function status(array $candidate, array $decisionPayload): string
    {
        if (($decisionPayload['status'] ?? null) !== 'valid') {
            return 'blocked_invalid_candidate_decision';
        }

        if (($candidate['status'] ?? null) !== 'release_evidence_candidate_ready_for_human_review') {
            return 'blocked_by_release_evidence_candidate_contract';
        }

        return match ($decisionPayload['value'] ?? null) {
            'accept_candidate' => 'release_evidence_candidate_accepted_by_human',
            'request_candidate_changes' => 'release_evidence_candidate_changes_requested_by_human',
            'reject_candidate' => 'release_evidence_candidate_rejected_by_human',
            default => 'blocked_invalid_candidate_decision',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'release_evidence_candidate_accepted_by_human' => 'future_ap_may_consume_accepted_candidate_without_auto_execution',
            'release_evidence_candidate_changes_requested_by_human' => 'repair_candidate_contract_before_new_human_decision',
            'release_evidence_candidate_rejected_by_human' => 'stop_release_evidence_candidate_until_scope_reopens',
            'blocked_by_release_evidence_candidate_contract' => 'repair_release_evidence_candidate_before_human_decision',
            'blocked_invalid_candidate_decision' => 'fix_candidate_decision_value_or_reason_before_review',
            default => 'review_release_evidence_candidate_decision_status',
        };
    }
}
