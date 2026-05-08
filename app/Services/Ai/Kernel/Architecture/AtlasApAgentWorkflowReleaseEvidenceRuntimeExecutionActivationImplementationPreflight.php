<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationPreflight
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_preflight.v1';

    /** @var array<int,string> */
    private const REQUIRED_PREFLIGHT_EVIDENCE = [
        'reviewed_runtime_execution_activation_handoff',
        'declared_activation_surface',
        'declared_activation_entrypoint',
        'declared_activation_boundary',
        'declared_evidence_event_schema',
        'confirmed_operator_owner',
        'confirmed_policy_receipt_required',
        'confirmed_replay_window_defined',
        'confirmed_rollback_plan_available',
        'confirmed_idempotency_key_strategy',
        'confirmed_no_immediate_activation',
        'confirmed_no_runtime_job_created',
        'confirmed_no_ledger_write',
        'confirmed_no_payload_executed',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_PREFLIGHT_EVIDENCE = [
        'evidence_event_schema',
        'idempotency_key_strategy',
        'notes',
        'operator_owner',
        'activation_boundary',
        'replay_window',
        'rollback_plan_ref',
        'activation_entrypoint',
        'activation_surface',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationHandoffPacket $runtimeExecutionActivationHandoffPacket,
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
     * @param  array<string,mixed>  $runtimeExecutionHandoffEvidence
     * @param  array<string,mixed>  $runtimeExecutionImplementationPreflightEvidence
     * @param  array<string,mixed>  $runtimeExecutionActivationPreflightEvidence
     * @param  array<string,mixed>  $runtimeExecutionActivationHandoffEvidence
     * @param  array<string,mixed>  $runtimeExecutionActivationImplementationPreflightEvidence
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
        string $runtimeExecutionDecision,
        array $runtimeExecutionHandoffEvidence,
        array $runtimeExecutionImplementationPreflightEvidence,
        string $runtimeExecutionImplementationDecision,
        array $runtimeExecutionActivationPreflightEvidence,
        string $runtimeExecutionActivationDecision,
        array $runtimeExecutionActivationHandoffEvidence,
        array $runtimeExecutionActivationImplementationPreflightEvidence,
        ?string $runtimeExecutionActivationDecisionReason = null,
        ?string $runtimeExecutionImplementationDecisionReason = null,
        ?string $runtimeExecutionDecisionReason = null,
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
        $handoff = $this->runtimeExecutionActivationHandoffPacket->packet(
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
            runtimePreflightEvidence: $runtimePreflightEvidence,
            runtimeExecutionDecision: $runtimeExecutionDecision,
            runtimeExecutionHandoffEvidence: $runtimeExecutionHandoffEvidence,
            runtimeExecutionImplementationPreflightEvidence: $runtimeExecutionImplementationPreflightEvidence,
            runtimeExecutionImplementationDecision: $runtimeExecutionImplementationDecision,
            runtimeExecutionActivationPreflightEvidence: $runtimeExecutionActivationPreflightEvidence,
            runtimeExecutionActivationDecision: $runtimeExecutionActivationDecision,
            runtimeExecutionActivationHandoffEvidence: $runtimeExecutionActivationHandoffEvidence,
            runtimeExecutionActivationDecisionReason: $runtimeExecutionActivationDecisionReason,
            runtimeExecutionImplementationDecisionReason: $runtimeExecutionImplementationDecisionReason,
            runtimeExecutionDecisionReason: $runtimeExecutionDecisionReason,
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
        $evidence = $this->evaluateEvidence($runtimeExecutionActivationImplementationPreflightEvidence);
        $status = $this->status($handoff, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_runtime_execution_activation_implementation_preflight',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_preflight_only_no_execution',
            'work_title' => $handoff['work_title'],
            'resolved_target_ap' => $handoff['resolved_target_ap'],
            'runtime_execution_activation_handoff_summary' => [
                'schema_version' => $handoff['schema_version'],
                'status' => $handoff['status'],
                'future_runtime_execution_activation_ap' => data_get($handoff, 'handoff_target.future_runtime_execution_activation_ap'),
                'owner' => data_get($handoff, 'handoff_target.owner'),
                'runtime_execution_activation_package' => $handoff['runtime_execution_activation_package'] ?? null,
                'runtime_execution_activation_receipt_ref' => $handoff['runtime_execution_activation_receipt_ref'] ?? null,
            ],
            'runtime_execution_activation_implementation_preflight_evidence' => $evidence,
            'runtime_execution_activation_implementation_target' => [
                'activation_surface' => $runtimeExecutionActivationImplementationPreflightEvidence['activation_surface'] ?? null,
                'activation_entrypoint' => $runtimeExecutionActivationImplementationPreflightEvidence['activation_entrypoint'] ?? null,
                'operator_owner' => $runtimeExecutionActivationImplementationPreflightEvidence['operator_owner'] ?? null,
                'activation_boundary' => $runtimeExecutionActivationImplementationPreflightEvidence['activation_boundary'] ?? null,
                'evidence_event_schema' => $runtimeExecutionActivationImplementationPreflightEvidence['evidence_event_schema'] ?? null,
                'replay_window' => $runtimeExecutionActivationImplementationPreflightEvidence['replay_window'] ?? null,
                'rollback_plan_ref' => $runtimeExecutionActivationImplementationPreflightEvidence['rollback_plan_ref'] ?? null,
                'idempotency_key_strategy' => $runtimeExecutionActivationImplementationPreflightEvidence['idempotency_key_strategy'] ?? null,
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
                'accepts_without_runtime_execution_activation_handoff' => false,
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
                    'id' => 'unknown_release_evidence_runtime_execution_activation_implementation_preflight_key',
                    'key' => (string) $key,
                ];
            }
        }

        foreach (self::REQUIRED_PREFLIGHT_EVIDENCE as $key) {
            if (! array_key_exists($key, $preflightEvidence)) {
                $shapeErrors[] = [
                    'id' => 'missing_required_release_evidence_runtime_execution_activation_implementation_preflight',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($preflightEvidence[$key])) {
                $shapeErrors[] = [
                    'id' => 'release_evidence_runtime_execution_activation_implementation_preflight_must_be_boolean',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if ($preflightEvidence[$key] !== true) {
                $failed[] = $key;
            }
        }

        foreach (['activation_surface', 'activation_entrypoint', 'operator_owner', 'activation_boundary', 'evidence_event_schema', 'replay_window', 'rollback_plan_ref', 'idempotency_key_strategy'] as $key) {
            if (! is_string($preflightEvidence[$key] ?? null) || trim((string) $preflightEvidence[$key]) === '') {
                $shapeErrors[] = [
                    'id' => 'release_evidence_runtime_execution_activation_implementation_preflight_text_required',
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
     * @param  array<string,mixed>  $handoff
     * @param  array<string,mixed>  $evidence
     */
    private function status(array $handoff, array $evidence): string
    {
        if (($handoff['status'] ?? null) !== 'runtime_execution_activation_handoff_ready_for_future_execution_ap') {
            return 'blocked_by_runtime_execution_activation_handoff_packet';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_runtime_execution_activation_implementation_preflight_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'runtime_execution_activation_implementation_preflight_incomplete';
        }

        return 'ready_for_runtime_execution_activation_implementation_review';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'ready_for_runtime_execution_activation_implementation_review' => 'future_runtime_execution_activation_implementation_review_ap_may_review_preflight_without_activation',
            'blocked_by_runtime_execution_activation_handoff_packet' => 'repair_runtime_execution_activation_handoff_before_implementation_preflight',
            'blocked_invalid_runtime_execution_activation_implementation_preflight_shape' => 'fix_runtime_execution_activation_implementation_preflight_shape_before_review',
            'runtime_execution_activation_implementation_preflight_incomplete' => 'complete_runtime_execution_activation_implementation_preflight_evidence_before_review',
            default => 'review_runtime_execution_activation_implementation_preflight_status',
        };
    }
}
