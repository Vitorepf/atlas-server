<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceHandoffPacket
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_handoff_packet.v1';

    /** @var array<int,string> */
    private const REQUIRED_HANDOFF_EVIDENCE = [
        'reviewed_release_evidence_preflight',
        'confirmed_future_ap_owner',
        'confirmed_no_runtime_side_effect',
        'confirmed_no_direct_release_execution',
        'confirmed_no_direct_ledger_write',
        'confirmed_followup_ap_required',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_HANDOFF_EVIDENCE = [
        'commands',
        'future_ap',
        'notes',
        'owner',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidencePreflight $releaseEvidencePreflight,
    ) {}

    /**
     * @param  array<int,string>  $intendedPaths
     * @param  array<string,mixed>  $validationEvidence
     * @param  array<int,mixed>  $traceSteps
     * @param  array<string,mixed>  $integratorEvidence
     * @param  array<string,mixed>  $integrationEvidence
     * @param  array<string,mixed>  $finalAuditEvidence
     * @param  array<string,mixed>  $preflightEvidence
     * @param  array<string,mixed>  $handoffEvidence
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
        string $closeoutDecision,
        array $preflightEvidence,
        array $handoffEvidence,
        ?string $closeoutReason = null,
        ?string $reason = null,
        ?string $docsApPath = null,
        int|string|null $targetAp = null,
        ?int $requestedApNumber = null,
        ?string $proposedSlug = null,
    ): array {
        $preflight = $this->releaseEvidencePreflight->preflight(
            workTitle: $workTitle,
            intendedPaths: $intendedPaths,
            validationEvidence: $validationEvidence,
            traceSteps: $traceSteps,
            decision: $decision,
            integratorEvidence: $integratorEvidence,
            integrationEvidence: $integrationEvidence,
            finalAuditEvidence: $finalAuditEvidence,
            closeoutDecision: $closeoutDecision,
            preflightEvidence: $preflightEvidence,
            closeoutReason: $closeoutReason,
            reason: $reason,
            docsApPath: $docsApPath,
            targetAp: $targetAp,
            requestedApNumber: $requestedApNumber,
            proposedSlug: $proposedSlug,
        );
        $evidence = $this->evaluateEvidence($handoffEvidence);
        $status = $this->status($preflight, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_handoff_packet',
            'authority' => 'ap_agent_workflow_release_evidence_handoff_only_no_execution',
            'work_title' => $preflight['work_title'],
            'resolved_target_ap' => $preflight['resolved_target_ap'],
            'release_evidence_preflight_summary' => [
                'schema_version' => $preflight['schema_version'],
                'status' => $preflight['status'],
                'closeout_acceptance_status' => data_get($preflight, 'closeout_acceptance_summary.status'),
                'preflight_evidence_status' => data_get($preflight, 'preflight_evidence.status'),
            ],
            'handoff_evidence' => $evidence,
            'handoff_target' => [
                'future_ap' => $handoffEvidence['future_ap'] ?? null,
                'owner' => $handoffEvidence['owner'] ?? null,
            ],
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_packet' => false,
                'publishes_release' => false,
                'emits_evidence_event' => false,
                'creates_release_surface' => false,
                'writes_evidence_ledger' => false,
                'replaces_release_or_evidence_ap' => false,
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
                $shapeErrors[] = [
                    'id' => 'unknown_release_evidence_handoff_key',
                    'key' => (string) $key,
                ];
            }
        }

        foreach (self::REQUIRED_HANDOFF_EVIDENCE as $key) {
            if (! array_key_exists($key, $handoffEvidence)) {
                $shapeErrors[] = [
                    'id' => 'missing_required_release_evidence_handoff',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($handoffEvidence[$key])) {
                $shapeErrors[] = [
                    'id' => 'release_evidence_handoff_must_be_boolean',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if ($handoffEvidence[$key] !== true) {
                $failed[] = $key;
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
     * @param  array<string,mixed>  $preflight
     * @param  array<string,mixed>  $evidence
     */
    private function status(array $preflight, array $evidence): string
    {
        if (($preflight['status'] ?? null) !== 'ready_for_future_release_or_evidence_layer') {
            return 'blocked_by_release_evidence_preflight';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_release_evidence_handoff_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'release_evidence_handoff_incomplete';
        }

        return 'ready_for_release_evidence_owner_review';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'ready_for_release_evidence_owner_review' => 'future_release_or_evidence_owner_reviews_packet_before_new_ap',
            'blocked_by_release_evidence_preflight' => 'repair_release_evidence_preflight_before_handoff_packet',
            'blocked_invalid_release_evidence_handoff_shape' => 'fix_release_evidence_handoff_shape_before_owner_review',
            'release_evidence_handoff_incomplete' => 'complete_release_evidence_handoff_before_owner_review',
            default => 'review_release_evidence_handoff_packet_status',
        };
    }
}
