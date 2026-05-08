<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceDryRunResultEnvelopeContract
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_dry_run_result_envelope_contract.v1';

    /** @var array<int,string> */
    private const REQUIRED_RESULT_EVIDENCE = [
        'reviewed_dry_run_review_decision',
        'declared_result_source',
        'declared_fixture_or_corpus_used',
        'declared_outcome_summary',
        'declared_failure_observations',
        'confirmed_no_real_mutation',
        'confirmed_no_publish',
        'confirmed_no_ledger_write',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_RESULT_EVIDENCE = [
        'failure_observations',
        'fixture_or_corpus_used',
        'notes',
        'outcome_summary',
        'result_source',
        'runtime_trace_ref',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidenceDryRunReviewContract $dryRunReviewContract,
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
    public function envelope(
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
        ?string $dryRunReviewReason = null,
        ?string $candidateDecisionReason = null,
        ?string $closeoutReason = null,
        ?string $reason = null,
        ?string $docsApPath = null,
        int|string|null $targetAp = null,
        ?int $requestedApNumber = null,
        ?string $proposedSlug = null,
    ): array {
        $review = $this->dryRunReviewContract->review(
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
            dryRunReviewReason: $dryRunReviewReason,
            candidateDecisionReason: $candidateDecisionReason,
            closeoutReason: $closeoutReason,
            reason: $reason,
            docsApPath: $docsApPath,
            targetAp: $targetAp,
            requestedApNumber: $requestedApNumber,
            proposedSlug: $proposedSlug,
        );
        $evidence = $this->evaluateEvidence($resultEvidence);
        $status = $this->status($review, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_dry_run_result_envelope',
            'authority' => 'ap_agent_workflow_release_evidence_dry_run_result_envelope_only_no_execution',
            'work_title' => $review['work_title'],
            'resolved_target_ap' => $review['resolved_target_ap'],
            'dry_run_review_summary' => [
                'schema_version' => $review['schema_version'],
                'status' => $review['status'],
                'decision' => data_get($review, 'dry_run_review_decision.value'),
                'simulation_scope' => data_get($review, 'dry_run_plan_summary.simulation_scope'),
                'fixture_or_corpus' => data_get($review, 'dry_run_plan_summary.fixture_or_corpus'),
            ],
            'dry_run_result_evidence' => $evidence,
            'dry_run_result' => [
                'result_source' => $resultEvidence['result_source'] ?? null,
                'fixture_or_corpus_used' => $resultEvidence['fixture_or_corpus_used'] ?? null,
                'outcome_summary' => $resultEvidence['outcome_summary'] ?? null,
                'failure_observations' => $resultEvidence['failure_observations'] ?? null,
                'runtime_trace_ref' => $resultEvidence['runtime_trace_ref'] ?? null,
            ],
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_result' => false,
                'publishes_release' => false,
                'emits_evidence_event' => false,
                'writes_evidence_ledger' => false,
                'creates_runtime_job' => false,
                'runs_dry_run' => false,
                'accepts_without_human_plan_review' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $resultEvidence
     * @return array<string,mixed>
     */
    private function evaluateEvidence(array $resultEvidence): array
    {
        $failed = [];
        $shapeErrors = [];
        $allowedKeys = array_merge(self::REQUIRED_RESULT_EVIDENCE, self::OPTIONAL_RESULT_EVIDENCE);

        foreach ($resultEvidence as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                $shapeErrors[] = [
                    'id' => 'unknown_release_evidence_dry_run_result_key',
                    'key' => (string) $key,
                ];
            }
        }

        foreach (self::REQUIRED_RESULT_EVIDENCE as $key) {
            if (! array_key_exists($key, $resultEvidence)) {
                $shapeErrors[] = [
                    'id' => 'missing_required_release_evidence_dry_run_result',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($resultEvidence[$key])) {
                $shapeErrors[] = [
                    'id' => 'release_evidence_dry_run_result_must_be_boolean',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if ($resultEvidence[$key] !== true) {
                $failed[] = $key;
            }
        }

        foreach (['result_source', 'fixture_or_corpus_used', 'outcome_summary', 'failure_observations'] as $key) {
            if (! is_string($resultEvidence[$key] ?? null) || trim((string) $resultEvidence[$key]) === '') {
                $shapeErrors[] = [
                    'id' => 'release_evidence_dry_run_result_text_required',
                    'key' => $key,
                ];
            }
        }

        return [
            'status' => $shapeErrors === []
                ? ($failed === [] ? 'complete' : 'incomplete')
                : 'invalid_shape',
            'required_keys' => self::REQUIRED_RESULT_EVIDENCE,
            'passed_count' => count(self::REQUIRED_RESULT_EVIDENCE) - count(array_unique($failed)),
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
        if (($review['status'] ?? null) !== 'dry_run_plan_accepted_by_human') {
            return 'blocked_by_dry_run_review_contract';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_dry_run_result_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'dry_run_result_evidence_incomplete';
        }

        return 'dry_run_result_envelope_ready_for_human_review';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'dry_run_result_envelope_ready_for_human_review' => 'future_ap_may_review_dry_run_result_without_auto_release',
            'blocked_by_dry_run_review_contract' => 'repair_or_accept_dry_run_plan_before_result_envelope',
            'blocked_invalid_dry_run_result_shape' => 'fix_dry_run_result_shape_before_review',
            'dry_run_result_evidence_incomplete' => 'complete_dry_run_result_evidence_before_review',
            default => 'review_dry_run_result_envelope_status',
        };
    }
}
