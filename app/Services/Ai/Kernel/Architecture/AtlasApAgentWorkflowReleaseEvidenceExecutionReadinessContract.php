<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceExecutionReadinessContract
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_execution_readiness_contract.v1';

    /** @var array<int,string> */
    private const REQUIRED_READINESS_EVIDENCE = [
        'reviewed_candidate_decision_receipt',
        'confirmed_execution_ap_owner',
        'confirmed_payload_schema_final',
        'confirmed_replay_or_rollback_plan',
        'confirmed_privacy_and_policy_review',
        'confirmed_dry_run_required',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_READINESS_EVIDENCE = [
        'commands',
        'execution_ap',
        'notes',
        'owner',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidenceCandidateDecisionReceipt $candidateDecisionReceipt,
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
        ?string $candidateDecisionReason = null,
        ?string $closeoutReason = null,
        ?string $reason = null,
        ?string $docsApPath = null,
        int|string|null $targetAp = null,
        ?int $requestedApNumber = null,
        ?string $proposedSlug = null,
    ): array {
        $receipt = $this->candidateDecisionReceipt->receipt(
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
            candidateDecisionReason: $candidateDecisionReason,
            closeoutReason: $closeoutReason,
            reason: $reason,
            docsApPath: $docsApPath,
            targetAp: $targetAp,
            requestedApNumber: $requestedApNumber,
            proposedSlug: $proposedSlug,
        );
        $evidence = $this->evaluateEvidence($readinessEvidence);
        $status = $this->status($receipt, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_execution_readiness',
            'authority' => 'ap_agent_workflow_release_evidence_execution_readiness_only_no_execution',
            'work_title' => $receipt['work_title'],
            'resolved_target_ap' => $receipt['resolved_target_ap'],
            'candidate_decision_receipt_summary' => [
                'schema_version' => $receipt['schema_version'],
                'status' => $receipt['status'],
                'decision' => data_get($receipt, 'candidate_decision_summary.decision'),
                'candidate_kind' => data_get($receipt, 'candidate_decision_summary.candidate_kind'),
                'payload_schema' => data_get($receipt, 'candidate_decision_summary.payload_schema'),
            ],
            'readiness_evidence' => $evidence,
            'execution_target' => [
                'execution_ap' => $readinessEvidence['execution_ap'] ?? null,
                'owner' => $readinessEvidence['owner'] ?? null,
            ],
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_readiness' => false,
                'publishes_release' => false,
                'emits_evidence_event' => false,
                'writes_evidence_ledger' => false,
                'creates_runtime_job' => false,
                'replaces_execution_ap' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $readinessEvidence
     * @return array<string,mixed>
     */
    private function evaluateEvidence(array $readinessEvidence): array
    {
        $failed = [];
        $shapeErrors = [];
        $allowedKeys = array_merge(self::REQUIRED_READINESS_EVIDENCE, self::OPTIONAL_READINESS_EVIDENCE);

        foreach ($readinessEvidence as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                $shapeErrors[] = [
                    'id' => 'unknown_release_evidence_execution_readiness_key',
                    'key' => (string) $key,
                ];
            }
        }

        foreach (self::REQUIRED_READINESS_EVIDENCE as $key) {
            if (! array_key_exists($key, $readinessEvidence)) {
                $shapeErrors[] = [
                    'id' => 'missing_required_release_evidence_execution_readiness',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($readinessEvidence[$key])) {
                $shapeErrors[] = [
                    'id' => 'release_evidence_execution_readiness_must_be_boolean',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if ($readinessEvidence[$key] !== true) {
                $failed[] = $key;
            }
        }

        return [
            'status' => $shapeErrors === []
                ? ($failed === [] ? 'complete' : 'incomplete')
                : 'invalid_shape',
            'required_keys' => self::REQUIRED_READINESS_EVIDENCE,
            'passed_count' => count(self::REQUIRED_READINESS_EVIDENCE) - count(array_unique($failed)),
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
        if (($receipt['status'] ?? null) !== 'release_evidence_candidate_acceptance_reported') {
            return 'blocked_by_candidate_decision_receipt';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_execution_readiness_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'release_evidence_execution_readiness_incomplete';
        }

        return 'ready_for_future_release_or_ledger_execution_ap';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'ready_for_future_release_or_ledger_execution_ap' => 'future_execution_ap_may_review_readiness_without_auto_execution',
            'blocked_by_candidate_decision_receipt' => 'repair_candidate_decision_receipt_before_execution_readiness',
            'blocked_invalid_execution_readiness_shape' => 'fix_execution_readiness_evidence_shape_before_review',
            'release_evidence_execution_readiness_incomplete' => 'complete_execution_readiness_evidence_before_review',
            default => 'review_execution_readiness_status',
        };
    }
}
