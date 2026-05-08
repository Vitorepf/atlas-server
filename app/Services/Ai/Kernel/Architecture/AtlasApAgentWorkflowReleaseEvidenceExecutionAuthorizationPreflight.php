<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationPreflight
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_execution_authorization_preflight.v1';

    /** @var array<int,string> */
    private const REQUIRED_AUTHORIZATION_EVIDENCE = [
        'reviewed_consumer_readiness_receipt',
        'confirmed_execution_owner',
        'confirmed_payload_schema_locked',
        'confirmed_replay_or_rollback_ready',
        'confirmed_policy_and_privacy_clearance',
        'confirmed_human_authorization_required',
        'confirmed_no_auto_release',
        'confirmed_no_auto_ledger_write',
        'confirmed_no_runtime_job',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_AUTHORIZATION_EVIDENCE = [
        'authorization_scope',
        'authorization_surface',
        'execution_owner',
        'notes',
        'payload_schema',
        'rollback_reference',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessDecisionReceipt $consumerReadinessDecisionReceipt,
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
        $receipt = $this->consumerReadinessDecisionReceipt->receipt(
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
        $evidence = $this->evaluateEvidence($authorizationEvidence);
        $status = $this->status($receipt, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_execution_authorization_preflight',
            'authority' => 'ap_agent_workflow_release_evidence_execution_authorization_preflight_only_no_execution',
            'work_title' => $receipt['work_title'],
            'resolved_target_ap' => $receipt['resolved_target_ap'],
            'consumer_readiness_receipt_summary' => [
                'schema_version' => $receipt['schema_version'],
                'status' => $receipt['status'],
                'decision' => data_get($receipt, 'consumer_readiness_decision_summary.decision'),
                'target_surface' => data_get($receipt, 'consumer_readiness_decision_summary.target_surface'),
                'future_consumer_owner' => data_get($receipt, 'consumer_readiness_decision_summary.future_consumer_owner'),
                'payload_schema' => data_get($receipt, 'consumer_readiness_decision_summary.payload_schema'),
            ],
            'authorization_evidence' => $evidence,
            'authorization_target' => [
                'authorization_surface' => $authorizationEvidence['authorization_surface'] ?? null,
                'authorization_scope' => $authorizationEvidence['authorization_scope'] ?? null,
                'execution_owner' => $authorizationEvidence['execution_owner'] ?? null,
                'payload_schema' => $authorizationEvidence['payload_schema'] ?? null,
                'rollback_reference' => $authorizationEvidence['rollback_reference'] ?? null,
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
                'authorizes_execution' => false,
                'accepts_without_consumer_receipt' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $authorizationEvidence
     * @return array<string,mixed>
     */
    private function evaluateEvidence(array $authorizationEvidence): array
    {
        $failed = [];
        $shapeErrors = [];
        $allowedKeys = array_merge(self::REQUIRED_AUTHORIZATION_EVIDENCE, self::OPTIONAL_AUTHORIZATION_EVIDENCE);

        foreach ($authorizationEvidence as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                $shapeErrors[] = [
                    'id' => 'unknown_release_evidence_execution_authorization_key',
                    'key' => (string) $key,
                ];
            }
        }

        foreach (self::REQUIRED_AUTHORIZATION_EVIDENCE as $key) {
            if (! array_key_exists($key, $authorizationEvidence)) {
                $shapeErrors[] = [
                    'id' => 'missing_required_release_evidence_execution_authorization',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($authorizationEvidence[$key])) {
                $shapeErrors[] = [
                    'id' => 'release_evidence_execution_authorization_must_be_boolean',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if ($authorizationEvidence[$key] !== true) {
                $failed[] = $key;
            }
        }

        foreach (['authorization_surface', 'authorization_scope', 'execution_owner', 'payload_schema', 'rollback_reference'] as $key) {
            if (! is_string($authorizationEvidence[$key] ?? null) || trim((string) $authorizationEvidence[$key]) === '') {
                $shapeErrors[] = [
                    'id' => 'release_evidence_execution_authorization_text_required',
                    'key' => $key,
                ];
            }
        }

        return [
            'status' => $shapeErrors === []
                ? ($failed === [] ? 'complete' : 'incomplete')
                : 'invalid_shape',
            'required_keys' => self::REQUIRED_AUTHORIZATION_EVIDENCE,
            'passed_count' => count(self::REQUIRED_AUTHORIZATION_EVIDENCE) - count(array_unique($failed)),
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
        if (($receipt['status'] ?? null) !== 'consumer_readiness_acceptance_reported') {
            return 'blocked_by_consumer_readiness_decision_receipt';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_execution_authorization_preflight_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'execution_authorization_preflight_incomplete';
        }

        return 'ready_for_execution_authorization_decision';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'ready_for_execution_authorization_decision' => 'future_execution_authorization_decision_ap_may_review_preflight_without_auto_execution',
            'blocked_by_consumer_readiness_decision_receipt' => 'repair_consumer_readiness_decision_receipt_before_authorization_preflight',
            'blocked_invalid_execution_authorization_preflight_shape' => 'fix_execution_authorization_preflight_shape_before_review',
            'execution_authorization_preflight_incomplete' => 'complete_execution_authorization_preflight_evidence_before_review',
            default => 'review_execution_authorization_preflight_status',
        };
    }
}
