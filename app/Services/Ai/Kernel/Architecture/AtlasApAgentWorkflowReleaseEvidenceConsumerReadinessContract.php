<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessContract
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_consumer_readiness_contract.v1';

    /** @var array<int,string> */
    private const REQUIRED_READINESS_EVIDENCE = [
        'reviewed_post_dry_run_handoff',
        'confirmed_future_consumer_owner',
        'confirmed_payload_schema_final',
        'confirmed_policy_and_privacy_review',
        'confirmed_replay_or_rollback_plan',
        'confirmed_no_auto_release',
        'confirmed_no_auto_ledger_write',
        'confirmed_no_runtime_job',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_READINESS_EVIDENCE = [
        'future_consumer_owner',
        'notes',
        'payload_schema',
        'replay_or_rollback_plan',
        'target_surface',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidencePostDryRunHandoffPacket $postDryRunHandoffPacket,
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
        $handoff = $this->postDryRunHandoffPacket->packet(
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
        $evidence = $this->evaluateEvidence($consumerReadinessEvidence);
        $status = $this->status($handoff, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_consumer_readiness',
            'authority' => 'ap_agent_workflow_release_evidence_consumer_readiness_only_no_execution',
            'work_title' => $handoff['work_title'],
            'resolved_target_ap' => $handoff['resolved_target_ap'],
            'post_dry_run_handoff_summary' => [
                'schema_version' => $handoff['schema_version'],
                'status' => $handoff['status'],
                'future_consumer_ap' => data_get($handoff, 'handoff_target.future_consumer_ap'),
                'handoff_owner' => data_get($handoff, 'handoff_target.owner'),
                'handoff_package' => data_get($handoff, 'handoff_package'),
            ],
            'consumer_readiness_evidence' => $evidence,
            'consumer_target' => [
                'target_surface' => $consumerReadinessEvidence['target_surface'] ?? null,
                'future_consumer_owner' => $consumerReadinessEvidence['future_consumer_owner'] ?? null,
                'payload_schema' => $consumerReadinessEvidence['payload_schema'] ?? null,
            ],
            'replay_or_rollback_plan' => $consumerReadinessEvidence['replay_or_rollback_plan'] ?? null,
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_readiness' => false,
                'publishes_release' => false,
                'emits_evidence_event' => false,
                'writes_evidence_ledger' => false,
                'creates_runtime_job' => false,
                'runs_dry_run' => false,
                'accepts_without_ready_handoff' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $consumerReadinessEvidence
     * @return array<string,mixed>
     */
    private function evaluateEvidence(array $consumerReadinessEvidence): array
    {
        $failed = [];
        $shapeErrors = [];
        $allowedKeys = array_merge(self::REQUIRED_READINESS_EVIDENCE, self::OPTIONAL_READINESS_EVIDENCE);

        foreach ($consumerReadinessEvidence as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                $shapeErrors[] = [
                    'id' => 'unknown_release_evidence_consumer_readiness_key',
                    'key' => (string) $key,
                ];
            }
        }

        foreach (self::REQUIRED_READINESS_EVIDENCE as $key) {
            if (! array_key_exists($key, $consumerReadinessEvidence)) {
                $shapeErrors[] = [
                    'id' => 'missing_required_release_evidence_consumer_readiness',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($consumerReadinessEvidence[$key])) {
                $shapeErrors[] = [
                    'id' => 'release_evidence_consumer_readiness_must_be_boolean',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if ($consumerReadinessEvidence[$key] !== true) {
                $failed[] = $key;
            }
        }

        foreach (['target_surface', 'future_consumer_owner', 'payload_schema', 'replay_or_rollback_plan'] as $key) {
            if (! is_string($consumerReadinessEvidence[$key] ?? null) || trim((string) $consumerReadinessEvidence[$key]) === '') {
                $shapeErrors[] = [
                    'id' => 'release_evidence_consumer_readiness_text_required',
                    'key' => $key,
                ];
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
     * @param  array<string,mixed>  $handoff
     * @param  array<string,mixed>  $evidence
     */
    private function status(array $handoff, array $evidence): string
    {
        if (($handoff['status'] ?? null) !== 'post_dry_run_handoff_ready_for_future_release_or_ledger_ap') {
            return 'blocked_by_post_dry_run_handoff_packet';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_consumer_readiness_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'consumer_readiness_evidence_incomplete';
        }

        return 'consumer_readiness_ready_for_future_release_or_ledger_decision';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'consumer_readiness_ready_for_future_release_or_ledger_decision' => 'future_release_or_ledger_decision_ap_may_review_readiness_without_auto_execution',
            'blocked_by_post_dry_run_handoff_packet' => 'repair_post_dry_run_handoff_before_consumer_readiness',
            'blocked_invalid_consumer_readiness_shape' => 'fix_consumer_readiness_shape_before_review',
            'consumer_readiness_evidence_incomplete' => 'complete_consumer_readiness_evidence_before_review',
            default => 'review_consumer_readiness_status',
        };
    }
}
