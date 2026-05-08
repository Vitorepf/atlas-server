<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceExecutionImplementationPreflight
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_execution_implementation_preflight.v1';

    /** @var array<int,string> */
    private const REQUIRED_PREFLIGHT_EVIDENCE = [
        'reviewed_execution_authorization_handoff',
        'declared_execution_surface',
        'declared_execution_entrypoint',
        'declared_mutation_boundary',
        'confirmed_operator_owner',
        'confirmed_policy_receipt_required',
        'confirmed_replay_window_defined',
        'confirmed_rollback_plan_available',
        'confirmed_evidence_write_schema_locked',
        'confirmed_no_immediate_execution',
        'confirmed_no_background_job_created',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_PREFLIGHT_EVIDENCE = [
        'evidence_event_schema',
        'execution_entrypoint',
        'execution_surface',
        'mutation_boundary',
        'notes',
        'operator_owner',
        'replay_window',
        'rollback_plan_ref',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationHandoffPacket $executionAuthorizationHandoffPacket,
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
        $handoff = $this->executionAuthorizationHandoffPacket->packet(
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
        $evidence = $this->evaluateEvidence($implementationPreflightEvidence);
        $status = $this->status($handoff, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_execution_implementation_preflight',
            'authority' => 'ap_agent_workflow_release_evidence_execution_implementation_preflight_only_no_execution',
            'work_title' => $handoff['work_title'],
            'resolved_target_ap' => $handoff['resolved_target_ap'],
            'execution_authorization_handoff_summary' => [
                'schema_version' => $handoff['schema_version'],
                'status' => $handoff['status'],
                'future_execution_ap' => data_get($handoff, 'handoff_target.future_execution_ap'),
                'owner' => data_get($handoff, 'handoff_target.owner'),
                'execution_package' => $handoff['execution_package'] ?? null,
                'authorization_receipt_ref' => $handoff['authorization_receipt_ref'] ?? null,
            ],
            'execution_implementation_preflight_evidence' => $evidence,
            'execution_implementation_target' => [
                'execution_surface' => $implementationPreflightEvidence['execution_surface'] ?? null,
                'execution_entrypoint' => $implementationPreflightEvidence['execution_entrypoint'] ?? null,
                'operator_owner' => $implementationPreflightEvidence['operator_owner'] ?? null,
                'mutation_boundary' => $implementationPreflightEvidence['mutation_boundary'] ?? null,
                'evidence_event_schema' => $implementationPreflightEvidence['evidence_event_schema'] ?? null,
                'replay_window' => $implementationPreflightEvidence['replay_window'] ?? null,
                'rollback_plan_ref' => $implementationPreflightEvidence['rollback_plan_ref'] ?? null,
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
                'accepts_without_authorization_handoff' => false,
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
                    'id' => 'unknown_release_evidence_execution_implementation_preflight_key',
                    'key' => (string) $key,
                ];
            }
        }

        foreach (self::REQUIRED_PREFLIGHT_EVIDENCE as $key) {
            if (! array_key_exists($key, $preflightEvidence)) {
                $shapeErrors[] = [
                    'id' => 'missing_required_release_evidence_execution_implementation_preflight',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($preflightEvidence[$key])) {
                $shapeErrors[] = [
                    'id' => 'release_evidence_execution_implementation_preflight_must_be_boolean',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if ($preflightEvidence[$key] !== true) {
                $failed[] = $key;
            }
        }

        foreach (['execution_surface', 'execution_entrypoint', 'operator_owner', 'mutation_boundary', 'evidence_event_schema', 'replay_window', 'rollback_plan_ref'] as $key) {
            if (! is_string($preflightEvidence[$key] ?? null) || trim((string) $preflightEvidence[$key]) === '') {
                $shapeErrors[] = [
                    'id' => 'release_evidence_execution_implementation_preflight_text_required',
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
        if (($handoff['status'] ?? null) !== 'execution_authorization_handoff_ready_for_future_execution_ap') {
            return 'blocked_by_execution_authorization_handoff_packet';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_execution_implementation_preflight_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'execution_implementation_preflight_incomplete';
        }

        return 'ready_for_execution_implementation_review';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'ready_for_execution_implementation_review' => 'future_execution_implementation_review_ap_may_review_preflight_without_execution',
            'blocked_by_execution_authorization_handoff_packet' => 'repair_execution_authorization_handoff_before_implementation_preflight',
            'blocked_invalid_execution_implementation_preflight_shape' => 'fix_execution_implementation_preflight_shape_before_review',
            'execution_implementation_preflight_incomplete' => 'complete_execution_implementation_preflight_evidence_before_review',
            default => 'review_execution_implementation_preflight_status',
        };
    }
}
