<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceExecutionActivationPreflight
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_execution_activation_preflight.v1';

    /** @var array<int,string> */
    private const REQUIRED_PREFLIGHT_EVIDENCE = [
        'reviewed_execution_implementation_receipt',
        'declared_activation_surface',
        'declared_activation_entrypoint',
        'declared_policy_receipt_source',
        'declared_operator_confirmation_surface',
        'confirmed_execution_receipt_accepted',
        'confirmed_final_replay_window',
        'confirmed_final_rollback_plan',
        'confirmed_observability_hooks',
        'confirmed_idempotency_key_strategy',
        'confirmed_no_activation_performed',
        'confirmed_no_runtime_job_created',
        'confirmed_no_ledger_write',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_PREFLIGHT_EVIDENCE = [
        'activation_entrypoint',
        'activation_surface',
        'idempotency_key_strategy',
        'notes',
        'observability_hooks',
        'operator_confirmation_surface',
        'owner',
        'policy_receipt_source',
        'replay_window',
        'rollback_plan_ref',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidenceExecutionImplementationDecisionReceipt $executionImplementationDecisionReceipt,
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
     * @return array<string,mixed>
     */
    public function evaluate(
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
        $implementationReceipt = $this->executionImplementationDecisionReceipt->receipt(
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
        $evidence = $this->evaluateEvidence($activationPreflightEvidence);
        $status = $this->status($implementationReceipt, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_execution_activation_preflight',
            'authority' => 'ap_agent_workflow_release_evidence_execution_activation_preflight_only_no_execution',
            'work_title' => $implementationReceipt['work_title'],
            'resolved_target_ap' => $implementationReceipt['resolved_target_ap'],
            'execution_implementation_receipt_summary' => [
                'schema_version' => $implementationReceipt['schema_version'],
                'status' => $implementationReceipt['status'],
                'decision' => data_get($implementationReceipt, 'execution_implementation_decision_summary.decision'),
                'execution_surface' => data_get($implementationReceipt, 'execution_implementation_decision_summary.execution_surface'),
                'execution_entrypoint' => data_get($implementationReceipt, 'execution_implementation_decision_summary.execution_entrypoint'),
                'operator_owner' => data_get($implementationReceipt, 'execution_implementation_decision_summary.operator_owner'),
                'mutation_boundary' => data_get($implementationReceipt, 'execution_implementation_decision_summary.mutation_boundary'),
                'evidence_event_schema' => data_get($implementationReceipt, 'execution_implementation_decision_summary.evidence_event_schema'),
                'replay_window' => data_get($implementationReceipt, 'execution_implementation_decision_summary.replay_window'),
                'rollback_plan_ref' => data_get($implementationReceipt, 'execution_implementation_decision_summary.rollback_plan_ref'),
            ],
            'execution_activation_preflight_evidence' => $evidence,
            'execution_activation_target' => [
                'activation_surface' => $activationPreflightEvidence['activation_surface'] ?? null,
                'activation_entrypoint' => $activationPreflightEvidence['activation_entrypoint'] ?? null,
                'policy_receipt_source' => $activationPreflightEvidence['policy_receipt_source'] ?? null,
                'operator_confirmation_surface' => $activationPreflightEvidence['operator_confirmation_surface'] ?? null,
                'owner' => $activationPreflightEvidence['owner'] ?? null,
                'replay_window' => $activationPreflightEvidence['replay_window'] ?? null,
                'rollback_plan_ref' => $activationPreflightEvidence['rollback_plan_ref'] ?? null,
                'observability_hooks' => $activationPreflightEvidence['observability_hooks'] ?? null,
                'idempotency_key_strategy' => $activationPreflightEvidence['idempotency_key_strategy'] ?? null,
            ],
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_preflight' => false,
                'publishes_release' => false,
                'emits_evidence_event' => false,
                'writes_evidence_ledger' => false,
                'creates_runtime_job' => false,
                'runs_dry_run' => false,
                'executes_authorized_work' => false,
                'performs_activation' => false,
                'accepts_without_implementation_receipt' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $preflightEvidence
     * @return array<string,mixed>
     */
    private function evaluateEvidence(array $preflightEvidence): array
    {
        $failed = [];
        $shapeErrors = [];
        $allowedKeys = array_merge(self::REQUIRED_PREFLIGHT_EVIDENCE, self::OPTIONAL_PREFLIGHT_EVIDENCE);

        foreach ($preflightEvidence as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                $shapeErrors[] = [
                    'id' => 'unknown_release_evidence_execution_activation_preflight_key',
                    'key' => (string) $key,
                ];
            }
        }

        foreach (self::REQUIRED_PREFLIGHT_EVIDENCE as $key) {
            if (! array_key_exists($key, $preflightEvidence)) {
                $shapeErrors[] = [
                    'id' => 'missing_required_release_evidence_execution_activation_preflight',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($preflightEvidence[$key])) {
                $shapeErrors[] = [
                    'id' => 'release_evidence_execution_activation_preflight_must_be_boolean',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if ($preflightEvidence[$key] !== true) {
                $failed[] = $key;
            }
        }

        foreach (['activation_surface', 'activation_entrypoint', 'policy_receipt_source', 'operator_confirmation_surface', 'owner', 'replay_window', 'rollback_plan_ref', 'observability_hooks', 'idempotency_key_strategy'] as $key) {
            if (! is_string($preflightEvidence[$key] ?? null) || trim((string) $preflightEvidence[$key]) === '') {
                $shapeErrors[] = [
                    'id' => 'release_evidence_execution_activation_preflight_text_required',
                    'key' => $key,
                ];
            }
        }

        return [
            'status' => $shapeErrors === []
                ? ($failed === [] ? 'complete' : 'incomplete')
                : 'invalid_shape',
            'required_keys' => self::REQUIRED_PREFLIGHT_EVIDENCE,
            'passed_count' => count(self::REQUIRED_PREFLIGHT_EVIDENCE) - count(array_unique($failed)),
            'failed_count' => count(array_unique($failed)),
            'failed_keys' => array_values(array_unique($failed)),
            'shape_error_count' => count($shapeErrors),
            'shape_errors' => $shapeErrors,
        ];
    }

    /**
     * @param  array<string,mixed>  $implementationReceipt
     * @param  array<string,mixed>  $evidence
     */
    private function status(array $implementationReceipt, array $evidence): string
    {
        if (($implementationReceipt['status'] ?? null) !== 'execution_implementation_acceptance_reported') {
            return 'blocked_by_execution_implementation_decision_receipt';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_execution_activation_preflight_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'execution_activation_preflight_incomplete';
        }

        return 'ready_for_execution_activation_review';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'ready_for_execution_activation_review' => 'future_execution_activation_review_ap_may_review_preflight_without_activation',
            'blocked_by_execution_implementation_decision_receipt' => 'repair_execution_implementation_receipt_before_activation_preflight',
            'blocked_invalid_execution_activation_preflight_shape' => 'fix_execution_activation_preflight_shape_before_review',
            'execution_activation_preflight_incomplete' => 'complete_execution_activation_preflight_evidence_before_review',
            default => 'review_execution_activation_preflight_status',
        };
    }
}
