<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidencePostDryRunHandoffPacket
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_post_dry_run_handoff_packet.v1';

    /** @var array<int,string> */
    private const REQUIRED_HANDOFF_EVIDENCE = [
        'reviewed_dry_run_result_review',
        'declared_future_consumer_ap',
        'declared_handoff_package',
        'confirmed_accepted_result_only',
        'confirmed_no_auto_release',
        'confirmed_no_ledger_write',
        'confirmed_no_runtime_job',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_HANDOFF_EVIDENCE = [
        'future_consumer_ap',
        'handoff_package',
        'notes',
        'owner',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidenceDryRunResultReviewContract $resultReviewContract,
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
     * @return array<string,mixed>
     */
    public function packet(
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
        $review = $this->resultReviewContract->review(
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
        $evidence = $this->evaluateEvidence($postDryRunHandoffEvidence);
        $status = $this->status($review, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_post_dry_run_handoff_packet',
            'authority' => 'ap_agent_workflow_release_evidence_post_dry_run_handoff_only_no_execution',
            'work_title' => $review['work_title'],
            'resolved_target_ap' => $review['resolved_target_ap'],
            'dry_run_result_review_summary' => [
                'schema_version' => $review['schema_version'],
                'status' => $review['status'],
                'decision' => data_get($review, 'dry_run_result_review_decision.value'),
                'outcome_summary' => data_get($review, 'dry_run_result_envelope_summary.outcome_summary'),
                'failure_observations' => data_get($review, 'dry_run_result_envelope_summary.failure_observations'),
            ],
            'post_dry_run_handoff_evidence' => $evidence,
            'handoff_target' => [
                'future_consumer_ap' => $postDryRunHandoffEvidence['future_consumer_ap'] ?? null,
                'owner' => $postDryRunHandoffEvidence['owner'] ?? null,
            ],
            'handoff_package' => $postDryRunHandoffEvidence['handoff_package'] ?? null,
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_packet' => false,
                'publishes_release' => false,
                'emits_evidence_event' => false,
                'writes_evidence_ledger' => false,
                'creates_runtime_job' => false,
                'runs_dry_run' => false,
                'accepts_without_accepted_result_review' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $postDryRunHandoffEvidence
     * @return array<string,mixed>
     */
    private function evaluateEvidence(array $postDryRunHandoffEvidence): array
    {
        $failed = [];
        $shapeErrors = [];
        $allowedKeys = array_merge(self::REQUIRED_HANDOFF_EVIDENCE, self::OPTIONAL_HANDOFF_EVIDENCE);

        foreach ($postDryRunHandoffEvidence as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                $shapeErrors[] = [
                    'id' => 'unknown_release_evidence_post_dry_run_handoff_key',
                    'key' => (string) $key,
                ];
            }
        }

        foreach (self::REQUIRED_HANDOFF_EVIDENCE as $key) {
            if (! array_key_exists($key, $postDryRunHandoffEvidence)) {
                $shapeErrors[] = [
                    'id' => 'missing_required_release_evidence_post_dry_run_handoff',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($postDryRunHandoffEvidence[$key])) {
                $shapeErrors[] = [
                    'id' => 'release_evidence_post_dry_run_handoff_must_be_boolean',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if ($postDryRunHandoffEvidence[$key] !== true) {
                $failed[] = $key;
            }
        }

        foreach (['future_consumer_ap', 'handoff_package', 'owner'] as $key) {
            if (! is_string($postDryRunHandoffEvidence[$key] ?? null) || trim((string) $postDryRunHandoffEvidence[$key]) === '') {
                $shapeErrors[] = [
                    'id' => 'release_evidence_post_dry_run_handoff_text_required',
                    'key' => $key,
                ];
            }
        }

        return [
            'status' => $shapeErrors === []
                ? ($failed === [] ? 'complete' : 'incomplete')
                : 'invalid_shape',
            'required_keys' => self::REQUIRED_HANDOFF_EVIDENCE,
            'passed_count' => count(self::REQUIRED_HANDOFF_EVIDENCE) - count(array_unique($failed)),
            'failed_count' => count(array_unique($failed)),
            'failed_keys' => array_values(array_unique($failed)),
            'shape_error_count' => count($shapeErrors),
            'shape_errors' => $shapeErrors,
        ];
    }

    /**
     * @param  array<string,mixed>  $review
     * @param  array<string,mixed>  $evidence
     */
    private function status(array $review, array $evidence): string
    {
        if (($review['status'] ?? null) !== 'dry_run_result_accepted_by_human') {
            return 'blocked_by_dry_run_result_review_contract';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_post_dry_run_handoff_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'post_dry_run_handoff_evidence_incomplete';
        }

        return 'post_dry_run_handoff_ready_for_future_release_or_ledger_ap';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'post_dry_run_handoff_ready_for_future_release_or_ledger_ap' => 'future_release_or_ledger_ap_may_review_handoff_without_auto_execution',
            'blocked_by_dry_run_result_review_contract' => 'repair_or_accept_dry_run_result_review_before_handoff',
            'blocked_invalid_post_dry_run_handoff_shape' => 'fix_post_dry_run_handoff_shape_before_review',
            'post_dry_run_handoff_evidence_incomplete' => 'complete_post_dry_run_handoff_evidence_before_review',
            default => 'review_post_dry_run_handoff_status',
        };
    }
}
