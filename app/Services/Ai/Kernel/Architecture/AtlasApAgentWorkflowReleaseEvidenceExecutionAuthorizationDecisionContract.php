<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationDecisionContract
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_execution_authorization_decision_contract.v1';

    /** @var array<int,string> */
    private const ALLOWED_DECISIONS = [
        'authorize_execution',
        'request_execution_authorization_changes',
        'reject_execution_authorization',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidenceExecutionAuthorizationPreflight $executionAuthorizationPreflight,
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
    public function decide(
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
        $preflight = $this->executionAuthorizationPreflight->evaluate(
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
        $decisionPayload = $this->decisionPayload($authorizationDecision, $authorizationDecisionReason);
        $status = $this->status($preflight, $decisionPayload);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_execution_authorization_decision',
            'authority' => 'ap_agent_workflow_release_evidence_execution_authorization_decision_only_no_execution',
            'work_title' => $preflight['work_title'],
            'resolved_target_ap' => $preflight['resolved_target_ap'],
            'authorization_preflight_summary' => [
                'schema_version' => $preflight['schema_version'],
                'status' => $preflight['status'],
                'authorization_surface' => data_get($preflight, 'authorization_target.authorization_surface'),
                'authorization_scope' => data_get($preflight, 'authorization_target.authorization_scope'),
                'execution_owner' => data_get($preflight, 'authorization_target.execution_owner'),
                'payload_schema' => data_get($preflight, 'authorization_target.payload_schema'),
                'rollback_reference' => data_get($preflight, 'authorization_target.rollback_reference'),
            ],
            'execution_authorization_decision' => $decisionPayload,
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_decision' => false,
                'publishes_release' => false,
                'emits_evidence_event' => false,
                'writes_evidence_ledger' => false,
                'creates_runtime_job' => false,
                'runs_dry_run' => false,
                'executes_authorized_work' => false,
                'accepts_without_ready_preflight' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decisionPayload(string $authorizationDecision, ?string $reason): array
    {
        $normalized = trim($authorizationDecision);
        $normalizedReason = $reason === null ? null : trim($reason);
        $errors = [];

        if (! in_array($normalized, self::ALLOWED_DECISIONS, true)) {
            $errors[] = [
                'id' => 'execution_authorization_decision_not_allowed',
                'decision' => $normalized,
            ];
        }

        if (in_array($normalized, ['authorize_execution', 'request_execution_authorization_changes', 'reject_execution_authorization'], true) && ($normalizedReason === null || $normalizedReason === '')) {
            $errors[] = [
                'id' => 'execution_authorization_decision_reason_required',
                'decision' => $normalized,
            ];
        }

        return [
            'value' => $normalized,
            'reason' => $normalizedReason,
            'allowed_values' => self::ALLOWED_DECISIONS,
            'status' => $errors === [] ? 'valid' : 'invalid',
            'error_count' => count($errors),
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string,mixed>  $preflight
     * @param  array<string,mixed>  $decisionPayload
     */
    private function status(array $preflight, array $decisionPayload): string
    {
        if (($decisionPayload['status'] ?? null) !== 'valid') {
            return 'blocked_invalid_execution_authorization_decision';
        }

        if (($preflight['status'] ?? null) !== 'ready_for_execution_authorization_decision') {
            return 'blocked_by_execution_authorization_preflight';
        }

        return match ($decisionPayload['value'] ?? null) {
            'authorize_execution' => 'execution_authorization_approved_by_human',
            'request_execution_authorization_changes' => 'execution_authorization_changes_requested_by_human',
            'reject_execution_authorization' => 'execution_authorization_rejected_by_human',
            default => 'blocked_invalid_execution_authorization_decision',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'execution_authorization_approved_by_human' => 'future_execution_receipt_ap_may_record_authorization_without_executing',
            'execution_authorization_changes_requested_by_human' => 'repair_execution_authorization_preflight_before_new_decision',
            'execution_authorization_rejected_by_human' => 'stop_execution_authorization_path_until_scope_reopens',
            'blocked_by_execution_authorization_preflight' => 'repair_execution_authorization_preflight_before_decision',
            'blocked_invalid_execution_authorization_decision' => 'fix_execution_authorization_decision_value_or_reason',
            default => 'review_execution_authorization_decision_status',
        };
    }
}
