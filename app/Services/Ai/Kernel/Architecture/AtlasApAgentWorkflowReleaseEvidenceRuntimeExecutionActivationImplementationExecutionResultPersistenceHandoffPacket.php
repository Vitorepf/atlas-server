<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceHandoffPacket
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_handoff_packet.v1';

    /** @var array<int,string> */
    private const REQUIRED_HANDOFF_EVIDENCE = [
        'reviewed_runtime_execution_activation_implementation_execution_result_persistence_receipt',
        'declared_future_runtime_execution_activation_implementation_execution_result_persistence_ap',
        'declared_runtime_execution_activation_implementation_execution_result_persistence_package',
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
        'future_runtime_execution_activation_implementation_execution_result_persistence_ap',
        'idempotency_key_strategy',
        'notes',
        'owner',
        'policy_receipt_source',
        'rollback_plan_ref',
        'runtime_execution_activation_implementation_execution_result_persistence_package',
        'runtime_execution_activation_implementation_execution_result_persistence_receipt_ref',
    ];

    /**
     * @param  array<string,mixed>  $persistenceReceipt
     * @param  array<string,mixed>  $handoffEvidence
     * @return array<string,mixed>
     */
    public function packet(array $persistenceReceipt, array $handoffEvidence): array
    {
        $evidence = $this->evaluateEvidence($handoffEvidence);
        $status = $this->status($persistenceReceipt, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_handoff_packet',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_handoff_only_no_ledger_write',
            'work_title' => $persistenceReceipt['work_title'] ?? null,
            'resolved_target_ap' => $persistenceReceipt['resolved_target_ap'] ?? null,
            'runtime_execution_activation_implementation_execution_result_persistence_receipt_summary' => [
                'schema_version' => $persistenceReceipt['schema_version'] ?? null,
                'status' => $persistenceReceipt['status'] ?? null,
                'decision' => data_get($persistenceReceipt, 'runtime_execution_activation_implementation_execution_result_persistence_decision_summary.decision'),
                'persistence_target' => data_get($persistenceReceipt, 'runtime_execution_activation_implementation_execution_result_persistence_decision_summary.persistence_target'),
                'evidence_event_family' => data_get($persistenceReceipt, 'runtime_execution_activation_implementation_execution_result_persistence_decision_summary.evidence_event_family'),
                'redaction_strategy' => data_get($persistenceReceipt, 'runtime_execution_activation_implementation_execution_result_persistence_decision_summary.redaction_strategy'),
                'payload_hash' => data_get($persistenceReceipt, 'runtime_execution_activation_implementation_execution_result_persistence_decision_summary.payload_hash'),
                'ledger_write_plan_ref' => data_get($persistenceReceipt, 'runtime_execution_activation_implementation_execution_result_persistence_decision_summary.ledger_write_plan_ref'),
                'owner' => data_get($persistenceReceipt, 'runtime_execution_activation_implementation_execution_result_persistence_decision_summary.owner'),
                'policy_receipt_source' => data_get($persistenceReceipt, 'runtime_execution_activation_implementation_execution_result_persistence_decision_summary.policy_receipt_source'),
            ],
            'runtime_execution_activation_implementation_execution_result_persistence_handoff_evidence' => $evidence,
            'handoff_target' => [
                'future_runtime_execution_activation_implementation_execution_result_persistence_ap' => $handoffEvidence['future_runtime_execution_activation_implementation_execution_result_persistence_ap'] ?? null,
                'owner' => $handoffEvidence['owner'] ?? null,
            ],
            'runtime_execution_activation_implementation_execution_result_persistence_package' => $handoffEvidence['runtime_execution_activation_implementation_execution_result_persistence_package'] ?? null,
            'runtime_execution_activation_implementation_execution_result_persistence_receipt_ref' => $handoffEvidence['runtime_execution_activation_implementation_execution_result_persistence_receipt_ref'] ?? null,
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
                'accepts_without_result_persistence_receipt' => false,
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
                $shapeErrors[] = ['id' => 'unknown_result_persistence_handoff_key', 'key' => (string) $key];
            }
        }

        foreach (self::REQUIRED_HANDOFF_EVIDENCE as $key) {
            if (! array_key_exists($key, $handoffEvidence)) {
                $shapeErrors[] = ['id' => 'missing_required_result_persistence_handoff', 'key' => $key];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($handoffEvidence[$key])) {
                $shapeErrors[] = ['id' => 'result_persistence_handoff_must_be_boolean', 'key' => $key];
                $failed[] = $key;

                continue;
            }

            if ($handoffEvidence[$key] !== true) {
                $failed[] = $key;
            }
        }

        foreach (['future_runtime_execution_activation_implementation_execution_result_persistence_ap', 'runtime_execution_activation_implementation_execution_result_persistence_package', 'owner', 'runtime_execution_activation_implementation_execution_result_persistence_receipt_ref'] as $key) {
            if (! is_string($handoffEvidence[$key] ?? null) || trim((string) $handoffEvidence[$key]) === '') {
                $shapeErrors[] = ['id' => 'result_persistence_handoff_text_required', 'key' => $key];
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
        if (($receipt['status'] ?? null) !== 'runtime_execution_activation_implementation_execution_result_persistence_acceptance_reported') {
            return 'blocked_by_runtime_execution_activation_implementation_execution_result_persistence_decision_receipt';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_handoff_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'runtime_execution_activation_implementation_execution_result_persistence_handoff_evidence_incomplete';
        }

        return 'runtime_execution_activation_implementation_execution_result_persistence_handoff_ready_for_future_ledger_ap';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'runtime_execution_activation_implementation_execution_result_persistence_handoff_ready_for_future_ledger_ap' => 'future_result_persistence_execution_ap_may_review_handoff_without_auto_ledger_write',
            'blocked_by_runtime_execution_activation_implementation_execution_result_persistence_decision_receipt' => 'repair_or_accept_result_persistence_decision_before_handoff',
            'blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_handoff_shape' => 'fix_result_persistence_handoff_shape_before_review',
            'runtime_execution_activation_implementation_execution_result_persistence_handoff_evidence_incomplete' => 'complete_result_persistence_handoff_evidence_before_review',
            default => 'review_result_persistence_handoff_status',
        };
    }
}
