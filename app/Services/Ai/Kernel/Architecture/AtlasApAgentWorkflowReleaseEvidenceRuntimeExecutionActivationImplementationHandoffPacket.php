<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationHandoffPacket
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_handoff_packet.v1';

    /** @var array<int,string> */
    private const REQUIRED_HANDOFF_EVIDENCE = [
        'reviewed_runtime_execution_activation_implementation_receipt',
        'declared_future_runtime_execution_activation_implementation_ap',
        'declared_runtime_execution_activation_implementation_package',
        'confirmed_acceptance_receipt_only',
        'confirmed_policy_receipt_attached',
        'confirmed_operator_confirmation_required',
        'confirmed_no_auto_execution',
        'confirmed_no_command_execution',
        'confirmed_no_runtime_job_created',
        'confirmed_no_ledger_write',
        'confirmed_no_payload_executed',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_HANDOFF_EVIDENCE = [
        'future_runtime_execution_activation_implementation_ap',
        'idempotency_key_strategy',
        'notes',
        'owner',
        'policy_receipt_source',
        'rollback_plan_ref',
        'runtime_execution_activation_implementation_package',
        'runtime_execution_activation_implementation_receipt_ref',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationDecisionReceipt $runtimeExecutionActivationImplementationDecisionReceipt,
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
     * @param  array<string,mixed>  $runtimeExecutionActivationImplementationHandoffEvidence
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
        string $runtimeExecutionActivationImplementationDecision,
        array $runtimeExecutionActivationImplementationHandoffEvidence,
        ?string $runtimeExecutionActivationImplementationDecisionReason = null,
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
        $receipt = $this->runtimeExecutionActivationImplementationDecisionReceipt->receipt(
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
            runtimeExecutionActivationImplementationPreflightEvidence: $runtimeExecutionActivationImplementationPreflightEvidence,
            runtimeExecutionActivationImplementationDecision: $runtimeExecutionActivationImplementationDecision,
            runtimeExecutionActivationImplementationDecisionReason: $runtimeExecutionActivationImplementationDecisionReason,
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
        $evidence = $this->evaluateEvidence($runtimeExecutionActivationImplementationHandoffEvidence);
        $status = $this->status($receipt, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_runtime_execution_activation_implementation_handoff_packet',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_handoff_only_no_execution',
            'work_title' => $receipt['work_title'],
            'resolved_target_ap' => $receipt['resolved_target_ap'],
            'runtime_execution_activation_implementation_receipt_summary' => [
                'schema_version' => $receipt['schema_version'],
                'status' => $receipt['status'],
                'decision' => data_get($receipt, 'runtime_execution_activation_implementation_decision_summary.decision'),
                'reason' => data_get($receipt, 'runtime_execution_activation_implementation_decision_summary.reason'),
                'activation_surface' => data_get($receipt, 'runtime_execution_activation_implementation_decision_summary.activation_surface'),
                'activation_entrypoint' => data_get($receipt, 'runtime_execution_activation_implementation_decision_summary.activation_entrypoint'),
                'operator_owner' => data_get($receipt, 'runtime_execution_activation_implementation_decision_summary.operator_owner'),
                'activation_boundary' => data_get($receipt, 'runtime_execution_activation_implementation_decision_summary.activation_boundary'),
                'replay_window' => data_get($receipt, 'runtime_execution_activation_implementation_decision_summary.replay_window'),
                'rollback_plan_ref' => data_get($receipt, 'runtime_execution_activation_implementation_decision_summary.rollback_plan_ref'),
                'idempotency_key_strategy' => data_get($receipt, 'runtime_execution_activation_implementation_decision_summary.idempotency_key_strategy'),
            ],
            'runtime_execution_activation_implementation_handoff_evidence' => $evidence,
            'handoff_target' => [
                'future_runtime_execution_activation_implementation_ap' => $runtimeExecutionActivationImplementationHandoffEvidence['future_runtime_execution_activation_implementation_ap'] ?? null,
                'owner' => $runtimeExecutionActivationImplementationHandoffEvidence['owner'] ?? null,
            ],
            'runtime_execution_activation_implementation_package' => $runtimeExecutionActivationImplementationHandoffEvidence['runtime_execution_activation_implementation_package'] ?? null,
            'runtime_execution_activation_implementation_receipt_ref' => $runtimeExecutionActivationImplementationHandoffEvidence['runtime_execution_activation_implementation_receipt_ref'] ?? null,
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
                'executes_authorized_work' => false,
                'performs_activation' => false,
                'executes_runtime_payload' => false,
                'accepts_without_runtime_execution_activation_implementation_receipt' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $handoffEvidence
     * @return array<string,mixed>
     */
    private function evaluateEvidence(array $handoffEvidence): array
    {
        $failed = [];
        $shapeErrors = [];
        $allowedKeys = array_merge(self::REQUIRED_HANDOFF_EVIDENCE, self::OPTIONAL_HANDOFF_EVIDENCE);

        foreach ($handoffEvidence as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                $shapeErrors[] = [
                    'id' => 'unknown_release_evidence_runtime_execution_activation_implementation_handoff_key',
                    'key' => (string) $key,
                ];
            }
        }

        foreach (self::REQUIRED_HANDOFF_EVIDENCE as $key) {
            if (! array_key_exists($key, $handoffEvidence)) {
                $shapeErrors[] = [
                    'id' => 'missing_required_release_evidence_runtime_execution_activation_implementation_handoff',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($handoffEvidence[$key])) {
                $shapeErrors[] = [
                    'id' => 'release_evidence_runtime_execution_activation_implementation_handoff_must_be_boolean',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if ($handoffEvidence[$key] !== true) {
                $failed[] = $key;
            }
        }

        foreach (['future_runtime_execution_activation_implementation_ap', 'runtime_execution_activation_implementation_package', 'owner', 'runtime_execution_activation_implementation_receipt_ref'] as $key) {
            if (! is_string($handoffEvidence[$key] ?? null) || trim((string) $handoffEvidence[$key]) === '') {
                $shapeErrors[] = [
                    'id' => 'release_evidence_runtime_execution_activation_implementation_handoff_text_required',
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
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $evidence
     */
    private function status(array $receipt, array $evidence): string
    {
        if (($receipt['status'] ?? null) !== 'runtime_execution_activation_implementation_acceptance_reported') {
            return 'blocked_by_runtime_execution_activation_implementation_decision_receipt';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_runtime_execution_activation_implementation_handoff_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'runtime_execution_activation_implementation_handoff_evidence_incomplete';
        }

        return 'runtime_execution_activation_implementation_handoff_ready_for_future_execution_ap';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'runtime_execution_activation_implementation_handoff_ready_for_future_execution_ap' => 'future_runtime_execution_activation_implementation_ap_may_review_handoff_without_auto_execution',
            'blocked_by_runtime_execution_activation_implementation_decision_receipt' => 'repair_or_accept_runtime_execution_activation_implementation_decision_before_handoff',
            'blocked_invalid_runtime_execution_activation_implementation_handoff_shape' => 'fix_runtime_execution_activation_implementation_handoff_shape_before_review',
            'runtime_execution_activation_implementation_handoff_evidence_incomplete' => 'complete_runtime_execution_activation_implementation_handoff_evidence_before_review',
            default => 'review_runtime_execution_activation_implementation_handoff_status',
        };
    }
}
