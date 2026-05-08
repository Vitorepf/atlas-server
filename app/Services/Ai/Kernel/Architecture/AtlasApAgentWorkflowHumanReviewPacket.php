<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowHumanReviewPacket
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_human_review_packet.v1';

    public function __construct(
        private readonly AtlasApAgentWorkflowExecutionReceipt $executionReceipt,
    ) {}

    /**
     * @param  array<int,string>  $intendedPaths
     * @param  array<string,mixed>  $validationEvidence
     * @param  array<int,mixed>  $traceSteps
     * @return array<string,mixed>
     */
    public function packet(
        string $workTitle,
        array $intendedPaths,
        array $validationEvidence,
        array $traceSteps,
        ?string $docsApPath = null,
        int|string|null $targetAp = null,
        ?int $requestedApNumber = null,
        ?string $proposedSlug = null,
    ): array {
        $receipt = $this->executionReceipt->receipt(
            workTitle: $workTitle,
            intendedPaths: $intendedPaths,
            validationEvidence: $validationEvidence,
            traceSteps: $traceSteps,
            docsApPath: $docsApPath,
            targetAp: $targetAp,
            requestedApNumber: $requestedApNumber,
            proposedSlug: $proposedSlug,
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $this->status($receipt),
            'mode' => 'read_only_human_review_packet',
            'authority' => 'ap_agent_workflow_human_review_packet_only_no_execution',
            'work_title' => $receipt['work_title'],
            'resolved_target_ap' => $receipt['resolved_target_ap'],
            'review_decision' => $this->reviewDecision($receipt),
            'review_summary' => [
                'execution_receipt_status' => $receipt['status'],
                'trace_status' => data_get($receipt, 'trace_audit.status'),
                'completion_status' => data_get($receipt, 'completion_report.status'),
                'session_gate_status' => data_get($receipt, 'completion_report.session_gate_status'),
                'validation_evidence_shape_status' => data_get($receipt, 'completion_report.validation_evidence_shape_status'),
                'completion_checklist_status' => data_get($receipt, 'completion_report.completion_checklist_status'),
            ],
            'source_receipt' => [
                'schema_version' => $receipt['schema_version'],
                'status' => $receipt['status'],
                'next_action' => $receipt['next_action'],
            ],
            'next_action' => $this->nextAction($receipt),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_review' => false,
                'overrides_execution_receipt' => false,
                'replaces_human_judgement' => false,
                'replaces_evidence_ledger' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function status(array $receipt): string
    {
        return ($receipt['status'] ?? null) === 'accepted'
            ? 'ready_for_human_acceptance'
            : 'requires_human_repair_review';
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function reviewDecision(array $receipt): string
    {
        return match ($receipt['status'] ?? null) {
            'accepted' => 'human_can_accept_or_request_extra_review',
            'blocked_by_trace_audit' => 'human_should_review_workflow_trace_repair',
            'blocked_by_completion_report' => 'human_should_review_completion_evidence_repair',
            default => 'human_should_review_unknown_execution_receipt_status',
        };
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function nextAction(array $receipt): string
    {
        if (($receipt['status'] ?? null) === 'accepted') {
            return 'present_execution_receipt_summary_to_human_reviewer';
        }

        return (string) ($receipt['next_action'] ?? 'repair_execution_receipt_before_human_acceptance');
    }
}
