<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWritePreflight
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight.v1';

    /** @var array<int,string> */
    private const REQUIRED_PREFLIGHT_EVIDENCE = [
        'reviewed_runtime_execution_activation_implementation_execution_result_persistence_execution_handoff',
        'declared_persistence_execution_handoff_ready',
        'declared_ledger_write_execution_surface',
        'declared_ledger_write_plan_ref',
        'declared_idempotency_key_strategy',
        'declared_operator_confirmation_surface',
        'declared_rollback_plan',
        'confirmed_policy_receipt_attached',
        'confirmed_payload_hash_attached',
        'confirmed_redaction_or_hash_strategy_attached',
        'confirmed_no_auto_execution',
        'confirmed_no_command_execution',
        'confirmed_no_runtime_job_created',
        'confirmed_no_ledger_write',
        'confirmed_no_evidence_event_emitted',
        'confirmed_no_payload_executed',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_PREFLIGHT_EVIDENCE = [
        'execution_surface',
        'idempotency_key_strategy',
        'ledger_write_plan_ref',
        'notes',
        'operator_confirmation_surface',
        'owner',
        'payload_hash',
        'policy_receipt_source',
        'rollback_plan_ref',
    ];

    /**
     * @param  array<string,mixed>  $executionHandoff
     * @param  array<string,mixed>  $preflightEvidence
     * @return array<string,mixed>
     */
    public function preflight(array $executionHandoff, array $preflightEvidence): array
    {
        $evidence = $this->evaluateEvidence($preflightEvidence);
        $status = $this->status($executionHandoff, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight_only_no_ledger_write',
            'work_title' => $executionHandoff['work_title'] ?? null,
            'resolved_target_ap' => $executionHandoff['resolved_target_ap'] ?? null,
            'runtime_execution_activation_implementation_execution_result_persistence_execution_handoff_summary' => [
                'schema_version' => $executionHandoff['schema_version'] ?? null,
                'status' => $executionHandoff['status'] ?? null,
                'future_ap' => data_get($executionHandoff, 'handoff_target.future_runtime_execution_activation_implementation_execution_result_persistence_execution_ap'),
                'operator_confirmation_surface' => data_get($executionHandoff, 'handoff_target.operator_confirmation_surface'),
                'decision' => data_get($executionHandoff, 'runtime_execution_activation_implementation_execution_result_persistence_execution_receipt_summary.decision'),
                'execution_surface' => data_get($executionHandoff, 'runtime_execution_activation_implementation_execution_result_persistence_execution_receipt_summary.execution_surface'),
                'idempotency_key_strategy' => data_get($executionHandoff, 'runtime_execution_activation_implementation_execution_result_persistence_execution_receipt_summary.idempotency_key_strategy'),
                'rollback_plan_ref' => data_get($executionHandoff, 'runtime_execution_activation_implementation_execution_result_persistence_execution_receipt_summary.rollback_plan_ref'),
                'payload_hash' => data_get($executionHandoff, 'runtime_execution_activation_implementation_execution_result_persistence_execution_receipt_summary.payload_hash'),
                'ledger_write_plan_ref' => data_get($executionHandoff, 'runtime_execution_activation_implementation_execution_result_persistence_execution_receipt_summary.ledger_write_plan_ref'),
                'owner' => data_get($executionHandoff, 'runtime_execution_activation_implementation_execution_result_persistence_execution_receipt_summary.owner'),
                'policy_receipt_source' => data_get($executionHandoff, 'runtime_execution_activation_implementation_execution_result_persistence_execution_receipt_summary.policy_receipt_source'),
            ],
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight_evidence' => $evidence,
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_target' => [
                'execution_surface' => $preflightEvidence['execution_surface'] ?? null,
                'idempotency_key_strategy' => $preflightEvidence['idempotency_key_strategy'] ?? null,
                'operator_confirmation_surface' => $preflightEvidence['operator_confirmation_surface'] ?? null,
                'rollback_plan_ref' => $preflightEvidence['rollback_plan_ref'] ?? null,
                'payload_hash' => $preflightEvidence['payload_hash'] ?? null,
                'ledger_write_plan_ref' => $preflightEvidence['ledger_write_plan_ref'] ?? null,
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
                'accepts_without_persistence_execution_handoff' => false,
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
                $shapeErrors[] = ['id' => 'unknown_result_persistence_execution_ledger_write_preflight_key', 'key' => (string) $key];
            }
        }

        foreach (self::REQUIRED_PREFLIGHT_EVIDENCE as $key) {
            if (! array_key_exists($key, $preflightEvidence)) {
                $shapeErrors[] = ['id' => 'missing_required_result_persistence_execution_ledger_write_preflight', 'key' => $key];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($preflightEvidence[$key])) {
                $shapeErrors[] = ['id' => 'result_persistence_execution_ledger_write_preflight_must_be_boolean', 'key' => $key];
                $failed[] = $key;

                continue;
            }

            if ($preflightEvidence[$key] !== true) {
                $failed[] = $key;
            }
        }

        foreach (['execution_surface', 'idempotency_key_strategy', 'operator_confirmation_surface', 'rollback_plan_ref', 'payload_hash', 'ledger_write_plan_ref', 'owner'] as $key) {
            if (! is_string($preflightEvidence[$key] ?? null) || trim((string) $preflightEvidence[$key]) === '') {
                $shapeErrors[] = ['id' => 'result_persistence_execution_ledger_write_preflight_text_required', 'key' => $key];
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
     * @param  array<string,mixed>  $executionHandoff
     * @param  array<string,mixed>  $evidence
     */
    private function status(array $executionHandoff, array $evidence): string
    {
        if (($executionHandoff['status'] ?? null) !== 'runtime_execution_activation_implementation_execution_result_persistence_execution_handoff_ready_for_future_execution_ap') {
            return 'blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_handoff_packet';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight_incomplete';
        }

        return 'ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_review';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_review' => 'request_human_decision_before_any_result_persistence_execution_ledger_write',
            'blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_handoff_packet' => 'repair_result_persistence_execution_handoff_before_ledger_write_preflight',
            'blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight_shape' => 'fix_result_persistence_execution_ledger_write_preflight_shape',
            'runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_preflight_incomplete' => 'complete_result_persistence_execution_ledger_write_preflight_evidence',
            default => 'review_result_persistence_execution_ledger_write_preflight_status',
        };
    }
}
