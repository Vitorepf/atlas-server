<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowManualIntegrationReceipt
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_manual_integration_receipt.v1';

    /** @var array<int,string> */
    private const REQUIRED_EVIDENCE = [
        'manually_applied_by_integrator',
        'applied_paths_match_handoff_scope',
        'final_diff_reviewed',
        'final_validation_reran',
        'final_docs_health_checked',
        'no_unrelated_work_included',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_EVIDENCE = [
        'notes',
        'commands',
        'integration_reference',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowIntegratorReadinessContract $integratorReadinessContract,
    ) {}

    /**
     * @param  array<int,string>  $intendedPaths
     * @param  array<string,mixed>  $validationEvidence
     * @param  array<int,mixed>  $traceSteps
     * @param  array<string,mixed>  $integratorEvidence
     * @param  array<string,mixed>  $integrationEvidence
     * @return array<string,mixed>
     */
    public function receipt(
        string $workTitle,
        array $intendedPaths,
        array $validationEvidence,
        array $traceSteps,
        string $decision,
        array $integratorEvidence,
        array $integrationEvidence,
        ?string $reason = null,
        ?string $docsApPath = null,
        int|string|null $targetAp = null,
        ?int $requestedApNumber = null,
        ?string $proposedSlug = null,
    ): array {
        $readiness = $this->integratorReadinessContract->readiness(
            workTitle: $workTitle,
            intendedPaths: $intendedPaths,
            validationEvidence: $validationEvidence,
            traceSteps: $traceSteps,
            decision: $decision,
            integratorEvidence: $integratorEvidence,
            reason: $reason,
            docsApPath: $docsApPath,
            targetAp: $targetAp,
            requestedApNumber: $requestedApNumber,
            proposedSlug: $proposedSlug,
        );
        $evidence = $this->evaluateEvidence($integrationEvidence);
        $status = $this->status($readiness, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_manual_integration_receipt',
            'authority' => 'ap_agent_workflow_manual_integration_receipt_only_no_execution',
            'work_title' => $readiness['work_title'],
            'resolved_target_ap' => $readiness['resolved_target_ap'],
            'readiness_summary' => [
                'schema_version' => $readiness['schema_version'],
                'status' => $readiness['status'],
                'handoff_status' => data_get($readiness, 'handoff_summary.status'),
                'integrator_evidence_status' => data_get($readiness, 'integrator_evidence.status'),
            ],
            'manual_integration_evidence' => $evidence,
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_receipt' => false,
                'auto_merges_work' => false,
                'claims_integration_without_readiness' => false,
                'claims_integration_without_evidence' => false,
                'replaces_evidence_ledger' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $integrationEvidence
     * @return array<string,mixed>
     */
    private function evaluateEvidence(array $integrationEvidence): array
    {
        $failed = [];
        $shapeErrors = [];
        $allowedKeys = array_merge(self::REQUIRED_EVIDENCE, self::OPTIONAL_EVIDENCE);

        foreach ($integrationEvidence as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                $shapeErrors[] = [
                    'id' => 'unknown_manual_integration_evidence_key',
                    'key' => (string) $key,
                ];
            }
        }

        foreach (self::REQUIRED_EVIDENCE as $key) {
            if (! array_key_exists($key, $integrationEvidence)) {
                $shapeErrors[] = [
                    'id' => 'missing_required_manual_integration_evidence',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($integrationEvidence[$key])) {
                $shapeErrors[] = [
                    'id' => 'manual_integration_evidence_must_be_boolean',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if ($integrationEvidence[$key] !== true) {
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
     * @param  array<string,mixed>  $readiness
     * @param  array<string,mixed>  $evidence
     */
    private function status(array $readiness, array $evidence): string
    {
        if (($readiness['status'] ?? null) !== 'ready_for_manual_integration') {
            return 'blocked_by_integrator_readiness';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_manual_integration_evidence_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'manual_integration_evidence_incomplete';
        }

        return 'manual_integration_reported';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'manual_integration_reported' => 'present_manual_integration_receipt_for_final_human_audit',
            'blocked_by_integrator_readiness' => 'repair_integrator_readiness_before_reporting_integration',
            'blocked_invalid_manual_integration_evidence_shape' => 'fix_manual_integration_evidence_shape_before_receipt',
            'manual_integration_evidence_incomplete' => 'complete_manual_integration_evidence_before_receipt',
            default => 'review_manual_integration_receipt_status',
        };
    }
}
