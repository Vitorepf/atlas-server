<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowExecutionReceipt
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_execution_receipt.v1';

    public function __construct(
        private readonly AtlasApAgentWorkflowTraceAudit $traceAudit,
        private readonly AtlasApAgentCompletionReport $completionReport,
    ) {}

    /**
     * @param  array<int,string>  $intendedPaths
     * @param  array<string,mixed>  $validationEvidence
     * @param  array<int,mixed>  $traceSteps
     * @return array<string,mixed>
     */
    public function receipt(
        string $workTitle,
        array $intendedPaths,
        array $validationEvidence,
        array $traceSteps,
        ?string $docsApPath = null,
        int|string|null $targetAp = null,
        ?int $requestedApNumber = null,
        ?string $proposedSlug = null,
    ): array {
        $trace = $this->traceAudit->audit($traceSteps);
        $completion = $this->completionReport->report(
            workTitle: $workTitle,
            intendedPaths: $intendedPaths,
            validationEvidence: $validationEvidence,
            docsApPath: $docsApPath,
            targetAp: $targetAp,
            requestedApNumber: $requestedApNumber,
            proposedSlug: $proposedSlug,
        );
        $status = $this->status($trace, $completion);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_execution_receipt',
            'authority' => 'ap_agent_workflow_execution_receipt_only_no_execution',
            'work_title' => trim($workTitle),
            'resolved_target_ap' => $completion['resolved_target_ap'] ?? null,
            'trace_audit' => [
                'schema_version' => $trace['schema_version'],
                'status' => $trace['status'],
                'step_count' => $trace['step_count'],
                'terminal_reached' => $trace['terminal_reached'],
                'blocked_transition_count' => $trace['blocked_transition_count'],
                'shape_error_count' => $trace['shape_error_count'],
                'next_action' => $trace['next_action'],
            ],
            'completion_report' => [
                'schema_version' => $completion['schema_version'],
                'status' => $completion['status'],
                'session_gate_status' => data_get($completion, 'session_gate.status'),
                'validation_evidence_shape_status' => data_get($completion, 'validation_evidence_shape.status'),
                'completion_checklist_status' => data_get($completion, 'completion_checklist.status'),
                'next_action' => $completion['next_action'],
            ],
            'next_action' => $this->nextAction($status, $trace, $completion),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_receipt' => false,
                'accepts_without_valid_trace' => false,
                'accepts_without_complete_report' => false,
                'replaces_evidence_ledger' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $trace
     * @param  array<string,mixed>  $completion
     */
    private function status(array $trace, array $completion): string
    {
        if (($trace['status'] ?? null) !== 'valid_trace') {
            return 'blocked_by_trace_audit';
        }

        if (($completion['status'] ?? null) !== 'complete') {
            return 'blocked_by_completion_report';
        }

        return 'accepted';
    }

    /**
     * @param  array<string,mixed>  $trace
     * @param  array<string,mixed>  $completion
     */
    private function nextAction(string $status, array $trace, array $completion): string
    {
        if ($status === 'blocked_by_trace_audit') {
            return (string) ($trace['next_action'] ?? 'repair_trace_before_accepting_execution_receipt');
        }

        if ($status === 'blocked_by_completion_report') {
            return (string) ($completion['next_action'] ?? 'repair_completion_report_before_accepting_execution_receipt');
        }

        return 'accept_execution_receipt_for_human_review_summary';
    }
}
