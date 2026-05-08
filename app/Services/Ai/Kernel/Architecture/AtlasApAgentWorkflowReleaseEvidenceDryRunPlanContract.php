<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceDryRunPlanContract
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_dry_run_plan_contract.v1';

    /** @var array<int,string> */
    private const REQUIRED_DRY_RUN_EVIDENCE = [
        'reviewed_execution_readiness',
        'declared_simulation_scope',
        'declared_fixture_or_corpus',
        'declared_success_criteria',
        'declared_failure_criteria',
        'confirmed_no_real_mutation',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_DRY_RUN_EVIDENCE = [
        'commands',
        'failure_criteria',
        'fixture_or_corpus',
        'notes',
        'simulation_scope',
        'success_criteria',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidenceExecutionReadinessContract $executionReadiness,
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
     * @return array<string,mixed>
     */
    public function plan(
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
        ?string $candidateDecisionReason = null,
        ?string $closeoutReason = null,
        ?string $reason = null,
        ?string $docsApPath = null,
        int|string|null $targetAp = null,
        ?int $requestedApNumber = null,
        ?string $proposedSlug = null,
    ): array {
        $readiness = $this->executionReadiness->evaluate(
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
            candidateDecisionReason: $candidateDecisionReason,
            closeoutReason: $closeoutReason,
            reason: $reason,
            docsApPath: $docsApPath,
            targetAp: $targetAp,
            requestedApNumber: $requestedApNumber,
            proposedSlug: $proposedSlug,
        );
        $evidence = $this->evaluateEvidence($dryRunEvidence);
        $status = $this->status($readiness, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_dry_run_plan',
            'authority' => 'ap_agent_workflow_release_evidence_dry_run_plan_only_no_execution',
            'work_title' => $readiness['work_title'],
            'resolved_target_ap' => $readiness['resolved_target_ap'],
            'execution_readiness_summary' => [
                'schema_version' => $readiness['schema_version'],
                'status' => $readiness['status'],
                'execution_ap' => data_get($readiness, 'execution_target.execution_ap'),
                'owner' => data_get($readiness, 'execution_target.owner'),
                'candidate_kind' => data_get($readiness, 'candidate_decision_receipt_summary.candidate_kind'),
                'payload_schema' => data_get($readiness, 'candidate_decision_receipt_summary.payload_schema'),
            ],
            'dry_run_evidence' => $evidence,
            'dry_run_plan' => [
                'simulation_scope' => $dryRunEvidence['simulation_scope'] ?? null,
                'fixture_or_corpus' => $dryRunEvidence['fixture_or_corpus'] ?? null,
                'success_criteria' => $dryRunEvidence['success_criteria'] ?? null,
                'failure_criteria' => $dryRunEvidence['failure_criteria'] ?? null,
            ],
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_plan' => false,
                'publishes_release' => false,
                'emits_evidence_event' => false,
                'writes_evidence_ledger' => false,
                'creates_runtime_job' => false,
                'runs_dry_run' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $dryRunEvidence
     * @return array<string,mixed>
     */
    private function evaluateEvidence(array $dryRunEvidence): array
    {
        $failed = [];
        $shapeErrors = [];
        $allowedKeys = array_merge(self::REQUIRED_DRY_RUN_EVIDENCE, self::OPTIONAL_DRY_RUN_EVIDENCE);

        foreach ($dryRunEvidence as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                $shapeErrors[] = [
                    'id' => 'unknown_release_evidence_dry_run_plan_key',
                    'key' => (string) $key,
                ];
            }
        }

        foreach (self::REQUIRED_DRY_RUN_EVIDENCE as $key) {
            if (! array_key_exists($key, $dryRunEvidence)) {
                $shapeErrors[] = [
                    'id' => 'missing_required_release_evidence_dry_run_plan',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($dryRunEvidence[$key])) {
                $shapeErrors[] = [
                    'id' => 'release_evidence_dry_run_plan_must_be_boolean',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if ($dryRunEvidence[$key] !== true) {
                $failed[] = $key;
            }
        }

        foreach (['simulation_scope', 'fixture_or_corpus', 'success_criteria', 'failure_criteria'] as $key) {
            if (! is_string($dryRunEvidence[$key] ?? null) || trim((string) $dryRunEvidence[$key]) === '') {
                $shapeErrors[] = [
                    'id' => 'release_evidence_dry_run_plan_text_required',
                    'key' => $key,
                ];
            }
        }

        return [
            'status' => $shapeErrors === []
                ? ($failed === [] ? 'complete' : 'incomplete')
                : 'invalid_shape',
            'required_keys' => self::REQUIRED_DRY_RUN_EVIDENCE,
            'passed_count' => count(self::REQUIRED_DRY_RUN_EVIDENCE) - count(array_unique($failed)),
            'failed_count' => count(array_unique($failed)),
            'failed_keys' => array_values(array_unique($failed)),
            'shape_error_count' => count($shapeErrors),
            'shape_errors' => $shapeErrors,
        ];
    }

    /**
     * @param  array<string,mixed>  $readiness
     * @param  array<string,mixed>  $evidence
     */
    private function status(array $readiness, array $evidence): string
    {
        if (($readiness['status'] ?? null) !== 'ready_for_future_release_or_ledger_execution_ap') {
            return 'blocked_by_execution_readiness';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_dry_run_plan_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'release_evidence_dry_run_plan_incomplete';
        }

        return 'ready_for_future_dry_run_review';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'ready_for_future_dry_run_review' => 'future_execution_ap_may_review_dry_run_plan_without_running_it',
            'blocked_by_execution_readiness' => 'repair_execution_readiness_before_dry_run_plan',
            'blocked_invalid_dry_run_plan_shape' => 'fix_dry_run_plan_shape_before_review',
            'release_evidence_dry_run_plan_incomplete' => 'complete_dry_run_plan_before_review',
            default => 'review_release_evidence_dry_run_plan_status',
        };
    }
}
