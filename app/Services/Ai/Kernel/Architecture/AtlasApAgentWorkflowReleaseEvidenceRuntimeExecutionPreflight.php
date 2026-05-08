<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionPreflight
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_preflight.v1';

    /** @var array<int,string> */
    private const REQUIRED_PREFLIGHT_EVIDENCE = [
        'reviewed_execution_activation_receipt',
        'declared_runtime_surface',
        'declared_runtime_entrypoint',
        'declared_runtime_owner',
        'declared_execution_payload_schema',
        'confirmed_activation_receipt_accepted',
        'confirmed_policy_receipt_attached',
        'confirmed_operator_confirmation_required',
        'confirmed_idempotency_key_ready',
        'confirmed_observability_ready',
        'confirmed_replay_window_ready',
        'confirmed_rollback_plan_ready',
        'confirmed_no_runtime_execution_performed',
        'confirmed_no_runtime_job_created',
        'confirmed_no_ledger_write',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_PREFLIGHT_EVIDENCE = [
        'execution_payload_schema',
        'idempotency_key_strategy',
        'notes',
        'observability_hooks',
        'operator_confirmation_surface',
        'policy_receipt_source',
        'replay_window',
        'rollback_plan_ref',
        'runtime_entrypoint',
        'runtime_owner',
        'runtime_surface',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidenceExecutionActivationDecisionReceipt $executionActivationDecisionReceipt,
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
        string $activationDecision,
        array $runtimePreflightEvidence,
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
        $activationReceipt = $this->executionActivationDecisionReceipt->receipt(
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
        $evidence = $this->evaluateEvidence($runtimePreflightEvidence);
        $status = $this->status($activationReceipt, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_runtime_execution_preflight',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_preflight_only_no_execution',
            'work_title' => $activationReceipt['work_title'],
            'resolved_target_ap' => $activationReceipt['resolved_target_ap'],
            'execution_activation_receipt_summary' => [
                'schema_version' => $activationReceipt['schema_version'],
                'status' => $activationReceipt['status'],
                'decision' => data_get($activationReceipt, 'execution_activation_decision_summary.decision'),
                'activation_surface' => data_get($activationReceipt, 'execution_activation_decision_summary.activation_surface'),
                'activation_entrypoint' => data_get($activationReceipt, 'execution_activation_decision_summary.activation_entrypoint'),
                'policy_receipt_source' => data_get($activationReceipt, 'execution_activation_decision_summary.policy_receipt_source'),
                'owner' => data_get($activationReceipt, 'execution_activation_decision_summary.owner'),
                'replay_window' => data_get($activationReceipt, 'execution_activation_decision_summary.replay_window'),
                'rollback_plan_ref' => data_get($activationReceipt, 'execution_activation_decision_summary.rollback_plan_ref'),
            ],
            'runtime_execution_preflight_evidence' => $evidence,
            'runtime_execution_target' => [
                'runtime_surface' => $runtimePreflightEvidence['runtime_surface'] ?? null,
                'runtime_entrypoint' => $runtimePreflightEvidence['runtime_entrypoint'] ?? null,
                'runtime_owner' => $runtimePreflightEvidence['runtime_owner'] ?? null,
                'execution_payload_schema' => $runtimePreflightEvidence['execution_payload_schema'] ?? null,
                'policy_receipt_source' => $runtimePreflightEvidence['policy_receipt_source'] ?? null,
                'operator_confirmation_surface' => $runtimePreflightEvidence['operator_confirmation_surface'] ?? null,
                'replay_window' => $runtimePreflightEvidence['replay_window'] ?? null,
                'rollback_plan_ref' => $runtimePreflightEvidence['rollback_plan_ref'] ?? null,
                'observability_hooks' => $runtimePreflightEvidence['observability_hooks'] ?? null,
                'idempotency_key_strategy' => $runtimePreflightEvidence['idempotency_key_strategy'] ?? null,
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
                'executes_runtime_payload' => false,
                'accepts_without_activation_receipt' => false,
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
                    'id' => 'unknown_release_evidence_runtime_execution_preflight_key',
                    'key' => (string) $key,
                ];
            }
        }

        foreach (self::REQUIRED_PREFLIGHT_EVIDENCE as $key) {
            if (! array_key_exists($key, $preflightEvidence)) {
                $shapeErrors[] = [
                    'id' => 'missing_required_release_evidence_runtime_execution_preflight',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($preflightEvidence[$key])) {
                $shapeErrors[] = [
                    'id' => 'release_evidence_runtime_execution_preflight_must_be_boolean',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if ($preflightEvidence[$key] !== true) {
                $failed[] = $key;
            }
        }

        foreach (['runtime_surface', 'runtime_entrypoint', 'runtime_owner', 'execution_payload_schema', 'policy_receipt_source', 'operator_confirmation_surface', 'replay_window', 'rollback_plan_ref', 'observability_hooks', 'idempotency_key_strategy'] as $key) {
            if (! is_string($preflightEvidence[$key] ?? null) || trim((string) $preflightEvidence[$key]) === '') {
                $shapeErrors[] = [
                    'id' => 'release_evidence_runtime_execution_preflight_text_required',
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
     * @param  array<string,mixed>  $activationReceipt
     * @param  array<string,mixed>  $evidence
     */
    private function status(array $activationReceipt, array $evidence): string
    {
        if (($activationReceipt['status'] ?? null) !== 'execution_activation_acceptance_reported') {
            return 'blocked_by_execution_activation_decision_receipt';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_runtime_execution_preflight_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'runtime_execution_preflight_incomplete';
        }

        return 'ready_for_runtime_execution_review';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'ready_for_runtime_execution_review' => 'future_runtime_execution_review_ap_may_review_preflight_without_execution',
            'blocked_by_execution_activation_decision_receipt' => 'repair_execution_activation_receipt_before_runtime_preflight',
            'blocked_invalid_runtime_execution_preflight_shape' => 'fix_runtime_execution_preflight_shape_before_review',
            'runtime_execution_preflight_incomplete' => 'complete_runtime_execution_preflight_evidence_before_review',
            default => 'review_runtime_execution_preflight_status',
        };
    }
}
