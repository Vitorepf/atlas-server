<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistencePreflight
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_preflight.v1';

    /** @var array<int,string> */
    private const REQUIRED_PREFLIGHT_EVIDENCE = [
        'reviewed_runtime_execution_activation_implementation_execution_result_receipt',
        'declared_result_acceptance_reported',
        'declared_persistence_target',
        'declared_evidence_event_family',
        'declared_payload_redaction',
        'confirmed_policy_receipt_attached',
        'confirmed_operator_confirmation_required',
        'confirmed_no_auto_persistence',
        'confirmed_no_command_execution',
        'confirmed_no_runtime_job_created',
        'confirmed_no_ledger_write',
        'confirmed_no_payload_executed',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_PREFLIGHT_EVIDENCE = [
        'evidence_event_family',
        'ledger_write_plan_ref',
        'notes',
        'owner',
        'payload_hash',
        'persistence_target',
        'policy_receipt_source',
        'redaction_strategy',
        'rollback_plan_ref',
    ];

    /**
     * @param  array<string,mixed>  $resultReceipt
     * @param  array<string,mixed>  $preflightEvidence
     * @return array<string,mixed>
     */
    public function preflight(array $resultReceipt, array $preflightEvidence): array
    {
        $evidence = $this->evaluateEvidence($preflightEvidence);
        $status = $this->status($resultReceipt, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_preflight',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_preflight_only_no_ledger_write',
            'work_title' => $resultReceipt['work_title'] ?? null,
            'resolved_target_ap' => $resultReceipt['resolved_target_ap'] ?? null,
            'runtime_execution_activation_implementation_execution_result_receipt_summary' => [
                'schema_version' => $resultReceipt['schema_version'] ?? null,
                'status' => $resultReceipt['status'] ?? null,
                'decision' => data_get($resultReceipt, 'runtime_execution_activation_implementation_execution_result_decision_summary.decision'),
                'result_status' => data_get($resultReceipt, 'runtime_execution_activation_implementation_execution_result_decision_summary.result_status'),
                'artifact_refs' => data_get($resultReceipt, 'runtime_execution_activation_implementation_execution_result_decision_summary.artifact_refs', []),
                'executor_receipt_ref' => data_get($resultReceipt, 'runtime_execution_activation_implementation_execution_result_decision_summary.executor_receipt_ref'),
                'rollback_plan_ref' => data_get($resultReceipt, 'runtime_execution_activation_implementation_execution_result_decision_summary.rollback_plan_ref'),
                'policy_receipt_source' => data_get($resultReceipt, 'runtime_execution_activation_implementation_execution_result_decision_summary.policy_receipt_source'),
            ],
            'runtime_execution_activation_implementation_execution_result_persistence_preflight_evidence' => $evidence,
            'persistence_plan' => [
                'persistence_target' => $preflightEvidence['persistence_target'] ?? null,
                'evidence_event_family' => $preflightEvidence['evidence_event_family'] ?? null,
                'redaction_strategy' => $preflightEvidence['redaction_strategy'] ?? null,
                'payload_hash' => $preflightEvidence['payload_hash'] ?? null,
                'ledger_write_plan_ref' => $preflightEvidence['ledger_write_plan_ref'] ?? null,
                'owner' => $preflightEvidence['owner'] ?? null,
            ],
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_preflight' => false,
                'publishes_release' => false,
                'emits_evidence_event' => false,
                'writes_evidence_ledger' => false,
                'creates_runtime_job' => false,
                'runs_dry_run' => false,
                'executes_authorized_work' => false,
                'performs_activation' => false,
                'executes_runtime_payload' => false,
                'accepts_without_result_acceptance_receipt' => false,
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
                $shapeErrors[] = [
                    'id' => 'unknown_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_preflight_key',
                    'key' => (string) $key,
                ];
            }
        }

        foreach (self::REQUIRED_PREFLIGHT_EVIDENCE as $key) {
            if (! array_key_exists($key, $preflightEvidence)) {
                $shapeErrors[] = [
                    'id' => 'missing_required_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_preflight',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($preflightEvidence[$key])) {
                $shapeErrors[] = [
                    'id' => 'release_evidence_runtime_execution_activation_implementation_execution_result_persistence_preflight_must_be_boolean',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if ($preflightEvidence[$key] !== true) {
                $failed[] = $key;
            }
        }

        foreach (['persistence_target', 'evidence_event_family', 'redaction_strategy', 'payload_hash', 'owner'] as $key) {
            if (! is_string($preflightEvidence[$key] ?? null) || trim((string) $preflightEvidence[$key]) === '') {
                $shapeErrors[] = [
                    'id' => 'release_evidence_runtime_execution_activation_implementation_execution_result_persistence_preflight_text_required',
                    'key' => $key,
                ];
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
     * @param  array<string,mixed>  $resultReceipt
     * @param  array<string,mixed>  $evidence
     */
    private function status(array $resultReceipt, array $evidence): string
    {
        if (($resultReceipt['status'] ?? null) !== 'runtime_execution_activation_implementation_execution_result_acceptance_reported') {
            return 'blocked_by_runtime_execution_activation_implementation_execution_result_decision_receipt';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_preflight_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'runtime_execution_activation_implementation_execution_result_persistence_preflight_incomplete';
        }

        return 'ready_for_runtime_execution_activation_implementation_execution_result_persistence_review';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'ready_for_runtime_execution_activation_implementation_execution_result_persistence_review' => 'request_human_decision_before_any_result_persistence_or_ledger_write',
            'blocked_by_runtime_execution_activation_implementation_execution_result_decision_receipt' => 'repair_or_accept_result_review_before_persistence_preflight',
            'blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_preflight_shape' => 'fix_runtime_execution_activation_implementation_execution_result_persistence_preflight_shape',
            'runtime_execution_activation_implementation_execution_result_persistence_preflight_incomplete' => 'complete_runtime_execution_activation_implementation_execution_result_persistence_preflight_evidence',
            default => 'review_runtime_execution_activation_implementation_execution_result_persistence_preflight_status',
        };
    }
}
