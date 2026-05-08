<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteHandoffPacket
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_handoff_packet.v1';

    /** @var array<int,string> */
    private const REQUIRED_HANDOFF_EVIDENCE = [
        'reviewed_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_receipt',
        'declared_future_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_ap',
        'declared_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_package',
        'confirmed_acceptance_receipt_only',
        'confirmed_policy_receipt_attached',
        'confirmed_operator_confirmation_required',
        'confirmed_idempotency_strategy_attached',
        'confirmed_rollback_plan_attached',
        'confirmed_no_auto_execution',
        'confirmed_no_command_execution',
        'confirmed_no_runtime_job_created',
        'confirmed_no_ledger_write',
        'confirmed_no_payload_executed',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_HANDOFF_EVIDENCE = [
        'future_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_ap',
        'idempotency_key_strategy',
        'notes',
        'operator_confirmation_surface',
        'owner',
        'policy_receipt_source',
        'rollback_plan_ref',
        'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_package',
        'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_receipt_ref',
    ];

    /**
     * @param  array<string,mixed>  $ledgerWriteReceipt
     * @param  array<string,mixed>  $handoffEvidence
     * @return array<string,mixed>
     */
    public function packet(array $ledgerWriteReceipt, array $handoffEvidence): array
    {
        $evidence = $this->evaluateEvidence($handoffEvidence);
        $status = $this->status($ledgerWriteReceipt, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_handoff_packet',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_handoff_only_no_ledger_write',
            'work_title' => $ledgerWriteReceipt['work_title'] ?? null,
            'resolved_target_ap' => $ledgerWriteReceipt['resolved_target_ap'] ?? null,
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_receipt_summary' => [
                'schema_version' => $ledgerWriteReceipt['schema_version'] ?? null,
                'status' => $ledgerWriteReceipt['status'] ?? null,
                'decision' => data_get($ledgerWriteReceipt, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_summary.decision'),
                'execution_surface' => data_get($ledgerWriteReceipt, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_summary.execution_surface'),
                'idempotency_key_strategy' => data_get($ledgerWriteReceipt, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_summary.idempotency_key_strategy'),
                'operator_confirmation_surface' => data_get($ledgerWriteReceipt, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_summary.operator_confirmation_surface'),
                'rollback_plan_ref' => data_get($ledgerWriteReceipt, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_summary.rollback_plan_ref'),
                'payload_hash' => data_get($ledgerWriteReceipt, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_summary.payload_hash'),
                'ledger_write_plan_ref' => data_get($ledgerWriteReceipt, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_summary.ledger_write_plan_ref'),
                'owner' => data_get($ledgerWriteReceipt, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_summary.owner'),
                'policy_receipt_source' => data_get($ledgerWriteReceipt, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_summary.policy_receipt_source'),
            ],
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_handoff_evidence' => $evidence,
            'handoff_target' => [
                'future_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_ap' => $handoffEvidence['future_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_ap'] ?? null,
                'owner' => $handoffEvidence['owner'] ?? null,
                'operator_confirmation_surface' => $handoffEvidence['operator_confirmation_surface'] ?? null,
            ],
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_package' => $handoffEvidence['runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_package'] ?? null,
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_receipt_ref' => $handoffEvidence['runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_receipt_ref'] ?? null,
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_packet' => false,
                'persists_result_state' => false,
                'publishes_release' => false,
                'emits_evidence_event' => false,
                'writes_evidence_ledger' => false,
                'creates_runtime_job' => false,
                'runs_dry_run' => false,
                'executes_authorized_work' => false,
                'performs_activation' => false,
                'executes_runtime_payload' => false,
                'performs_ledger_write' => false,
                'accepts_without_ledger_write_decision_receipt' => false,
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
                $shapeErrors[] = ['id' => 'unknown_result_persistence_execution_ledger_write_handoff_key', 'key' => (string) $key];
            }
        }

        foreach (self::REQUIRED_HANDOFF_EVIDENCE as $key) {
            if (! array_key_exists($key, $handoffEvidence)) {
                $shapeErrors[] = ['id' => 'missing_required_result_persistence_execution_ledger_write_handoff', 'key' => $key];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($handoffEvidence[$key])) {
                $shapeErrors[] = ['id' => 'result_persistence_execution_ledger_write_handoff_must_be_boolean', 'key' => $key];
                $failed[] = $key;

                continue;
            }

            if ($handoffEvidence[$key] !== true) {
                $failed[] = $key;
            }
        }

        foreach (['future_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_ap', 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_package', 'owner', 'operator_confirmation_surface', 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_receipt_ref'] as $key) {
            if (! is_string($handoffEvidence[$key] ?? null) || trim((string) $handoffEvidence[$key]) === '') {
                $shapeErrors[] = ['id' => 'result_persistence_execution_ledger_write_handoff_text_required', 'key' => $key];
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
        if (($receipt['status'] ?? null) !== 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_acceptance_reported') {
            return 'blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_receipt';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_handoff_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_handoff_evidence_incomplete';
        }

        return 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_handoff_ready_for_future_execution_ap';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_handoff_ready_for_future_execution_ap' => 'future_ledger_write_execution_ap_may_review_handoff_without_auto_ledger_write',
            'blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_decision_receipt' => 'repair_or_accept_result_persistence_execution_ledger_write_decision_before_handoff',
            'blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_handoff_shape' => 'fix_result_persistence_execution_ledger_write_handoff_shape_before_review',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_handoff_evidence_incomplete' => 'complete_result_persistence_execution_ledger_write_handoff_evidence_before_review',
            default => 'review_result_persistence_execution_ledger_write_handoff_status',
        };
    }
}
