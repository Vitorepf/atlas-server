<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessDecisionContract
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_consumer_readiness_decision_contract.v1';

    /** @var array<int,string> */
    private const ALLOWED_DECISIONS = [
        'accept_consumer_readiness',
        'request_consumer_readiness_changes',
        'reject_consumer_readiness',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidenceConsumerReadinessContract $consumerReadinessContract,
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
        $readiness = $this->consumerReadinessContract->evaluate(
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
        $decisionEvidence = $this->decisionEvidence($consumerReadinessDecision, $consumerReadinessDecisionReason);
        $status = $this->status($readiness, $decisionEvidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_consumer_readiness_decision',
            'authority' => 'ap_agent_workflow_release_evidence_consumer_readiness_decision_only_no_execution',
            'work_title' => $readiness['work_title'],
            'resolved_target_ap' => $readiness['resolved_target_ap'],
            'consumer_readiness_summary' => [
                'schema_version' => $readiness['schema_version'],
                'status' => $readiness['status'],
                'target_surface' => data_get($readiness, 'consumer_target.target_surface'),
                'future_consumer_owner' => data_get($readiness, 'consumer_target.future_consumer_owner'),
                'payload_schema' => data_get($readiness, 'consumer_target.payload_schema'),
                'replay_or_rollback_plan' => $readiness['replay_or_rollback_plan'] ?? null,
            ],
            'consumer_readiness_decision' => $decisionEvidence,
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
                'accepts_without_ready_consumer_readiness' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decisionEvidence(string $decision, ?string $reason): array
    {
        $errors = [];
        $requiresReason = in_array($decision, ['request_consumer_readiness_changes', 'reject_consumer_readiness'], true);

        if (! in_array($decision, self::ALLOWED_DECISIONS, true)) {
            $errors[] = [
                'id' => 'invalid_consumer_readiness_decision',
                'decision' => $decision,
            ];
        }

        if ($requiresReason && (! is_string($reason) || trim($reason) === '')) {
            $errors[] = [
                'id' => 'consumer_readiness_decision_reason_required',
                'decision' => $decision,
            ];
        }

        return [
            'decision' => $decision,
            'reason' => $reason,
            'allowed_decisions' => self::ALLOWED_DECISIONS,
            'error_count' => count($errors),
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string,mixed>  $readiness
     * @param  array<string,mixed>  $decisionEvidence
     */
    private function status(array $readiness, array $decisionEvidence): string
    {
        if (($decisionEvidence['error_count'] ?? 0) > 0) {
            return 'blocked_invalid_consumer_readiness_decision';
        }

        if (($readiness['status'] ?? null) !== 'consumer_readiness_ready_for_future_release_or_ledger_decision') {
            return 'blocked_by_consumer_readiness_contract';
        }

        return match ($decisionEvidence['decision'] ?? null) {
            'accept_consumer_readiness' => 'consumer_readiness_accepted_by_human',
            'request_consumer_readiness_changes' => 'consumer_readiness_changes_requested_by_human',
            'reject_consumer_readiness' => 'consumer_readiness_rejected_by_human',
            default => 'blocked_invalid_consumer_readiness_decision',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'consumer_readiness_accepted_by_human' => 'future_release_or_ledger_execution_ap_may_consume_accepted_readiness_without_auto_execution',
            'consumer_readiness_changes_requested_by_human' => 'repair_consumer_readiness_before_new_decision',
            'consumer_readiness_rejected_by_human' => 'stop_release_or_ledger_path_until_scope_reopens',
            'blocked_by_consumer_readiness_contract' => 'repair_consumer_readiness_before_decision',
            'blocked_invalid_consumer_readiness_decision' => 'fix_consumer_readiness_decision_value_or_reason',
            default => 'review_consumer_readiness_decision_status',
        };
    }
}
