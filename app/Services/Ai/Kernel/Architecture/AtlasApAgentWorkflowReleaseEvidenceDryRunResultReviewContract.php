<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceDryRunResultReviewContract
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_dry_run_result_review_contract.v1';

    /** @var array<int,string> */
    private const ALLOWED_DECISIONS = [
        'accept_dry_run_result',
        'request_dry_run_result_changes',
        'reject_dry_run_result',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidenceDryRunResultEnvelopeContract $resultEnvelopeContract,
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
     * @return array<string,mixed>
     */
    public function review(
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
        $envelope = $this->resultEnvelopeContract->envelope(
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
            dryRunReviewReason: $dryRunReviewReason,
            candidateDecisionReason: $candidateDecisionReason,
            closeoutReason: $closeoutReason,
            reason: $reason,
            docsApPath: $docsApPath,
            targetAp: $targetAp,
            requestedApNumber: $requestedApNumber,
            proposedSlug: $proposedSlug,
        );
        $reviewDecision = $this->decisionPayload($resultReviewDecision, $resultReviewReason);
        $status = $this->status($envelope, $reviewDecision);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_dry_run_result_review',
            'authority' => 'ap_agent_workflow_release_evidence_dry_run_result_review_only_no_execution',
            'work_title' => $envelope['work_title'],
            'resolved_target_ap' => $envelope['resolved_target_ap'],
            'dry_run_result_envelope_summary' => [
                'schema_version' => $envelope['schema_version'],
                'status' => $envelope['status'],
                'result_source' => data_get($envelope, 'dry_run_result.result_source'),
                'fixture_or_corpus_used' => data_get($envelope, 'dry_run_result.fixture_or_corpus_used'),
                'outcome_summary' => data_get($envelope, 'dry_run_result.outcome_summary'),
                'failure_observations' => data_get($envelope, 'dry_run_result.failure_observations'),
                'dry_run_review_status' => data_get($envelope, 'dry_run_review_summary.status'),
            ],
            'dry_run_result_review_decision' => $reviewDecision,
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_review' => false,
                'publishes_release' => false,
                'emits_evidence_event' => false,
                'writes_evidence_ledger' => false,
                'creates_runtime_job' => false,
                'runs_dry_run' => false,
                'accepts_without_ready_result_envelope' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decisionPayload(string $resultReviewDecision, ?string $reason): array
    {
        $normalized = trim($resultReviewDecision);
        $normalizedReason = $reason === null ? null : trim($reason);
        $errors = [];

        if (! in_array($normalized, self::ALLOWED_DECISIONS, true)) {
            $errors[] = [
                'id' => 'dry_run_result_review_decision_not_allowed',
                'decision' => $normalized,
            ];
        }

        if (in_array($normalized, ['request_dry_run_result_changes', 'reject_dry_run_result'], true) && ($normalizedReason === null || $normalizedReason === '')) {
            $errors[] = [
                'id' => 'dry_run_result_review_reason_required',
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
     * @param  array<string,mixed>  $envelope
     * @param  array<string,mixed>  $reviewDecision
     */
    private function status(array $envelope, array $reviewDecision): string
    {
        if (($reviewDecision['status'] ?? null) !== 'valid') {
            return 'blocked_invalid_dry_run_result_review_decision';
        }

        if (($envelope['status'] ?? null) !== 'dry_run_result_envelope_ready_for_human_review') {
            return 'blocked_by_dry_run_result_envelope_contract';
        }

        return match ($reviewDecision['value'] ?? null) {
            'accept_dry_run_result' => 'dry_run_result_accepted_by_human',
            'request_dry_run_result_changes' => 'dry_run_result_changes_requested_by_human',
            'reject_dry_run_result' => 'dry_run_result_rejected_by_human',
            default => 'blocked_invalid_dry_run_result_review_decision',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'dry_run_result_accepted_by_human' => 'future_release_or_ledger_ap_may_consume_accepted_dry_run_result',
            'dry_run_result_changes_requested_by_human' => 'repair_dry_run_result_envelope_before_new_review',
            'dry_run_result_rejected_by_human' => 'stop_release_evidence_path_until_scope_reopens',
            'blocked_by_dry_run_result_envelope_contract' => 'repair_dry_run_result_envelope_before_review',
            'blocked_invalid_dry_run_result_review_decision' => 'fix_dry_run_result_review_decision_value_or_reason',
            default => 'review_dry_run_result_review_status',
        };
    }
}
