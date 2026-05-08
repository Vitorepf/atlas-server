<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionPreflight
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_preflight.v1';

    /** @var array<int,string> */
    private const REQUIRED_PREFLIGHT_EVIDENCE = [
        'reviewed_runtime_execution_activation_implementation_handoff_packet',
        'declared_runtime_execution_activation_implementation_execution_scope',
        'declared_runtime_execution_activation_implementation_payload_hash',
        'declared_runtime_execution_activation_implementation_idempotency_key',
        'declared_operator_confirmation_surface',
        'confirmed_handoff_ready',
        'confirmed_policy_receipt_attached',
        'confirmed_runtime_payload_not_executed',
        'confirmed_runtime_job_not_created',
        'confirmed_no_command_execution',
        'confirmed_no_ledger_write',
        'confirmed_no_activation_side_effect',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_PREFLIGHT_EVIDENCE = [
        'execution_scope',
        'idempotency_key',
        'notes',
        'operator_confirmation_surface',
        'owner',
        'payload_hash',
        'policy_receipt_source',
        'rollback_plan_ref',
    ];

    /**
     * @param  array<string,mixed>  $handoffPacket
     * @param  array<string,mixed>  $executionPreflightEvidence
     * @return array<string,mixed>
     */
    public function evaluate(
        array $handoffPacket,
        array $executionPreflightEvidence,
        ?string $workTitle = null,
    ): array {
        $evidence = $this->evaluateEvidence($executionPreflightEvidence);
        $status = $this->status($handoffPacket, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_runtime_execution_activation_implementation_execution_preflight',
            'authority' => 'ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_preflight_only_no_execution',
            'work_title' => $workTitle ?? ($handoffPacket['work_title'] ?? null),
            'resolved_target_ap' => $handoffPacket['resolved_target_ap'] ?? null,
            'runtime_execution_activation_implementation_handoff_summary' => [
                'schema_version' => $handoffPacket['schema_version'] ?? null,
                'status' => $handoffPacket['status'] ?? null,
                'future_runtime_execution_activation_implementation_ap' => data_get($handoffPacket, 'handoff_target.future_runtime_execution_activation_implementation_ap'),
                'owner' => data_get($handoffPacket, 'handoff_target.owner'),
                'runtime_execution_activation_implementation_package' => $handoffPacket['runtime_execution_activation_implementation_package'] ?? null,
                'runtime_execution_activation_implementation_receipt_ref' => $handoffPacket['runtime_execution_activation_implementation_receipt_ref'] ?? null,
            ],
            'runtime_execution_activation_implementation_execution_preflight_evidence' => $evidence,
            'runtime_execution_activation_implementation_execution_target' => [
                'execution_scope' => $executionPreflightEvidence['execution_scope'] ?? null,
                'payload_hash' => $executionPreflightEvidence['payload_hash'] ?? null,
                'idempotency_key' => $executionPreflightEvidence['idempotency_key'] ?? null,
                'operator_confirmation_surface' => $executionPreflightEvidence['operator_confirmation_surface'] ?? null,
                'owner' => $executionPreflightEvidence['owner'] ?? null,
                'policy_receipt_source' => $executionPreflightEvidence['policy_receipt_source'] ?? null,
                'rollback_plan_ref' => $executionPreflightEvidence['rollback_plan_ref'] ?? null,
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
                'accepts_without_runtime_execution_activation_implementation_handoff' => false,
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
                    'id' => 'unknown_release_evidence_runtime_execution_activation_implementation_execution_preflight_key',
                    'key' => (string) $key,
                ];
            }
        }

        foreach (self::REQUIRED_PREFLIGHT_EVIDENCE as $key) {
            if (! array_key_exists($key, $preflightEvidence)) {
                $shapeErrors[] = [
                    'id' => 'missing_required_release_evidence_runtime_execution_activation_implementation_execution_preflight',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($preflightEvidence[$key])) {
                $shapeErrors[] = [
                    'id' => 'release_evidence_runtime_execution_activation_implementation_execution_preflight_must_be_boolean',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if ($preflightEvidence[$key] !== true) {
                $failed[] = $key;
            }
        }

        foreach (['execution_scope', 'payload_hash', 'idempotency_key', 'operator_confirmation_surface'] as $key) {
            if (! is_string($preflightEvidence[$key] ?? null) || trim((string) $preflightEvidence[$key]) === '') {
                $shapeErrors[] = [
                    'id' => 'release_evidence_runtime_execution_activation_implementation_execution_preflight_text_required',
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
     * @param  array<string,mixed>  $handoffPacket
     * @param  array<string,mixed>  $evidence
     */
    private function status(array $handoffPacket, array $evidence): string
    {
        if (($handoffPacket['status'] ?? null) !== 'runtime_execution_activation_implementation_handoff_ready_for_future_execution_ap') {
            return 'blocked_by_runtime_execution_activation_implementation_handoff_packet';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_runtime_execution_activation_implementation_execution_preflight_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'runtime_execution_activation_implementation_execution_preflight_incomplete';
        }

        return 'ready_for_runtime_execution_activation_implementation_execution_review';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'ready_for_runtime_execution_activation_implementation_execution_review' => 'human_may_review_runtime_execution_activation_implementation_execution_without_auto_execution',
            'blocked_by_runtime_execution_activation_implementation_handoff_packet' => 'repair_runtime_execution_activation_implementation_handoff_before_execution_preflight',
            'blocked_invalid_runtime_execution_activation_implementation_execution_preflight_shape' => 'fix_runtime_execution_activation_implementation_execution_preflight_shape_before_review',
            'runtime_execution_activation_implementation_execution_preflight_incomplete' => 'complete_runtime_execution_activation_implementation_execution_preflight_evidence_before_review',
            default => 'review_runtime_execution_activation_implementation_execution_preflight_status',
        };
    }
}
