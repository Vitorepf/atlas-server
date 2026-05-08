<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurablePreflight
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight.v1';

    /** @var array<int,string> */
    private const REQUIRED_PREFLIGHT_EVIDENCE = [
        'reviewed_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_handoff',
        'declared_handoff_ready_for_future_execution_ap',
        'declared_durable_ledger_write_surface',
        'declared_append_only_write_plan',
        'declared_idempotency_key_strategy',
        'declared_payload_hash_verified',
        'declared_policy_receipt_attached',
        'declared_operator_confirmation_surface',
        'declared_rollback_or_replay_plan',
        'confirmed_no_auto_execution',
        'confirmed_no_command_execution',
        'confirmed_no_runtime_job_created',
        'confirmed_no_ledger_write',
        'confirmed_no_evidence_event_emitted',
        'confirmed_no_payload_executed',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_PREFLIGHT_EVIDENCE = [
        'append_only_write_plan_ref',
        'durable_ledger_write_surface',
        'idempotency_key_strategy',
        'ledger_write_receipt_ref',
        'notes',
        'operator_confirmation_surface',
        'owner',
        'payload_hash',
        'policy_receipt_source',
        'rollback_or_replay_plan_ref',
    ];

    /**
     * @param  array<string,mixed>  $ledgerWriteExecutionHandoff
     * @param  array<string,mixed>  $preflightEvidence
     * @return array<string,mixed>
     */
    public function preflight(array $ledgerWriteExecutionHandoff, array $preflightEvidence): array
    {
        $evidence = $this->evaluateEvidence($preflightEvidence);
        $status = $this->status($ledgerWriteExecutionHandoff, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight_only_no_ledger_write',
            'work_title' => $ledgerWriteExecutionHandoff['work_title'] ?? null,
            'resolved_target_ap' => $ledgerWriteExecutionHandoff['resolved_target_ap'] ?? null,
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_handoff_summary' => [
                'schema_version' => $ledgerWriteExecutionHandoff['schema_version'] ?? null,
                'status' => $ledgerWriteExecutionHandoff['status'] ?? null,
                'future_ap' => data_get($ledgerWriteExecutionHandoff, 'handoff_target.future_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_ap'),
                'decision' => data_get($ledgerWriteExecutionHandoff, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_receipt_summary.decision'),
                'execution_surface' => data_get($ledgerWriteExecutionHandoff, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_receipt_summary.execution_surface'),
                'idempotency_key_strategy' => data_get($ledgerWriteExecutionHandoff, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_receipt_summary.idempotency_key_strategy'),
                'operator_confirmation_surface' => data_get($ledgerWriteExecutionHandoff, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_receipt_summary.operator_confirmation_surface'),
                'rollback_plan_ref' => data_get($ledgerWriteExecutionHandoff, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_receipt_summary.rollback_plan_ref'),
                'payload_hash' => data_get($ledgerWriteExecutionHandoff, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_receipt_summary.payload_hash'),
                'ledger_write_plan_ref' => data_get($ledgerWriteExecutionHandoff, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_receipt_summary.ledger_write_plan_ref'),
                'owner' => data_get($ledgerWriteExecutionHandoff, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_receipt_summary.owner'),
                'policy_receipt_source' => data_get($ledgerWriteExecutionHandoff, 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_receipt_summary.policy_receipt_source'),
            ],
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight_evidence' => $evidence,
            'durable_ledger_write_execution_target' => [
                'durable_ledger_write_surface' => $preflightEvidence['durable_ledger_write_surface'] ?? null,
                'append_only_write_plan_ref' => $preflightEvidence['append_only_write_plan_ref'] ?? null,
                'idempotency_key_strategy' => $preflightEvidence['idempotency_key_strategy'] ?? null,
                'operator_confirmation_surface' => $preflightEvidence['operator_confirmation_surface'] ?? null,
                'rollback_or_replay_plan_ref' => $preflightEvidence['rollback_or_replay_plan_ref'] ?? null,
                'payload_hash' => $preflightEvidence['payload_hash'] ?? null,
                'ledger_write_receipt_ref' => $preflightEvidence['ledger_write_receipt_ref'] ?? null,
                'owner' => $preflightEvidence['owner'] ?? null,
            ],
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_preflight' => false,
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
                'accepts_without_ledger_write_execution_handoff' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $preflightEvidence
     * @return array<string,mixed>
     */
    private function evaluateEvidence(array $preflightEvidence): array
    {
        $failed = [];
        $shapeErrors = [];
        $allowedKeys = array_merge(self::REQUIRED_PREFLIGHT_EVIDENCE, self::OPTIONAL_PREFLIGHT_EVIDENCE);

        foreach ($preflightEvidence as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                $shapeErrors[] = ['id' => 'unknown_durable_ledger_write_execution_preflight_key', 'key' => (string) $key];
            }
        }

        foreach (self::REQUIRED_PREFLIGHT_EVIDENCE as $key) {
            if (! array_key_exists($key, $preflightEvidence)) {
                $shapeErrors[] = ['id' => 'missing_required_durable_ledger_write_execution_preflight', 'key' => $key];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($preflightEvidence[$key])) {
                $shapeErrors[] = ['id' => 'durable_ledger_write_execution_preflight_must_be_boolean', 'key' => $key];
                $failed[] = $key;

                continue;
            }

            if ($preflightEvidence[$key] !== true) {
                $failed[] = $key;
            }
        }

        foreach (['durable_ledger_write_surface', 'append_only_write_plan_ref', 'idempotency_key_strategy', 'operator_confirmation_surface', 'rollback_or_replay_plan_ref', 'payload_hash', 'ledger_write_receipt_ref', 'owner'] as $key) {
            if (! is_string($preflightEvidence[$key] ?? null) || trim((string) $preflightEvidence[$key]) === '') {
                $shapeErrors[] = ['id' => 'durable_ledger_write_execution_preflight_text_required', 'key' => $key];
            }
        }

        return [
            'status' => $shapeErrors === []
                ? ($failed === [] ? 'complete' : 'incomplete')
                : 'invalid_shape',
            'required_keys' => self::REQUIRED_PREFLIGHT_EVIDENCE,
            'passed_count' => count(self::REQUIRED_PREFLIGHT_EVIDENCE) - count(array_unique($failed)),
            'failed_count' => count(array_unique($failed)),
            'failed_keys' => array_values(array_unique($failed)),
            'shape_error_count' => count($shapeErrors),
            'shape_errors' => $shapeErrors,
        ];
    }

    /**
     * @param  array<string,mixed>  $handoff
     * @param  array<string,mixed>  $evidence
     */
    private function status(array $handoff, array $evidence): string
    {
        if (($handoff['status'] ?? null) !== 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_handoff_ready_for_future_execution_ap') {
            return 'blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_handoff_packet';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight_incomplete';
        }

        return 'ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_review';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_review' => 'request_human_decision_before_any_durable_ledger_write_execution',
            'blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_handoff_packet' => 'repair_result_persistence_execution_ledger_write_execution_handoff_before_durable_execution_preflight',
            'blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight_shape' => 'fix_durable_ledger_write_execution_preflight_shape',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight_incomplete' => 'complete_durable_ledger_write_execution_preflight_evidence',
            default => 'review_durable_ledger_write_execution_preflight_status',
        };
    }
}
