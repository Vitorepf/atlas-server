<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowIntegratorReadinessContract
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_integrator_readiness_contract.v1';

    /** @var array<int,string> */
    private const REQUIRED_EVIDENCE = [
        'reviewed_handoff_packet',
        'reviewed_diff_scope',
        'reviewed_validation_output',
        'confirmed_no_hot_file_conflict',
        'confirmed_no_unrelated_reverts',
        'confirmed_manual_integration_owner',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_EVIDENCE = [
        'notes',
        'commands',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowIntegratorHandoffPacket $integratorHandoffPacket,
    ) {}

    /**
     * @param  array<int,string>  $intendedPaths
     * @param  array<string,mixed>  $validationEvidence
     * @param  array<int,mixed>  $traceSteps
     * @param  array<string,mixed>  $integratorEvidence
     * @return array<string,mixed>
     */
    public function readiness(
        string $workTitle,
        array $intendedPaths,
        array $validationEvidence,
        array $traceSteps,
        string $decision,
        array $integratorEvidence,
        ?string $reason = null,
        ?string $docsApPath = null,
        int|string|null $targetAp = null,
        ?int $requestedApNumber = null,
        ?string $proposedSlug = null,
    ): array {
        $handoff = $this->integratorHandoffPacket->handoff(
            workTitle: $workTitle,
            intendedPaths: $intendedPaths,
            validationEvidence: $validationEvidence,
            traceSteps: $traceSteps,
            decision: $decision,
            reason: $reason,
            docsApPath: $docsApPath,
            targetAp: $targetAp,
            requestedApNumber: $requestedApNumber,
            proposedSlug: $proposedSlug,
        );
        $evidence = $this->evaluateEvidence($integratorEvidence);
        $status = $this->status($handoff, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_integrator_readiness_contract',
            'authority' => 'ap_agent_workflow_integrator_readiness_only_no_execution',
            'work_title' => $handoff['work_title'],
            'resolved_target_ap' => $handoff['resolved_target_ap'],
            'handoff_summary' => [
                'schema_version' => $handoff['schema_version'],
                'status' => $handoff['status'],
                'human_decision_status' => data_get($handoff, 'human_decision_summary.status'),
                'human_decision' => data_get($handoff, 'human_decision_summary.decision'),
                'path_count' => data_get($handoff, 'integration_scope.path_count'),
            ],
            'integrator_evidence' => $evidence,
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_readiness' => false,
                'auto_merges_work' => false,
                'marks_ready_without_handoff' => false,
                'marks_ready_without_integrator_evidence' => false,
                'replaces_evidence_ledger' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $integratorEvidence
     * @return array<string,mixed>
     */
    private function evaluateEvidence(array $integratorEvidence): array
    {
        $failed = [];
        $shapeErrors = [];
        $allowedKeys = array_merge(self::REQUIRED_EVIDENCE, self::OPTIONAL_EVIDENCE);

        foreach ($integratorEvidence as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                $shapeErrors[] = [
                    'id' => 'unknown_integrator_evidence_key',
                    'key' => (string) $key,
                ];
            }
        }

        foreach (self::REQUIRED_EVIDENCE as $key) {
            if (! array_key_exists($key, $integratorEvidence)) {
                $shapeErrors[] = [
                    'id' => 'missing_required_integrator_evidence',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($integratorEvidence[$key])) {
                $shapeErrors[] = [
                    'id' => 'integrator_evidence_must_be_boolean',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if ($integratorEvidence[$key] !== true) {
                $failed[] = $key;
            }
        }

        return [
            'status' => $shapeErrors === []
                ? ($failed === [] ? 'complete' : 'incomplete')
                : 'invalid_shape',
            'required_keys' => self::REQUIRED_EVIDENCE,
            'passed_count' => count(self::REQUIRED_EVIDENCE) - count(array_unique($failed)),
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
        if (($handoff['status'] ?? null) !== 'ready_for_integrator_review') {
            return 'blocked_by_integrator_handoff';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_integrator_evidence_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'integrator_review_incomplete';
        }

        return 'ready_for_manual_integration';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'ready_for_manual_integration' => 'integrator_may_manually_apply_after_final_local_review',
            'blocked_by_integrator_handoff' => 'repair_integrator_handoff_before_readiness_review',
            'blocked_invalid_integrator_evidence_shape' => 'fix_integrator_evidence_shape_before_readiness_review',
            'integrator_review_incomplete' => 'complete_integrator_evidence_before_manual_integration',
            default => 'review_integrator_readiness_status',
        };
    }
}
