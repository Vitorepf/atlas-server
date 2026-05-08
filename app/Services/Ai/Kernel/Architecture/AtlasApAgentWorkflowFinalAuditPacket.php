<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowFinalAuditPacket
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_final_audit_packet.v1';

    /** @var array<int,string> */
    private const REQUIRED_AUDIT_EVIDENCE = [
        'reviewed_manual_integration_receipt',
        'reviewed_final_validation_commands',
        'reviewed_documentation_status',
        'reviewed_no_untracked_surprise',
        'reviewed_no_parallel_flow_created',
        'reviewed_remaining_risks',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_AUDIT_EVIDENCE = [
        'notes',
        'commands',
        'risk_summary',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowManualIntegrationReceipt $manualIntegrationReceipt,
    ) {}

    /**
     * @param  array<int,string>  $intendedPaths
     * @param  array<string,mixed>  $validationEvidence
     * @param  array<int,mixed>  $traceSteps
     * @param  array<string,mixed>  $integratorEvidence
     * @param  array<string,mixed>  $integrationEvidence
     * @param  array<string,mixed>  $finalAuditEvidence
     * @return array<string,mixed>
     */
    public function packet(
        string $workTitle,
        array $intendedPaths,
        array $validationEvidence,
        array $traceSteps,
        string $decision,
        array $integratorEvidence,
        array $integrationEvidence,
        array $finalAuditEvidence,
        ?string $reason = null,
        ?string $docsApPath = null,
        int|string|null $targetAp = null,
        ?int $requestedApNumber = null,
        ?string $proposedSlug = null,
    ): array {
        $receipt = $this->manualIntegrationReceipt->receipt(
            workTitle: $workTitle,
            intendedPaths: $intendedPaths,
            validationEvidence: $validationEvidence,
            traceSteps: $traceSteps,
            decision: $decision,
            integratorEvidence: $integratorEvidence,
            integrationEvidence: $integrationEvidence,
            reason: $reason,
            docsApPath: $docsApPath,
            targetAp: $targetAp,
            requestedApNumber: $requestedApNumber,
            proposedSlug: $proposedSlug,
        );
        $auditEvidence = $this->evaluateAuditEvidence($finalAuditEvidence);
        $status = $this->status($receipt, $auditEvidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_final_audit_packet',
            'authority' => 'ap_agent_workflow_final_audit_packet_only_no_execution',
            'work_title' => $receipt['work_title'],
            'resolved_target_ap' => $receipt['resolved_target_ap'],
            'manual_integration_receipt_summary' => [
                'schema_version' => $receipt['schema_version'],
                'status' => $receipt['status'],
                'readiness_status' => data_get($receipt, 'readiness_summary.status'),
                'manual_integration_evidence_status' => data_get($receipt, 'manual_integration_evidence.status'),
            ],
            'final_audit_evidence' => $auditEvidence,
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_audit' => false,
                'publishes_release' => false,
                'closes_without_manual_receipt' => false,
                'closes_without_final_audit_evidence' => false,
                'replaces_evidence_ledger' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $finalAuditEvidence
     * @return array<string,mixed>
     */
    private function evaluateAuditEvidence(array $finalAuditEvidence): array
    {
        $failed = [];
        $shapeErrors = [];
        $allowedKeys = array_merge(self::REQUIRED_AUDIT_EVIDENCE, self::OPTIONAL_AUDIT_EVIDENCE);

        foreach ($finalAuditEvidence as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                $shapeErrors[] = [
                    'id' => 'unknown_final_audit_evidence_key',
                    'key' => (string) $key,
                ];
            }
        }

        foreach (self::REQUIRED_AUDIT_EVIDENCE as $key) {
            if (! array_key_exists($key, $finalAuditEvidence)) {
                $shapeErrors[] = [
                    'id' => 'missing_required_final_audit_evidence',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($finalAuditEvidence[$key])) {
                $shapeErrors[] = [
                    'id' => 'final_audit_evidence_must_be_boolean',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if ($finalAuditEvidence[$key] !== true) {
                $failed[] = $key;
            }
        }

        return [
            'status' => $shapeErrors === []
                ? ($failed === [] ? 'complete' : 'incomplete')
                : 'invalid_shape',
            'required_keys' => self::REQUIRED_AUDIT_EVIDENCE,
            'passed_count' => count(self::REQUIRED_AUDIT_EVIDENCE) - count(array_unique($failed)),
            'failed_count' => count(array_unique($failed)),
            'failed_keys' => array_values(array_unique($failed)),
            'shape_error_count' => count($shapeErrors),
            'shape_errors' => $shapeErrors,
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @param  array<string,mixed>  $auditEvidence
     */
    private function status(array $receipt, array $auditEvidence): string
    {
        if (($receipt['status'] ?? null) !== 'manual_integration_reported') {
            return 'blocked_by_manual_integration_receipt';
        }

        if (($auditEvidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_final_audit_evidence_shape';
        }

        if (($auditEvidence['status'] ?? null) !== 'complete') {
            return 'final_audit_incomplete';
        }

        return 'ready_for_final_human_closeout_review';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'ready_for_final_human_closeout_review' => 'present_final_audit_packet_to_human_before_closeout',
            'blocked_by_manual_integration_receipt' => 'repair_manual_integration_receipt_before_final_audit',
            'blocked_invalid_final_audit_evidence_shape' => 'fix_final_audit_evidence_shape_before_closeout_review',
            'final_audit_incomplete' => 'complete_final_audit_evidence_before_closeout_review',
            default => 'review_final_audit_packet_status',
        };
    }
}
