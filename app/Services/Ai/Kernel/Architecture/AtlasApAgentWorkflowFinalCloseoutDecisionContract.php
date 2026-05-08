<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowFinalCloseoutDecisionContract
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_final_closeout_decision_contract.v1';

    /** @var array<int,string> */
    private const ALLOWED_DECISIONS = [
        'accept_closeout',
        'request_closeout_changes',
        'reject_closeout',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowFinalAuditPacket $finalAuditPacket,
    ) {}

    /**
     * @param  array<int,string>  $intendedPaths
     * @param  array<string,mixed>  $validationEvidence
     * @param  array<int,mixed>  $traceSteps
     * @param  array<string,mixed>  $integratorEvidence
     * @param  array<string,mixed>  $integrationEvidence
     * @param  array<string,mixed>  $finalAuditEvidence
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
        ?string $closeoutReason = null,
        ?string $reason = null,
        ?string $docsApPath = null,
        int|string|null $targetAp = null,
        ?int $requestedApNumber = null,
        ?string $proposedSlug = null,
    ): array {
        $packet = $this->finalAuditPacket->packet(
            workTitle: $workTitle,
            intendedPaths: $intendedPaths,
            validationEvidence: $validationEvidence,
            traceSteps: $traceSteps,
            decision: $decision,
            integratorEvidence: $integratorEvidence,
            integrationEvidence: $integrationEvidence,
            finalAuditEvidence: $finalAuditEvidence,
            reason: $reason,
            docsApPath: $docsApPath,
            targetAp: $targetAp,
            requestedApNumber: $requestedApNumber,
            proposedSlug: $proposedSlug,
        );
        $normalizedDecision = trim($closeoutDecision);
        $normalizedReason = $closeoutReason === null ? null : trim($closeoutReason);
        $status = $this->status($packet, $normalizedDecision, $normalizedReason);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_final_closeout_decision_contract',
            'authority' => 'ap_agent_workflow_final_closeout_decision_only_no_execution',
            'work_title' => $packet['work_title'],
            'resolved_target_ap' => $packet['resolved_target_ap'],
            'closeout_decision' => [
                'value' => $normalizedDecision,
                'reason' => $normalizedReason,
                'allowed_values' => self::ALLOWED_DECISIONS,
                'reason_required' => in_array($normalizedDecision, ['request_closeout_changes', 'reject_closeout'], true),
            ],
            'final_audit_packet_summary' => [
                'schema_version' => $packet['schema_version'],
                'status' => $packet['status'],
                'manual_integration_receipt_status' => data_get($packet, 'manual_integration_receipt_summary.status'),
                'final_audit_evidence_status' => data_get($packet, 'final_audit_evidence.status'),
            ],
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_decision' => false,
                'publishes_release' => false,
                'closes_without_final_audit_packet' => false,
                'auto_closes_work' => false,
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
            return 'blocked_invalid_closeout_decision';
        }

        if (in_array($decision, ['request_closeout_changes', 'reject_closeout'], true) && ($reason === null || $reason === '')) {
            return 'blocked_missing_closeout_reason';
        }

        if ($decision === 'accept_closeout' && ($packet['status'] ?? null) !== 'ready_for_final_human_closeout_review') {
            return 'blocked_accept_requires_ready_final_audit_packet';
        }

        return match ($decision) {
            'accept_closeout' => 'closeout_accepted_by_human',
            'request_closeout_changes' => 'closeout_changes_requested_by_human',
            'reject_closeout' => 'closeout_rejected_by_human',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'closeout_accepted_by_human' => 'handoff_closeout_acceptance_to_release_or_evidence_layer_without_auto_publish',
            'closeout_changes_requested_by_human' => 'return_closeout_notes_to_integrator_for_repair',
            'closeout_rejected_by_human' => 'stop_closeout_until_human_reopens_scope',
            'blocked_invalid_closeout_decision' => 'choose_allowed_closeout_decision_value',
            'blocked_missing_closeout_reason' => 'provide_closeout_reason_before_decision',
            'blocked_accept_requires_ready_final_audit_packet' => 'repair_final_audit_packet_before_closeout_acceptance',
            default => 'review_final_closeout_decision_status',
        };
    }
}
