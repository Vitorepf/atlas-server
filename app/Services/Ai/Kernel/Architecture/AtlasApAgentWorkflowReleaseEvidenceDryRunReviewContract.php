<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceDryRunReviewContract
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_dry_run_review_contract.v1';

    /** @var array<int,string> */
    private const ALLOWED_DECISIONS = [
        'accept_dry_run_plan',
        'request_dry_run_plan_changes',
        'reject_dry_run_plan',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidenceDryRunPlanContract $dryRunPlanContract,
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
     * @return array<string,mixed>
     */
    public function review(
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
        ?string $dryRunReviewReason = null,
        ?string $candidateDecisionReason = null,
        ?string $closeoutReason = null,
        ?string $reason = null,
        ?string $docsApPath = null,
        int|string|null $targetAp = null,
        ?int $requestedApNumber = null,
        ?string $proposedSlug = null,
    ): array {
        $plan = $this->dryRunPlanContract->plan(
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
            candidateDecisionReason: $candidateDecisionReason,
            closeoutReason: $closeoutReason,
            reason: $reason,
            docsApPath: $docsApPath,
            targetAp: $targetAp,
            requestedApNumber: $requestedApNumber,
            proposedSlug: $proposedSlug,
        );
        $reviewDecision = $this->decisionPayload($dryRunReviewDecision, $dryRunReviewReason);
        $status = $this->status($plan, $reviewDecision);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_dry_run_review',
            'authority' => 'ap_agent_workflow_release_evidence_dry_run_review_only_no_execution',
            'work_title' => $plan['work_title'],
            'resolved_target_ap' => $plan['resolved_target_ap'],
            'dry_run_plan_summary' => [
                'schema_version' => $plan['schema_version'],
                'status' => $plan['status'],
                'simulation_scope' => data_get($plan, 'dry_run_plan.simulation_scope'),
                'fixture_or_corpus' => data_get($plan, 'dry_run_plan.fixture_or_corpus'),
                'success_criteria' => data_get($plan, 'dry_run_plan.success_criteria'),
                'failure_criteria' => data_get($plan, 'dry_run_plan.failure_criteria'),
            ],
            'dry_run_review_decision' => $reviewDecision,
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_review' => false,
                'publishes_release' => false,
                'emits_evidence_event' => false,
                'writes_evidence_ledger' => false,
                'creates_runtime_job' => false,
                'runs_dry_run' => false,
                'accepts_without_ready_plan' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function decisionPayload(string $dryRunReviewDecision, ?string $reason): array
    {
        $normalized = trim($dryRunReviewDecision);
        $normalizedReason = $reason === null ? null : trim($reason);
        $errors = [];

        if (! in_array($normalized, self::ALLOWED_DECISIONS, true)) {
            $errors[] = [
                'id' => 'dry_run_review_decision_not_allowed',
                'decision' => $normalized,
            ];
        }

        if (in_array($normalized, ['request_dry_run_plan_changes', 'reject_dry_run_plan'], true) && ($normalizedReason === null || $normalizedReason === '')) {
            $errors[] = [
                'id' => 'dry_run_review_reason_required',
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
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $reviewDecision
     */
    private function status(array $plan, array $reviewDecision): string
    {
        if (($reviewDecision['status'] ?? null) !== 'valid') {
            return 'blocked_invalid_dry_run_review_decision';
        }

        if (($plan['status'] ?? null) !== 'ready_for_future_dry_run_review') {
            return 'blocked_by_dry_run_plan_contract';
        }

        return match ($reviewDecision['value'] ?? null) {
            'accept_dry_run_plan' => 'dry_run_plan_accepted_by_human',
            'request_dry_run_plan_changes' => 'dry_run_plan_changes_requested_by_human',
            'reject_dry_run_plan' => 'dry_run_plan_rejected_by_human',
            default => 'blocked_invalid_dry_run_review_decision',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'dry_run_plan_accepted_by_human' => 'future_execution_ap_may_run_dry_run_with_reviewed_plan',
            'dry_run_plan_changes_requested_by_human' => 'repair_dry_run_plan_before_new_review',
            'dry_run_plan_rejected_by_human' => 'stop_dry_run_path_until_scope_reopens',
            'blocked_by_dry_run_plan_contract' => 'repair_dry_run_plan_before_review_decision',
            'blocked_invalid_dry_run_review_decision' => 'fix_dry_run_review_decision_value_or_reason',
            default => 'review_dry_run_review_status',
        };
    }
}
