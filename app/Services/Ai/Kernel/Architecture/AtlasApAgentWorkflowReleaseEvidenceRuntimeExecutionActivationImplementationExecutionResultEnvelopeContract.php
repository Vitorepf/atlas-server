<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultEnvelopeContract
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_envelope_contract.v1';

    /** @var array<int,string> */
    private const REQUIRED_RESULT_EVIDENCE = [
        'reviewed_runtime_execution_activation_implementation_execution_handoff',
        'declared_runtime_execution_activation_implementation_execution_result',
        'declared_result_status',
        'declared_result_artifacts',
        'declared_result_validation_summary',
        'confirmed_execution_happened_outside_this_contract',
        'confirmed_policy_receipt_attached',
        'confirmed_operator_confirmation_preserved',
        'confirmed_no_command_execution',
        'confirmed_no_runtime_job_created',
        'confirmed_no_ledger_write',
        'confirmed_no_payload_executed_by_this_contract',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_RESULT_EVIDENCE = [
        'artifact_refs',
        'executor_receipt_ref',
        'notes',
        'owner',
        'policy_receipt_source',
        'result_status',
        'result_summary',
        'result_validation_summary',
        'rollback_plan_ref',
    ];

    /**
     * @param  array<string,mixed>  $handoffPacket
     * @param  array<string,mixed>  $resultEvidence
     * @return array<string,mixed>
     */
    public function envelope(array $handoffPacket, array $resultEvidence): array
    {
        $evidence = $this->evaluateEvidence($resultEvidence);
        $status = $this->status($handoffPacket, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_runtime_execution_activation_implementation_execution_result_envelope',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_envelope_only_no_execution',
            'work_title' => $handoffPacket['work_title'] ?? null,
            'resolved_target_ap' => $handoffPacket['resolved_target_ap'] ?? null,
            'runtime_execution_activation_implementation_execution_handoff_summary' => [
                'schema_version' => $handoffPacket['schema_version'] ?? null,
                'status' => $handoffPacket['status'] ?? null,
                'future_ap' => data_get($handoffPacket, 'handoff_target.future_runtime_execution_activation_implementation_execution_ap'),
                'owner' => data_get($handoffPacket, 'handoff_target.owner'),
                'package' => $handoffPacket['runtime_execution_activation_implementation_execution_package'] ?? null,
                'receipt_ref' => $handoffPacket['runtime_execution_activation_implementation_execution_receipt_ref'] ?? null,
            ],
            'runtime_execution_activation_implementation_execution_result_evidence' => $evidence,
            'result_summary' => [
                'result_status' => $resultEvidence['result_status'] ?? null,
                'artifact_refs' => $resultEvidence['artifact_refs'] ?? [],
                'executor_receipt_ref' => $resultEvidence['executor_receipt_ref'] ?? null,
                'result_validation_summary' => $resultEvidence['result_validation_summary'] ?? null,
                'rollback_plan_ref' => $resultEvidence['rollback_plan_ref'] ?? null,
                'policy_receipt_source' => $resultEvidence['policy_receipt_source'] ?? null,
            ],
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_envelope' => false,
                'publishes_release' => false,
                'emits_evidence_event' => false,
                'writes_evidence_ledger' => false,
                'creates_runtime_job' => false,
                'runs_dry_run' => false,
                'executes_authorized_work' => false,
                'performs_activation' => false,
                'executes_runtime_payload' => false,
                'accepts_without_runtime_execution_activation_implementation_execution_handoff' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $resultEvidence
     * @return array<string,mixed>
     */
    private function evaluateEvidence(array $resultEvidence): array
    {
        $failed = [];
        $shapeErrors = [];
        $allowedKeys = array_merge(self::REQUIRED_RESULT_EVIDENCE, self::OPTIONAL_RESULT_EVIDENCE);

        foreach ($resultEvidence as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                $shapeErrors[] = [
                    'id' => 'unknown_release_evidence_runtime_execution_activation_implementation_execution_result_key',
                    'key' => (string) $key,
                ];
            }
        }

        foreach (self::REQUIRED_RESULT_EVIDENCE as $key) {
            if (! array_key_exists($key, $resultEvidence)) {
                $shapeErrors[] = [
                    'id' => 'missing_required_release_evidence_runtime_execution_activation_implementation_execution_result',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($resultEvidence[$key])) {
                $shapeErrors[] = [
                    'id' => 'release_evidence_runtime_execution_activation_implementation_execution_result_must_be_boolean',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if ($resultEvidence[$key] !== true) {
                $failed[] = $key;
            }
        }

        foreach (['result_status', 'result_summary', 'result_validation_summary', 'owner', 'executor_receipt_ref'] as $key) {
            if (! is_string($resultEvidence[$key] ?? null) || trim((string) $resultEvidence[$key]) === '') {
                $shapeErrors[] = [
                    'id' => 'release_evidence_runtime_execution_activation_implementation_execution_result_text_required',
                    'key' => $key,
                ];
            }
        }

        if (! is_array($resultEvidence['artifact_refs'] ?? null) || ($resultEvidence['artifact_refs'] ?? []) === []) {
            $shapeErrors[] = [
                'id' => 'release_evidence_runtime_execution_activation_implementation_execution_result_artifacts_required',
                'key' => 'artifact_refs',
            ];
        }

        return [
            'status' => $shapeErrors === []
                ? ($failed === [] ? 'complete' : 'incomplete')
                : 'invalid_shape',
            'required_keys' => self::REQUIRED_RESULT_EVIDENCE,
            'passed_count' => count(self::REQUIRED_RESULT_EVIDENCE) - count(array_unique($failed)),
            'failed_count' => count(array_unique($failed)),
            'failed_keys' => array_values(array_unique($failed)),
            'shape_error_count' => count($shapeErrors),
            'shape_errors' => $shapeErrors,
        ];
    }

    /**
     * @param  array<string,mixed>  $handoffPacket
     * @param  array<string,mixed>  $evidence
     */
    private function status(array $handoffPacket, array $evidence): string
    {
        if (($handoffPacket['status'] ?? null) !== 'runtime_execution_activation_implementation_execution_handoff_ready_for_future_execution_ap') {
            return 'blocked_by_runtime_execution_activation_implementation_execution_handoff_packet';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_runtime_execution_activation_implementation_execution_result_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'runtime_execution_activation_implementation_execution_result_evidence_incomplete';
        }

        return 'runtime_execution_activation_implementation_execution_result_envelope_ready_for_human_review';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'runtime_execution_activation_implementation_execution_result_envelope_ready_for_human_review' => 'review_runtime_execution_activation_implementation_execution_result_before_any_ledger_write',
            'blocked_by_runtime_execution_activation_implementation_execution_handoff_packet' => 'repair_runtime_execution_activation_implementation_execution_handoff_before_result_envelope',
            'blocked_invalid_runtime_execution_activation_implementation_execution_result_shape' => 'fix_runtime_execution_activation_implementation_execution_result_shape_before_review',
            'runtime_execution_activation_implementation_execution_result_evidence_incomplete' => 'complete_runtime_execution_activation_implementation_execution_result_evidence_before_review',
            default => 'review_runtime_execution_activation_implementation_execution_result_status',
        };
    }
}
