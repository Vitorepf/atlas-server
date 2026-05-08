<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentWorkflowReleaseEvidenceCandidateContract
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_workflow_release_evidence_candidate_contract.v1';

    /** @var array<int,string> */
    private const REQUIRED_CANDIDATE_EVIDENCE = [
        'reviewed_release_evidence_handoff_packet',
        'selected_candidate_kind',
        'described_payload_schema',
        'confirmed_append_only_or_release_review',
        'confirmed_human_review_before_execution',
        'confirmed_no_runtime_mutation',
    ];

    /** @var array<int,string> */
    private const OPTIONAL_CANDIDATE_EVIDENCE = [
        'candidate_kind',
        'commands',
        'notes',
        'payload_schema',
    ];

    /** @var array<int,string> */
    private const ALLOWED_CANDIDATE_KINDS = [
        'evidence_ledger_candidate',
        'release_review_candidate',
    ];

    public function __construct(
        private readonly AtlasApAgentWorkflowReleaseEvidenceHandoffPacket $releaseEvidenceHandoffPacket,
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
     * @param  array<string,mixed>  $candidateEvidence
     * @return array<string,mixed>
     */
    public function candidate(
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
        array $candidateEvidence,
        ?string $closeoutReason = null,
        ?string $reason = null,
        ?string $docsApPath = null,
        int|string|null $targetAp = null,
        ?int $requestedApNumber = null,
        ?string $proposedSlug = null,
    ): array {
        $handoff = $this->releaseEvidenceHandoffPacket->packet(
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
            handoffEvidence: $handoffEvidence,
            closeoutReason: $closeoutReason,
            reason: $reason,
            docsApPath: $docsApPath,
            targetAp: $targetAp,
            requestedApNumber: $requestedApNumber,
            proposedSlug: $proposedSlug,
        );
        $evidence = $this->evaluateEvidence($candidateEvidence);
        $status = $this->status($handoff, $evidence);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_release_evidence_candidate_contract',
            'authority' => 'ap_agent_workflow_release_evidence_candidate_only_no_execution',
            'work_title' => $handoff['work_title'],
            'resolved_target_ap' => $handoff['resolved_target_ap'],
            'release_evidence_handoff_summary' => [
                'schema_version' => $handoff['schema_version'],
                'status' => $handoff['status'],
                'preflight_status' => data_get($handoff, 'release_evidence_preflight_summary.status'),
                'handoff_evidence_status' => data_get($handoff, 'handoff_evidence.status'),
                'future_ap' => data_get($handoff, 'handoff_target.future_ap'),
                'owner' => data_get($handoff, 'handoff_target.owner'),
            ],
            'candidate_evidence' => $evidence,
            'candidate' => [
                'kind' => $candidateEvidence['candidate_kind'] ?? null,
                'payload_schema' => $candidateEvidence['payload_schema'] ?? null,
            ],
            'next_action' => $this->nextAction($status),
            'guardrails' => [
                'writes_files' => false,
                'executes_commands' => false,
                'persists_candidate' => false,
                'publishes_release' => false,
                'emits_evidence_event' => false,
                'writes_evidence_ledger' => false,
                'creates_runtime_job' => false,
                'bypasses_human_review' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $candidateEvidence
     * @return array<string,mixed>
     */
    private function evaluateEvidence(array $candidateEvidence): array
    {
        $failed = [];
        $shapeErrors = [];
        $allowedKeys = array_merge(self::REQUIRED_CANDIDATE_EVIDENCE, self::OPTIONAL_CANDIDATE_EVIDENCE);

        foreach ($candidateEvidence as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                $shapeErrors[] = [
                    'id' => 'unknown_release_evidence_candidate_key',
                    'key' => (string) $key,
                ];
            }
        }

        foreach (self::REQUIRED_CANDIDATE_EVIDENCE as $key) {
            if (! array_key_exists($key, $candidateEvidence)) {
                $shapeErrors[] = [
                    'id' => 'missing_required_release_evidence_candidate',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if (! is_bool($candidateEvidence[$key])) {
                $shapeErrors[] = [
                    'id' => 'release_evidence_candidate_must_be_boolean',
                    'key' => $key,
                ];
                $failed[] = $key;

                continue;
            }

            if ($candidateEvidence[$key] !== true) {
                $failed[] = $key;
            }
        }

        $candidateKind = $candidateEvidence['candidate_kind'] ?? null;
        if (! is_string($candidateKind) || ! in_array($candidateKind, self::ALLOWED_CANDIDATE_KINDS, true)) {
            $shapeErrors[] = [
                'id' => 'release_evidence_candidate_kind_not_allowed',
                'key' => 'candidate_kind',
            ];
        }

        $payloadSchema = $candidateEvidence['payload_schema'] ?? null;
        if (! is_string($payloadSchema) || trim($payloadSchema) === '') {
            $shapeErrors[] = [
                'id' => 'release_evidence_candidate_payload_schema_required',
                'key' => 'payload_schema',
            ];
        }

        return [
            'status' => $shapeErrors === []
                ? ($failed === [] ? 'complete' : 'incomplete')
                : 'invalid_shape',
            'allowed_candidate_kinds' => self::ALLOWED_CANDIDATE_KINDS,
            'required_keys' => self::REQUIRED_CANDIDATE_EVIDENCE,
            'passed_count' => count(self::REQUIRED_CANDIDATE_EVIDENCE) - count(array_unique($failed)),
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
        if (($handoff['status'] ?? null) !== 'ready_for_release_evidence_owner_review') {
            return 'blocked_by_release_evidence_handoff_packet';
        }

        if (($evidence['status'] ?? null) === 'invalid_shape') {
            return 'blocked_invalid_release_evidence_candidate_shape';
        }

        if (($evidence['status'] ?? null) !== 'complete') {
            return 'release_evidence_candidate_incomplete';
        }

        return 'release_evidence_candidate_ready_for_human_review';
    }

    private function nextAction(string $status): string
    {
        return match ($status) {
            'release_evidence_candidate_ready_for_human_review' => 'human_reviews_candidate_before_any_release_or_ledger_ap_executes',
            'blocked_by_release_evidence_handoff_packet' => 'repair_release_evidence_handoff_packet_before_candidate',
            'blocked_invalid_release_evidence_candidate_shape' => 'fix_release_evidence_candidate_shape_before_review',
            'release_evidence_candidate_incomplete' => 'complete_release_evidence_candidate_before_review',
            default => 'review_release_evidence_candidate_status',
        };
    }
}
