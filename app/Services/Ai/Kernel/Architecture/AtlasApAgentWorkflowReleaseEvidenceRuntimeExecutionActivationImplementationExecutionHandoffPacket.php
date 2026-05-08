<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionHandoffPacket
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_handoff_packet.v1';

    /** @var array<int,string> */
    private const REQUIRED_HANDOFF_EVIDENCE = [
        'reviewed_runtime_execution_activation_implementation_execution_receipt',
        'declared_future_runtime_execution_activation_implementation_execution_ap',
        'declared_runtime_execution_activation_implementation_execution_package',
        'confirmed_acceptance_receipt_only',
        'confirmed_policy_receipt_attached',
        'confirmed_operator_confirmation_required',
        'confirmed_no_auto_execution',
        'confirmed_no_command_execution',
        'confirmed_no_runtime_job_created',
        'confirmed_no_ledger_write',
        'confirmed_no_payload_executed',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_HANDOFF_EVIDENCE = [
        'future_runtime_execution_activation_implementation_execution_ap',
        'idempotency_key_strategy',
        'notes',
        'owner',
        'policy_receipt_source',
        'rollback_plan_ref',
        'runtime_execution_activation_implementation_execution_package',
        'runtime_execution_activation_implementation_execution_receipt_ref',
    ];

    /**
     * @param  array<string,mixed>  $decisionReceipt
     * @param  array<string,mixed>  $handoffEvidence
     * @return array<string,mixed>
     */
    public function packet(array $decisionReceipt, array $handoffEvidence): array
    {
        $evidence = $this->evaluateEvidence($handoffEvidence);
        $status = $this->status($decisionReceipt, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_runtime_execution_activation_implementation_execution_handoff_packet',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_handoff_only_no_execution',
            'work_title' => $decisionReceipt['work_title'] ?? null,
            'resolved_target_ap' => $decisionReceipt['resolved_target_ap'] ?? null,
            'runtime_execution_activation_implementation_execution_receipt_summary' => [
                'schema_version' => $decisionReceipt['schema_version'] ?? null,
                'status' => $decisionReceipt['status'] ?? null,
                'decision' => data_get($decisionReceipt, 'runtime_execution_activation_implementation_execution_decision_summary.decision'),
                'reason' => data_get($decisionReceipt, 'runtime_execution_activation_implementation_execution_decision_summary.reason'),
                'execution_scope' => data_get($decisionReceipt, 'runtime_execution_activation_implementation_execution_decision_summary.execution_scope'),
                'payload_hash' => data_get($decisionReceipt, 'runtime_execution_activation_implementation_execution_decision_summary.payload_hash'),
                'idempotency_key' => data_get($decisionReceipt, 'runtime_execution_activation_implementation_execution_decision_summary.idempotency_key'),
                'operator_confirmation_surface' => data_get($decisionReceipt, 'runtime_execution_activation_implementation_execution_decision_summary.operator_confirmation_surface'),
                'owner' => data_get($decisionReceipt, 'runtime_execution_activation_implementation_execution_decision_summary.owner'),
                'policy_receipt_source' => data_get($decisionReceipt, 'runtime_execution_activation_implementation_execution_decision_summary.policy_receipt_source'),
                'rollback_plan_ref' => data_get($decisionReceipt, 'runtime_execution_activation_implementation_execution_decision_summary.rollback_plan_ref'),
            ],
            'runtime_execution_activation_implementation_execution_handoff_evidence' => $evidence,
            'handoff_target' => [
                'future_runtime_execution_activation_implementation_execution_ap' => $handoffEvidence['future_runtime_execution_activation_implementation_execution_ap'] ?? null,
                'owner' => $handoffEvidence['owner'] ?? null,
            ],
            'runtime_execution_activation_implementation_execution_package' => $handoffEvidence['runtime_execution_activation_implementation_execution_package'] ?? null,
            'runtime_execution_activation_implementation_execution_receipt_ref' => $handoffEvidence['runtime_execution_activation_implementation_execution_receipt_ref'] ?? null,
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_packet' => false,
                'publishes_release' => false,
                'emits_evidence_event' => false,
                'writes_evidence_ledger' => false,
                'creates_runtime_job' => false,
                'runs_dry_run' => false,
                'executes_authorized_work' => false,
                'performs_activation' => false,
                'executes_runtime_payload' => false,
                'accepts_without_runtime_execution_activation_implementation_execution_receipt' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $handoffEvidence
     * @return array<string,mixed>
     */
    private function evaluateEvidence(array $handoffEvidence): array
    {
        $failed = [];
        $shapeErrors = [];
        $allowedKeys = array_merge(self::REQUIRED_HANDOFF_EVIDENCE, self::OPTIONAL_HANDOFF_EVIDENCE);

        foreach ($handoffEvidence as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                $shapeErrors[] = [
                    'id' => 'unknown_release_evidence_runtime_execution_activation_implementation_execution_handoff_key',
                    'key' => (string) $key,
                ];
            }
        }

        foreach (self::REQUIRED_HANDOFF_EVIDENCE as $key) {
            if (! array_key_exists($key, $handoffEvidence)) {
                $shapeErrors[] = [
                    'id' => 'missing_required_release_evidence_runtime_execution_activation_implementation_execution_handoff',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($handoffEvidence[$key])) {
                $shapeErrors[] = [
                    'id' => 'release_evidence_runtime_execution_activation_implementation_execution_handoff_must_be_boolean',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if ($handoffEvidence[$key] !== true) {
                $failed[] = $key;
            }
        }

        foreach (['future_runtime_execution_activation_implementation_execution_ap', 'runtime_execution_activation_implementation_execution_package', 'owner', 'runtime_execution_activation_implementation_execution_receipt_ref'] as $key) {
            if (! is_string($handoffEvidence[$key] ?? null) || trim((string) $handoffEvidence[$key]) === '') {
                $shapeErrors[] = [
                    'id' => 'release_evidence_runtime_execution_activation_implementation_execution_handoff_text_required',
                    'key' => $key,
                ];
            }
        }

        return [
            'status' => $shapeErrors === []
                ? ($failed === [] ? 'complete' : 'incomplete')
                : 'invalid_shape',
            'required_keys' => self::REQUIRED_HANDOFF_EVIDENCE,
            'passed_count' => count(self::REQUIRED_HANDOFF_EVIDENCE) - count(array_unique($failed)),
            'failed_count' => count(array_unique($failed)),
            'failed_keys' => array_values(array_unique($failed)),
            'shape_error_count' => count($shapeErrors),
            'shape_errors' => $shapeErrors,
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $evidence
     */
    private function status(array $receipt, array $evidence): string
    {
        if (($receipt['status'] ?? null) !== 'runtime_execution_activation_implementation_execution_acceptance_reported') {
            return 'blocked_by_runtime_execution_activation_implementation_execution_decision_receipt';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_runtime_execution_activation_implementation_execution_handoff_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'runtime_execution_activation_implementation_execution_handoff_evidence_incomplete';
        }

        return 'runtime_execution_activation_implementation_execution_handoff_ready_for_future_execution_ap';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'runtime_execution_activation_implementation_execution_handoff_ready_for_future_execution_ap' => 'future_runtime_execution_activation_implementation_execution_ap_may_review_handoff_without_auto_execution',
            'blocked_by_runtime_execution_activation_implementation_execution_decision_receipt' => 'repair_or_accept_runtime_execution_activation_implementation_execution_decision_before_handoff',
            'blocked_invalid_runtime_execution_activation_implementation_execution_handoff_shape' => 'fix_runtime_execution_activation_implementation_execution_handoff_shape_before_review',
            'runtime_execution_activation_implementation_execution_handoff_evidence_incomplete' => 'complete_runtime_execution_activation_implementation_execution_handoff_evidence_before_review',
            default => 'review_runtime_execution_activation_implementation_execution_handoff_status',
        };
    }
}
