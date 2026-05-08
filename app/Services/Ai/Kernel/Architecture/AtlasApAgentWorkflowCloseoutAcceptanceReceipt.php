<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowCloseoutAcceptanceReceipt
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_closeout_acceptance_receipt.v1';

    public function __construct(
        private readonly AtlasApAgentWorkflowFinalCloseoutDecisionContract $closeoutDecisionContract,
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
    public function receipt(
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
        $decisionPayload = $this->closeoutDecisionContract->decide(
            workTitle: $workTitle,
            intendedPaths: $intendedPaths,
            validationEvidence: $validationEvidence,
            traceSteps: $traceSteps,
            decision: $decision,
            integratorEvidence: $integratorEvidence,
            integrationEvidence: $integrationEvidence,
            finalAuditEvidence: $finalAuditEvidence,
            closeoutDecision: $closeoutDecision,
            closeoutReason: $closeoutReason,
            reason: $reason,
            docsApPath: $docsApPath,
            targetAp: $targetAp,
            requestedApNumber: $requestedApNumber,
            proposedSlug: $proposedSlug,
        );
        $status = $this->status($decisionPayload);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_closeout_acceptance_receipt',
            'authority' => 'ap_agent_workflow_closeout_acceptance_receipt_only_no_execution',
            'work_title' => $decisionPayload['work_title'],
            'resolved_target_ap' => $decisionPayload['resolved_target_ap'],
            'closeout_decision_summary' => [
                'schema_version' => $decisionPayload['schema_version'],
                'status' => $decisionPayload['status'],
                'decision' => data_get($decisionPayload, 'closeout_decision.value'),
                'reason' => data_get($decisionPayload, 'closeout_decision.reason'),
                'final_audit_packet_status' => data_get($decisionPayload, 'final_audit_packet_summary.status'),
                'manual_integration_receipt_status' => data_get($decisionPayload, 'final_audit_packet_summary.manual_integration_receipt_status'),
            ],
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_receipt' => false,
                'publishes_release' => false,
                'emits_evidence_event' => false,
                'closes_without_human_acceptance' => false,
                'replaces_evidence_ledger' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $decisionPayload
     */
    private function status(array $decisionPayload): string
    {
        return match ($decisionPayload['status'] ?? null) {
            'closeout_accepted_by_human' => 'closeout_acceptance_reported',
            'closeout_changes_requested_by_human' => 'closeout_returned_for_repair',
            'closeout_rejected_by_human' => 'closeout_stopped_by_rejection',
            default => 'blocked_by_closeout_decision_contract',
        };
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'closeout_acceptance_reported' => 'handoff_to_future_release_or_evidence_receipt_without_auto_publish',
            'closeout_returned_for_repair' => 'integrator_repairs_closeout_notes_before_new_audit',
            'closeout_stopped_by_rejection' => 'stop_closeout_flow_until_scope_is_reopened',
            'blocked_by_closeout_decision_contract' => 'repair_closeout_decision_before_acceptance_receipt',
            default => 'review_closeout_acceptance_receipt_status',
        };
    }
}
