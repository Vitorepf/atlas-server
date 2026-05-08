<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowHumanDecisionContract
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_human_decision_contract.v1';

    /** @var array<int,string> */
    private const ALLOWED_DECISIONS = [
        'accept',
        'request_changes',
        'reject',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowHumanReviewPacket $humanReviewPacket,
    ) {}

    /**
     * @param  array<int,string>  $intendedPaths
     * @param  array<string,mixed>  $validationEvidence
     * @param  array<int,mixed>  $traceSteps
     * @return array<string,mixed>
     */
    public function decide(
        string $workTitle,
        array $intendedPaths,
        array $validationEvidence,
        array $traceSteps,
        string $decision,
        ?string $reason = null,
        ?string $docsApPath = null,
        int|string|null $targetAp = null,
        ?int $requestedApNumber = null,
        ?string $proposedSlug = null,
    ): array {
        $packet = $this->humanReviewPacket->packet(
            workTitle: $workTitle,
            intendedPaths: $intendedPaths,
            validationEvidence: $validationEvidence,
            traceSteps: $traceSteps,
            docsApPath: $docsApPath,
            targetAp: $targetAp,
            requestedApNumber: $requestedApNumber,
            proposedSlug: $proposedSlug,
        );

        $normalizedDecision = trim($decision);
        $normalizedReason = $reason === null ? null : trim($reason);
        $status = $this->status($packet, $normalizedDecision, $normalizedReason);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_human_decision_contract',
            'authority' => 'ap_agent_workflow_human_decision_only_no_execution',
            'work_title' => $packet['work_title'],
            'resolved_target_ap' => $packet['resolved_target_ap'],
            'decision' => [
                'value' => $normalizedDecision,
                'reason' => $normalizedReason,
                'allowed_values' => self::ALLOWED_DECISIONS,
                'reason_required' => in_array($normalizedDecision, ['request_changes', 'reject'], true),
            ],
            'review_packet_summary' => [
                'schema_version' => $packet['schema_version'],
                'status' => $packet['status'],
                'review_decision' => $packet['review_decision'],
                'execution_receipt_status' => data_get($packet, 'review_summary.execution_receipt_status'),
                'trace_status' => data_get($packet, 'review_summary.trace_status'),
                'completion_status' => data_get($packet, 'review_summary.completion_status'),
            ],
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_decision' => false,
                'auto_merges_work' => false,
                'overrides_review_packet' => false,
                'replaces_evidence_ledger' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $packet
     */
    private function status(array $packet, string $decision, ?string $reason): string
    {
        if (! in_array($decision, self::ALLOWED_DECISIONS, true)) {
            return 'blocked_invalid_human_decision';
        }

        if (in_array($decision, ['request_changes', 'reject'], true) && ($reason === null || $reason === '')) {
            return 'blocked_missing_human_reason';
        }

        if ($decision === 'accept' && ($packet['status'] ?? null) !== 'ready_for_human_acceptance') {
            return 'blocked_accept_requires_ready_review_packet';
        }

        return match ($decision) {
            'accept' => 'accepted_by_human',
            'request_changes' => 'changes_requested_by_human',
            'reject' => 'rejected_by_human',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'accepted_by_human' => 'handoff_human_acceptance_to_integrator_without_auto_merge',
            'changes_requested_by_human' => 'return_review_notes_to_agent_for_repair',
            'rejected_by_human' => 'stop_ap_agent_work_until_scope_is_reopened',
            'blocked_invalid_human_decision' => 'choose_allowed_human_decision_value',
            'blocked_missing_human_reason' => 'provide_human_reason_before_recording_decision',
            'blocked_accept_requires_ready_review_packet' => 'repair_review_packet_before_human_acceptance',
            default => 'review_human_decision_contract_status',
        };
    }
}
