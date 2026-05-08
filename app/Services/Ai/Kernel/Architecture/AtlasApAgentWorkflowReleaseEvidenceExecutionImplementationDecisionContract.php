<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceExecutionImplementationDecisionContract
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_execution_implementation_decision_contract.v1';

    /** @var array<int,string> */
    private const ALLOWED_DECISIONS = [
        'accept_execution_implementation',
        'request_execution_implementation_changes',
        'reject_execution_implementation',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidenceExecutionImplementationPreflight $executionImplementationPreflight,
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
        array $executionAuthorizationHandoffEvidence,
        array $implementationPreflightEvidence,
        string $implementationDecision,
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
        $preflight = $this->executionImplementationPreflight->evaluate(
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
        $decisionPayload = $this->decisionPayload($implementationDecision, $implementationDecisionReason);
        $status = $this->status($preflight, $decisionPayload);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_execution_implementation_decision',
            'authority' => 'ap_agent_workflow_release_evidence_execution_implementation_decision_only_no_execution',
            'work_title' => $preflight['work_title'],
            'resolved_target_ap' => $preflight['resolved_target_ap'],
            'execution_implementation_preflight_summary' => [
                'schema_version' => $preflight['schema_version'],
                'status' => $preflight['status'],
                'execution_surface' => data_get($preflight, 'execution_implementation_target.execution_surface'),
                'execution_entrypoint' => data_get($preflight, 'execution_implementation_target.execution_entrypoint'),
                'operator_owner' => data_get($preflight, 'execution_implementation_target.operator_owner'),
                'mutation_boundary' => data_get($preflight, 'execution_implementation_target.mutation_boundary'),
                'evidence_event_schema' => data_get($preflight, 'execution_implementation_target.evidence_event_schema'),
                'replay_window' => data_get($preflight, 'execution_implementation_target.replay_window'),
                'rollback_plan_ref' => data_get($preflight, 'execution_implementation_target.rollback_plan_ref'),
            ],
            'execution_implementation_decision' => $decisionPayload,
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
    private function decisionPayload(string $implementationDecision, ?string $reason): array
    {
        $normalized = trim($implementationDecision);
        $normalizedReason = $reason === null ? null : trim($reason);
        $errors = [];

        if (! in_array($normalized, self::ALLOWED_DECISIONS, true)) {
            $errors[] = [
                'id' => 'execution_implementation_decision_not_allowed',
                'decision' => $normalized,
            ];
        }

        if (in_array($normalized, self::ALLOWED_DECISIONS, true) && ($normalizedReason === null || $normalizedReason === '')) {
            $errors[] = [
                'id' => 'execution_implementation_decision_reason_required',
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
            return 'blocked_invalid_execution_implementation_decision';
        }

        if (($preflight['status'] ?? null) !== 'ready_for_execution_implementation_review') {
            return 'blocked_by_execution_implementation_preflight';
        }

        return match ($decisionPayload['value'] ?? null) {
            'accept_execution_implementation' => 'execution_implementation_accepted_by_human',
            'request_execution_implementation_changes' => 'execution_implementation_changes_requested_by_human',
            'reject_execution_implementation' => 'execution_implementation_rejected_by_human',
            default => 'blocked_invalid_execution_implementation_decision',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'execution_implementation_accepted_by_human' => 'future_execution_implementation_receipt_ap_may_report_acceptance_without_execution',
            'execution_implementation_changes_requested_by_human' => 'repair_execution_implementation_preflight_before_new_decision',
            'execution_implementation_rejected_by_human' => 'stop_execution_implementation_path_until_scope_reopens',
            'blocked_by_execution_implementation_preflight' => 'repair_execution_implementation_preflight_before_decision',
            'blocked_invalid_execution_implementation_decision' => 'fix_execution_implementation_decision_value_or_reason',
            default => 'review_execution_implementation_decision_status',
        };
    }
}
