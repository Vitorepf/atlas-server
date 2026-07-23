<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * GOD-DEBULK extracted projection family from AtlasSelfConstructionReadinessService.
 * Pure read-only projections. The mother is passed as $parent so cross-family sibling
 * calls route back through the preserved public mother surface (ReflectionMethod /
 * method_exists probes on the mother stay intact).
 */
final class ReadinessProjectionMiscProjectionsPart2Section
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function nextAction(array $options = []): array
    {
        $handoffPacketPayload = $this->parent->handoffPacket($options);
        $handoffPacket = (array) data_get($handoffPacketPayload, 'handoff_packet', []);

        $candidateActions = [
            [
                'id' => 'request_human_signature_review',
                'rank' => 1,
                'allowed' => true,
                'risk' => 'low',
                'action_type' => 'human_review',
                'reason' => 'The run is blocked by missing human signature and evidence, not by missing read-only preparation.',
                'command' => 'php artisan atlas:ai:self-construction --signature-request --json',
            ],
            [
                'id' => 'refresh_handoff_for_next_operator',
                'rank' => 2,
                'allowed' => true,
                'risk' => 'low',
                'action_type' => 'read_only_report',
                'reason' => 'Any new operator can resume from stable hashes, blockers and forbidden scope.',
                'command' => 'php artisan atlas:ai:self-construction --handoff-packet --json',
            ],
            [
                'id' => 'execute_scoped_patch',
                'rank' => 3,
                'allowed' => false,
                'risk' => 'medium',
                'action_type' => 'patch_execution',
                'reason' => 'Blocked until signed receipt and explicit evidence collection path exist.',
                'command' => null,
            ],
            [
                'id' => 'claim_completion',
                'rank' => 4,
                'allowed' => false,
                'risk' => 'high',
                'action_type' => 'completion_claim',
                'reason' => 'Blocked until signed execution receipt, scoped diff, gates and final evidence report exist.',
                'command' => null,
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_next_action.v1',
            'status' => 'next_action_ready',
            'mode' => 'read_only_next_action',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'handoff_hash' => data_get($handoffPacketPayload, 'handoff_hash'),
            'selected_action' => $candidateActions[0],
            'candidate_actions' => $candidateActions,
            'current_blockers' => data_get($handoffPacket, 'current_blockers'),
            'forbidden_until_signature' => [
                'execute_scoped_patch',
                'repair_after_gate_failure',
                'claim_completion',
                'promote_autonomy',
                'touch_hot_runtime_files',
            ],
            'non_execution_guarantees' => [
                'next_action_does_not_sign_receipt',
                'next_action_does_not_apply_patch',
                'next_action_does_not_mark_completion',
                'next_action_does_not_enable_execution',
            ],
            'human_summary' => 'Next action is human signature review. Patch execution and completion claims remain blocked.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function phaseLedger(array $options = []): array
    {
        $snapshot = $this->parent->snapshot($options);
        $promotionGate = $this->parent->promotionGate($options);
        $signatureRequest = $this->parent->signatureRequest($options);
        $completionReadiness = $this->parent->completionReadiness($options);
        $residualRisk = $this->parent->residualRisk($options);
        $nextAction = $this->parent->nextAction($options);

        $phases = [
            [
                'id' => 'phase_1_documentation_and_registry',
                'status' => data_get($snapshot, 'summary.missing_doc_count') === 0 ? 'complete' : 'blocked',
                'evidence' => 'required self-construction docs exist',
            ],
            [
                'id' => 'phase_2_read_only_gap_report',
                'status' => data_get($snapshot, 'status') === 'ready_for_phase_2' ? 'complete' : 'blocked',
                'evidence' => 'readiness command returns advisory snapshot',
            ],
            [
                'id' => 'phase_3_meta_sdd',
                'status' => 'complete',
                'evidence' => 'meta-sdd candidate is generated read-only',
            ],
            [
                'id' => 'phase_4_receipt_planning',
                'status' => 'complete',
                'evidence' => 'receipt preview, candidate, approval packet and draft exist',
            ],
            [
                'id' => 'phase_5_low_risk_execution',
                'status' => 'blocked_waiting_for_human_signature',
                'evidence' => 'signature request and runbook exist; execution remains disabled',
            ],
            [
                'id' => 'phase_5_completion',
                'status' => data_get($completionReadiness, 'status'),
                'evidence' => 'completion readiness requires signed execution evidence',
            ],
            [
                'id' => 'phase_6_restricted_runtime_patches',
                'status' => 'not_started',
                'evidence' => 'blocked until Phase 5 has signed execution evidence and clean residual risk',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_phase_ledger.v1',
            'status' => 'phase_ledger_ready',
            'mode' => 'read_only_phase_ledger',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'promotion_allowed' => false,
            'current_phase' => 'phase_5_low_risk_execution',
            'next_action_id' => data_get($nextAction, 'selected_action.id'),
            'phase_count' => count($phases),
            'phases' => $phases,
            'ledger_summary' => [
                'completed_count' => count(array_filter($phases, fn (array $phase): bool => $phase['status'] === 'complete')),
                'blocked_count' => count(array_filter($phases, fn (array $phase): bool => str_starts_with($phase['status'], 'blocked'))),
                'not_started_count' => count(array_filter($phases, fn (array $phase): bool => $phase['status'] === 'not_started')),
                'promotion_gate_status' => data_get($promotionGate, 'status'),
                'signature_status' => data_get($signatureRequest, 'status'),
                'completion_status' => data_get($completionReadiness, 'status'),
                'residual_risk_status' => data_get($residualRisk, 'status'),
            ],
            'hard_blocks' => [
                'missing_human_signature',
                'missing_signed_scoped_diff',
                'missing_post_execution_gate_outputs',
                'missing_final_evidence_report',
            ],
            'non_execution_guarantees' => [
                'phase_ledger_does_not_sign_receipt',
                'phase_ledger_does_not_apply_patch',
                'phase_ledger_does_not_mark_completion',
                'phase_ledger_does_not_enable_execution',
            ],
            'human_summary' => 'Phase ledger is ready: Phase 5 is prepared but blocked until human signature and execution evidence exist.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function assignmentPreview(array $options = []): array
    {
        $splitter = $this->parent->workSplitter($options);
        $packets = (array) data_get($splitter, 'split.packets', []);
        $requested = $options['packet'] ?? null;
        $selected = $requested === null
            ? collect($packets)->first(fn (array $packet): bool => data_get($packet, 'status') === 'available'
                && in_array(data_get($packet, 'collision_risk'), ['none', 'low'], true)
                && (array) data_get($packet, 'depends_on', []) === [])
            : collect($packets)->firstWhere('packet_id', $requested);

        if ($selected !== null && (
            data_get($selected, 'status') !== 'available'
            || ! in_array(data_get($selected, 'collision_risk'), ['none', 'low'], true)
            || (array) data_get($selected, 'depends_on', []) !== []
        )) {
            $selected = null;
        }

        $assignment = [
            'schema_version' => 'atlas.self_construction_assignment_preview.v1',
            'assignment_id' => 'ASSIGN-SELF-CONSTRUCTION-PREVIEW-0001',
            'status' => $selected === null ? 'blocked' : 'claim_preview_ready',
            'session_owner' => 'read_only_preview',
            'requested_packet_id' => $requested,
            'selected_packet_id' => data_get($selected, 'packet_id'),
            'claim_state' => 'preview_only_not_persisted',
            'execution_allowed' => false,
            'packet_hash' => data_get($this->parent->implementationPacket($options), 'packet_hash'),
            'split_hash' => data_get($splitter, 'split_hash'),
            'allowed_files' => (array) data_get($selected, 'allowed_files', []),
            'forbidden_files' => (array) data_get($selected, 'forbidden_files', []),
            'required_first_commands' => [
                'git status --short',
                'git diff --stat',
                'git diff --name-only',
                $requested === null
                    ? 'php artisan atlas:ai:self-construction --assignment-preview --json'
                    : 'php artisan atlas:ai:self-construction --assignment-preview --packet='.$requested.' --json',
                $requested === null
                    ? 'php artisan atlas:ai:self-construction --scope-validator --json'
                    : 'php artisan atlas:ai:self-construction --scope-validator --packet='.$requested.' --json',
            ],
            'stop_conditions' => [
                'selected_packet_missing',
                'packet_hash_changed',
                'forbidden_file_changed',
                'unknown_file_changed',
                'hot_external_file_changed',
                'required_gate_failed',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_assignment_preview.v1',
            'status' => $selected === null ? 'blocked' : 'claim_preview_ready',
            'mode' => 'read_only_assignment_preview',
            'execution_allowed' => false,
            'assignment' => $assignment,
            'assignment_hash' => ReadinessHash::stable($assignment),
            'non_execution_guarantees' => [
                'assignment_preview_does_not_persist_claim',
                'assignment_preview_does_not_apply_patch',
                'assignment_preview_does_not_sign_receipt',
                'assignment_preview_does_not_enable_execution',
            ],
            'human_summary' => $selected === null
                ? 'Assignment preview is blocked because no safe available packet matched the request.'
                : 'Assignment preview selected one safe packet for one AI session. Claim is not persisted and execution remains disabled.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function packetEvidenceReport(array $options = []): array
    {
        $runbookPayload = $this->parent->packetRunbook($options);
        $scope = $this->parent->scopeValidator($options);
        $externalBlockers = $this->parent->externalBlockers($options);
        $scopePassed = data_get($scope, 'status') === 'pass';

        $gateResults = [
            ['id' => 'runbook_ready', 'passed' => data_get($runbookPayload, 'status') === 'runbook_ready'],
            ['id' => 'scope_validator_passed', 'passed' => $scopePassed],
            ['id' => 'focused_tests_required', 'passed' => true],
            ['id' => 'docs_health_required', 'passed' => true],
            ['id' => 'architecture_validate_required', 'passed' => true],
            ['id' => 'diff_check_required', 'passed' => true],
        ];

        $evidenceResults = array_map(function (string $evidence): array {
            $present = in_array($evidence, ['selected_packet_id', 'assignment_hash'], true);

            return [
                'id' => $evidence,
                'present' => $present,
                'source' => $present ? 'runbook' : 'operator_output_required',
            ];
        }, (array) data_get($runbookPayload, 'runbook.required_evidence', []));

        $blockingReasons = [];
        if (! $scopePassed) {
            $blockingReasons[] = 'scope_validator_blocked';
        }

        foreach ($evidenceResults as $evidence) {
            if (! $evidence['present']) {
                $blockingReasons[] = 'missing_evidence:'.$evidence['id'];
            }
        }

        if ((int) data_get($externalBlockers, 'blocker_count') > 0) {
            $blockingReasons[] = 'external_hot_blockers_reported';
        }

        $report = [
            'report_id' => 'EVIDENCE-REPORT-SELF-CONSTRUCTION-PACKET-0001',
            'selected_packet_id' => data_get($runbookPayload, 'runbook.selected_packet_id'),
            'runbook_hash' => data_get($runbookPayload, 'runbook_hash'),
            'scope_validator_status' => data_get($scope, 'status'),
            'execution_allowed' => false,
            'completion_allowed' => false,
            'gate_results' => $gateResults,
            'evidence_results' => $evidenceResults,
            'blocking_reasons' => array_values(array_unique($blockingReasons)),
            'external_blockers' => data_get($externalBlockers, 'blockers'),
            'required_next_action' => $blockingReasons === []
                ? 'human_review_before_completion'
                : 'resolve_blockers_and_rerun_evidence_report',
        ];

        return [
            'schema_version' => 'atlas.self_construction_packet_evidence_report.v1',
            'status' => $blockingReasons === [] ? 'completion_review_ready' : 'blocked',
            'mode' => 'read_only_packet_evidence_report',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'report' => $report,
            'report_hash' => ReadinessHash::stable($report),
            'non_execution_guarantees' => [
                'packet_evidence_report_does_not_write_ledger',
                'packet_evidence_report_does_not_mark_completed',
                'packet_evidence_report_does_not_apply_patch',
                'packet_evidence_report_does_not_enable_execution',
            ],
            'human_summary' => $blockingReasons === []
                ? 'Packet evidence report is ready for human completion review; no completion was marked.'
                : 'Packet evidence report is blocked until scope, evidence and external blockers are resolved.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function packetCompletionGate(array $options = []): array
    {
        $evidencePayload = $this->parent->packetEvidenceReport($options);
        $report = (array) data_get($evidencePayload, 'report', []);
        $blockingReasons = (array) data_get($report, 'blocking_reasons', []);
        $cleanEvidence = data_get($evidencePayload, 'status') === 'completion_review_ready' && $blockingReasons === [];

        $gate = [
            'gate_id' => 'COMPLETION-GATE-SELF-CONSTRUCTION-PACKET-0001',
            'selected_packet_id' => data_get($report, 'selected_packet_id'),
            'evidence_report_hash' => data_get($evidencePayload, 'report_hash'),
            'status' => $cleanEvidence ? 'human_review_required' : 'blocked',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'durable_completion_written' => false,
            'decision' => $cleanEvidence ? 'request_human_review' : 'block',
            'blocking_reasons' => $blockingReasons,
            'required_next_action' => $cleanEvidence
                ? 'request_human_completion_review'
                : 'resolve_packet_evidence_blockers',
            'inspected_fields' => [
                'selected_packet_id',
                'evidence_report_hash',
                'scope_validator_status',
                'blocking_reasons',
                'external_blockers',
                'gate_results',
                'evidence_results',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_packet_completion_gate.v1',
            'status' => data_get($gate, 'status'),
            'mode' => 'read_only_packet_completion_gate',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'gate' => $gate,
            'gate_hash' => ReadinessHash::stable($gate),
            'non_execution_guarantees' => [
                'packet_completion_gate_does_not_write_ledger',
                'packet_completion_gate_does_not_mark_completed',
                'packet_completion_gate_does_not_apply_patch',
                'packet_completion_gate_does_not_enable_execution',
            ],
            'human_summary' => $cleanEvidence
                ? 'Packet completion gate requires human review before any durable completion.'
                : 'Packet completion gate blocks completion because evidence, scope or external blockers are unresolved.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function reservationLedgerPreview(array $options = []): array
    {
        $assignmentPayload = $this->parent->assignmentPreview($options);
        $assignment = (array) data_get($assignmentPayload, 'assignment', []);
        $completionGatePayload = $this->parent->packetCompletionGate($options);
        $assignmentReady = data_get($assignmentPayload, 'status') === 'claim_preview_ready';

        $reservation = [
            'ledger_id' => 'RESERVATION-LEDGER-PREVIEW-SELF-CONSTRUCTION-0001',
            'reservation_id' => 'RESERVATION-PREVIEW-SELF-CONSTRUCTION-0001',
            'selected_packet_id' => data_get($assignment, 'selected_packet_id'),
            'assignment_id' => data_get($assignment, 'assignment_id'),
            'session_owner' => data_get($assignment, 'session_owner', 'read_only_preview'),
            'claim_state' => 'preview_only_not_persisted',
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'execution_allowed' => false,
            'packet_hash' => data_get($assignment, 'packet_hash'),
            'split_hash' => data_get($assignment, 'split_hash'),
            'assignment_hash' => data_get($assignmentPayload, 'assignment_hash'),
            'completion_gate_hash' => data_get($completionGatePayload, 'gate_hash'),
            'lease_expires_at' => null,
            'allowed_files' => (array) data_get($assignment, 'allowed_files', []),
            'forbidden_files' => (array) data_get($assignment, 'forbidden_files', []),
            'collision_policy' => [
                'one_active_reservation_per_packet',
                'one_active_reservation_per_session',
                'block_when_allowed_files_overlap_active_reservation',
                'block_when_hot_external_scope_is_present',
            ],
            'stale_policy' => [
                'block_when_packet_hash_changes',
                'block_when_split_hash_changes',
                'block_when_assignment_hash_changes',
                'require_new_preview_before_future_persistence',
            ],
            'stop_conditions' => (array) data_get($assignment, 'stop_conditions', []),
            'future_persistence_requires' => [
                'approved_reservation_ledger_ap',
                'append_only_storage',
                'atomic_claim_lock',
                'lease_expiry_and_release_policy',
                'scope_overlap_detector',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_reservation_ledger_preview.v1',
            'status' => $assignmentReady ? 'reservation_preview_ready' : 'blocked_by_assignment',
            'mode' => 'read_only_reservation_ledger_preview',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'ledger_write_allowed' => false,
            'reservation_persisted' => false,
            'reservation' => $reservation,
            'reservation_hash' => ReadinessHash::stable($reservation),
            'non_execution_guarantees' => [
                'reservation_ledger_preview_does_not_persist_claim',
                'reservation_ledger_preview_does_not_write_ledger',
                'reservation_ledger_preview_does_not_apply_patch',
                'reservation_ledger_preview_does_not_enable_execution',
            ],
            'human_summary' => $assignmentReady
                ? 'Reservation ledger preview is ready: future packet ownership is modeled, but no claim or ledger row was written.'
                : 'Reservation ledger preview is blocked because no safe assignment preview exists.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentMergeReadiness(array $options = []): array
    {
        $integrationPayload = $this->parent->agentIntegrationReport($options);
        $report = (array) data_get($integrationPayload, 'report', []);
        $readyPackets = (array) data_get($report, 'ready_to_review_packets', []);
        $missingPackets = (array) data_get($report, 'missing_packets', []);
        $activeAgents = (int) data_get($report, 'counts.active_agents', 0);
        $missingEvidence = array_values(array_filter(
            $readyPackets,
            fn (array $packet): bool => ! is_string(data_get($packet, 'evidence_hash')) || data_get($packet, 'evidence_hash') === ''
        ));

        $blocking = [];
        if ($activeAgents > 0) {
            $blocking[] = [
                'id' => 'active_agents_present',
                'severity' => 'blocking',
                'detail' => 'One or more Forge Workspace agents are still active; merge review must wait for completion or release.',
            ];
        }
        if ($missingPackets !== []) {
            $blocking[] = [
                'id' => 'packets_not_completed',
                'severity' => 'blocking',
                'detail' => 'All assignable Self-Construction packets must be completed before full merge review readiness.',
                'packet_count' => count($missingPackets),
            ];
        }
        if ($missingEvidence !== []) {
            $blocking[] = [
                'id' => 'completion_evidence_hash_missing',
                'severity' => 'blocking',
                'detail' => 'Each completed packet must include a completion evidence hash before merge review.',
                'packet_ids' => array_values(array_map(fn (array $packet): mixed => data_get($packet, 'packet_id'), $missingEvidence)),
            ];
        }

        $mergeReviewStatus = $blocking === []
            ? 'ready_for_human_merge_review'
            : 'blocked_for_merge_review';

        $readiness = [
            'readiness_id' => 'AGENT-MERGE-READINESS-SELF-CONSTRUCTION-0001',
            'workspace' => [
                'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
                'canonical_name' => 'Obras Shared Workspace',
                'specialization' => 'Forge Workspace',
                'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
            ],
            'source_integration_report_hash' => data_get($integrationPayload, 'report_hash'),
            'merge_review_status' => $mergeReviewStatus,
            'ready_packet_count' => count($readyPackets),
            'missing_packet_count' => count($missingPackets),
            'active_agent_count' => $activeAgents,
            'missing_evidence_count' => count($missingEvidence),
            'blocking_count' => count($blocking),
            'blocking_failures' => $blocking,
            'ready_packets' => array_map(fn (array $packet): array => [
                'packet_id' => data_get($packet, 'packet_id'),
                'lane' => data_get($packet, 'lane'),
                'actor' => data_get($packet, 'actor'),
                'provider' => data_get($packet, 'provider'),
                'role' => data_get($packet, 'role'),
                'reservation_id' => data_get($packet, 'reservation_id'),
                'evidence_hash' => data_get($packet, 'evidence_hash'),
                'allowed_files' => (array) data_get($packet, 'allowed_files', []),
            ], $readyPackets),
            'required_review_gates' => [
                'php artisan atlas:ai:self-construction --agent-execution-status --json',
                'php artisan atlas:ai:self-construction --agent-integration-report --json',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'merge_boundaries' => [
                'merge_readiness_is_not_merge_approval',
                'packet_completion_is_not_code_approval',
                'human_review_or_signed_governed_receipt_required',
                'workspace_artifacts_must_be_reviewed_before_merge',
                'hot_voice_and_kernel_scopes_remain_out_of_scope',
            ],
            'recommended_next_action' => $blocking === []
                ? 'perform_human_or_governed_receipt_review_before_merge'
                : 'resolve_blocking_failures_before_merge_review',
            'execution_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_merge_readiness.v1',
            'status' => $mergeReviewStatus,
            'mode' => 'read_only_provider_neutral_agent_merge_readiness',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'readiness' => $readiness,
            'readiness_hash' => ReadinessHash::stable($readiness),
            'non_execution_guarantees' => [
                'agent_merge_readiness_does_not_claim_packets',
                'agent_merge_readiness_does_not_complete_packets',
                'agent_merge_readiness_does_not_approve_code',
                'agent_merge_readiness_does_not_merge',
                'agent_merge_readiness_does_not_dispatch_work',
            ],
            'human_summary' => $blocking === []
                ? 'Agent merge readiness is ready for human or governed receipt review. It still does not grant merge approval.'
                : 'Agent merge readiness is blocked; resolve active agents, missing packets or missing evidence before human merge review.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentFinalReviewPacket(array $options = []): array
    {
        $mergePayload = $this->parent->agentMergeReadiness($options);
        $readiness = (array) data_get($mergePayload, 'readiness', []);
        $readyPackets = (array) data_get($readiness, 'ready_packets', []);
        $blocking = (array) data_get($readiness, 'blocking_failures', []);
        $reviewReady = data_get($mergePayload, 'status') === 'ready_for_human_merge_review';

        $packet = [
            'packet_id' => 'AGENT-FINAL-REVIEW-PACKET-SELF-CONSTRUCTION-0001',
            'workspace' => [
                'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
                'canonical_name' => 'Obras Shared Workspace',
                'specialization' => 'Forge Workspace',
                'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
            ],
            'source_merge_readiness_hash' => data_get($mergePayload, 'readiness_hash'),
            'review_status' => $reviewReady ? 'ready_for_forge_workspace_integrator_review' : 'blocked_before_final_review',
            'decision_required' => true,
            'ready_packet_count' => count($readyPackets),
            'blocking_count' => count($blocking),
            'blocking_failures' => $blocking,
            'ready_packets' => $readyPackets,
            'forge_workspace_integrator_checklist' => [
                'confirm_each_agent_final_response_names_workspace_packet_and_reservation',
                'confirm_each_packet_diff_only_touches_allowed_files',
                'confirm_workspace_artifacts_were_returned_for_each_packet',
                'confirm_evidence_hash_matches_reported_final_evidence',
                'run_required_review_gates_from_agent_merge_readiness',
                'inspect_docs_for_contract_drift',
                'verify_hot_voice_and_kernel_scopes_were_not_modified_by_self_construction',
                'write_human_or_governed_review_decision_before_merge',
            ],
            'required_review_gates' => (array) data_get($readiness, 'required_review_gates', []),
            'decision_slots' => [
                [
                    'id' => 'forge_workspace_integrator_decision',
                    'required' => true,
                    'allowed_values' => ['approve_for_merge', 'request_changes', 'reject'],
                    'default' => 'request_changes',
                ],
                [
                    'id' => 'workspace_artifact_integrity_decision',
                    'required' => true,
                    'allowed_values' => ['artifacts_verified', 'artifacts_missing_or_inconsistent'],
                    'default' => 'artifacts_missing_or_inconsistent',
                ],
                [
                    'id' => 'scope_integrity_decision',
                    'required' => true,
                    'allowed_values' => ['scope_clean', 'scope_violation_found'],
                    'default' => 'scope_violation_found',
                ],
                [
                    'id' => 'evidence_integrity_decision',
                    'required' => true,
                    'allowed_values' => ['evidence_verified', 'evidence_missing_or_inconsistent'],
                    'default' => 'evidence_missing_or_inconsistent',
                ],
            ],
            'review_boundaries' => [
                'final_review_packet_is_not_approval',
                'final_review_packet_is_not_merge_authority',
                'agent_merge_readiness_is_not_merge_approval',
                'packet_completion_is_not_code_approval',
                'workspace_artifacts_must_be_reviewed_before_merge',
                'human_decision_must_be_recorded_outside_this_read_only_surface',
            ],
            'recommended_next_action' => $reviewReady
                ? 'run_review_gates_and_record_forge_workspace_integrator_decision'
                : 'resolve_agent_merge_readiness_blockers_before_final_review',
            'execution_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_final_review_packet.v1',
            'status' => $reviewReady ? 'final_review_packet_ready' : 'blocked_before_final_review',
            'mode' => 'read_only_provider_neutral_agent_final_review_packet',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'packet' => $packet,
            'packet_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'agent_final_review_packet_does_not_claim_packets',
                'agent_final_review_packet_does_not_complete_packets',
                'agent_final_review_packet_does_not_approve_code',
                'agent_final_review_packet_does_not_merge',
                'agent_final_review_packet_does_not_dispatch_work',
            ],
            'human_summary' => $reviewReady
                ? 'Agent final review packet is ready for the Forge Workspace integrator. It still does not approve, merge or dispatch work.'
                : 'Agent final review packet is blocked until agent merge-readiness blockers are resolved.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentReviewDecisionTemplate(array $options = []): array
    {
        $reviewPayload = $this->parent->agentFinalReviewPacket($options);
        $packet = (array) data_get($reviewPayload, 'packet', []);
        $reviewReady = data_get($reviewPayload, 'status') === 'final_review_packet_ready';

        $template = [
            'template_id' => 'AGENT-REVIEW-DECISION-TEMPLATE-SELF-CONSTRUCTION-0001',
            'workspace' => [
                'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
                'canonical_name' => 'Obras Shared Workspace',
                'specialization' => 'Forge Workspace',
                'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
            ],
            'source_final_review_packet_hash' => data_get($reviewPayload, 'packet_hash'),
            'decision_status' => $reviewReady ? 'ready_for_forge_workspace_integrator_decision' : 'blocked_before_review_decision_template',
            'decision_recording_allowed' => false,
            'default_decision' => 'request_changes',
            'required_inputs' => [
                'forge_workspace_integrator_name',
                'reviewed_at',
                'selected_decision',
                'workspace_artifact_integrity_result',
                'scope_integrity_result',
                'evidence_integrity_result',
                'gates_run_with_outputs',
                'files_reviewed',
                'decision_rationale',
                'remaining_risks',
            ],
            'allowed_decisions' => [
                'approve_for_merge',
                'request_changes',
                'reject',
            ],
            'approval_preconditions' => [
                'agent_final_review_packet_ready',
                'all_required_review_gates_passed',
                'workspace_artifact_integrity_result_is_artifacts_verified',
                'scope_integrity_result_is_scope_clean',
                'evidence_integrity_result_is_evidence_verified',
                'no_hot_scope_edits_from_self_construction',
                'forge_workspace_integrator_rationale_present',
            ],
            'default_safe_decision_policy' => [
                'when_any_precondition_is_missing' => 'request_changes',
                'when_workspace_artifacts_are_missing' => 'request_changes',
                'when_scope_violation_is_found' => 'reject_or_request_changes',
                'when_evidence_is_missing' => 'request_changes',
                'when_hot_scope_is_touched' => 'reject',
            ],
            'source_review_status' => data_get($packet, 'review_status'),
            'ready_packet_count' => data_get($packet, 'ready_packet_count'),
            'blocking_count' => data_get($packet, 'blocking_count'),
            'decision_slots' => (array) data_get($packet, 'decision_slots', []),
            'checklist' => (array) data_get($packet, 'forge_workspace_integrator_checklist', []),
            'required_review_gates' => (array) data_get($packet, 'required_review_gates', []),
            'output_contract' => [
                'decision_must_be_recorded_by_future_signed_receipt_or_human_review_surface',
                'template_output_must_include_source_final_review_packet_hash',
                'template_output_must_include_gate_outputs_or_links',
                'template_output_must_include_workspace_artifact_scope_and_evidence_integrity_results',
            ],
            'boundaries' => [
                'decision_template_does_not_record_decision',
                'decision_template_does_not_grant_approval',
                'decision_template_does_not_merge',
                'decision_template_does_not_dispatch_work',
            ],
            'execution_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_review_decision_template.v1',
            'status' => $reviewReady ? 'review_decision_template_ready' : 'blocked_before_review_decision_template',
            'mode' => 'read_only_provider_neutral_agent_review_decision_template',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'template' => $template,
            'template_hash' => ReadinessHash::stable($template),
            'non_execution_guarantees' => [
                'agent_review_decision_template_does_not_claim_packets',
                'agent_review_decision_template_does_not_complete_packets',
                'agent_review_decision_template_does_not_record_decision',
                'agent_review_decision_template_does_not_approve_code',
                'agent_review_decision_template_does_not_merge',
                'agent_review_decision_template_does_not_dispatch_work',
            ],
            'human_summary' => $reviewReady
                ? 'Agent review decision template is ready for manual completion. It does not record approval or allow merge.'
                : 'Agent review decision template is blocked until the agent final review packet is ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentReviewReceiptDraft(array $options = []): array
    {
        $templatePayload = $this->parent->agentReviewDecisionTemplate($options);
        $template = (array) data_get($templatePayload, 'template', []);
        $templateReady = data_get($templatePayload, 'status') === 'review_decision_template_ready';

        $receipt = [
            'receipt_id' => 'AGENT-REVIEW-RECEIPT-DRAFT-SELF-CONSTRUCTION-0001',
            'workspace' => [
                'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
                'canonical_name' => 'Obras Shared Workspace',
                'specialization' => 'Forge Workspace',
                'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
            ],
            'status' => $templateReady ? 'draft_ready_for_signature_review' : 'blocked_before_receipt_draft',
            'source_decision_template_hash' => data_get($templatePayload, 'template_hash'),
            'source_final_review_packet_hash' => data_get($template, 'source_final_review_packet_hash'),
            'signature_required' => true,
            'signature_valid' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'default_decision' => data_get($template, 'default_decision', 'request_changes'),
            'signer_roles' => [
                'forge_workspace_integrator',
                'human_owner_or_governed_receipt_authority',
            ],
            'draft_fields' => [
                'forge_workspace_integrator_name' => null,
                'reviewed_at' => null,
                'selected_decision' => data_get($template, 'default_decision', 'request_changes'),
                'workspace_artifact_integrity_result' => null,
                'scope_integrity_result' => null,
                'evidence_integrity_result' => null,
                'gates_run_with_outputs' => [],
                'files_reviewed' => [],
                'decision_rationale' => null,
                'remaining_risks' => [],
                'source_decision_template_hash' => data_get($templatePayload, 'template_hash'),
            ],
            'required_inputs' => (array) data_get($template, 'required_inputs', []),
            'approval_preconditions' => (array) data_get($template, 'approval_preconditions', []),
            'safe_decision_policy' => (array) data_get($template, 'default_safe_decision_policy', []),
            'verification_commands' => [
                'php artisan atlas:ai:self-construction --agent-review-decision-template --json',
                'php artisan atlas:ai:self-construction --agent-final-review-packet --json',
                'php artisan atlas:ai:self-construction --agent-merge-readiness --json',
                'php artisan atlas:ai:self-construction --agent-integration-report --json',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'non_authorizing_invariants' => [
                'receipt_draft_is_unsigned',
                'receipt_draft_does_not_record_decision',
                'receipt_draft_does_not_grant_approval',
                'receipt_draft_does_not_allow_merge',
                'receipt_draft_does_not_dispatch_work',
            ],
            'next_required_action' => $templateReady
                ? 'forge_workspace_integrator_completes_and_signs_receipt_in_future_governed_surface'
                : 'resolve_agent_final_review_blockers_before_receipt_draft',
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_review_receipt_draft.v1',
            'status' => $templateReady ? 'review_receipt_draft_ready' : 'blocked_before_review_receipt_draft',
            'mode' => 'read_only_provider_neutral_agent_review_receipt_draft',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'decision_recorded' => false,
            'receipt' => $receipt,
            'receipt_hash' => ReadinessHash::stable($receipt),
            'non_execution_guarantees' => [
                'agent_review_receipt_draft_does_not_claim_packets',
                'agent_review_receipt_draft_does_not_complete_packets',
                'agent_review_receipt_draft_does_not_record_decision',
                'agent_review_receipt_draft_does_not_approve_code',
                'agent_review_receipt_draft_does_not_merge',
                'agent_review_receipt_draft_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Agent review receipt draft is ready for future signature review. It is unsigned and does not approve or merge.'
                : 'Agent review receipt draft is blocked until the decision template is ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentReviewSignatureRequest(array $options = []): array
    {
        $draftPayload = $this->parent->agentReviewReceiptDraft($options);
        $receipt = (array) data_get($draftPayload, 'receipt', []);
        $draftReady = data_get($draftPayload, 'status') === 'review_receipt_draft_ready';

        $signablePayload = [
            'receipt_id' => data_get($receipt, 'receipt_id'),
            'receipt_hash' => data_get($draftPayload, 'receipt_hash'),
            'workspace_id' => data_get($receipt, 'workspace.workspace_id'),
            'workspace_canonical_name' => data_get($receipt, 'workspace.canonical_name'),
            'source_decision_template_hash' => data_get($receipt, 'source_decision_template_hash'),
            'source_final_review_packet_hash' => data_get($receipt, 'source_final_review_packet_hash'),
            'requested_signature_type' => 'forge_workspace_integrator_explicit_review_decision',
            'allowed_decisions' => [
                'approve_for_merge',
                'request_changes',
                'reject',
            ],
            'required_signer_roles' => (array) data_get($receipt, 'signer_roles', []),
            'required_inputs' => (array) data_get($receipt, 'required_inputs', []),
            'approval_preconditions' => (array) data_get($receipt, 'approval_preconditions', []),
            'verification_commands' => (array) data_get($receipt, 'verification_commands', []),
            'still_forbidden_after_signature' => [
                'auto_merge_without_explicit_human_merge_action',
                'dispatch_work_from_signature_request',
                'touch_hot_voice_or_kernel_scope',
                'bypass_required_review_gates',
                'ignore_workspace_artifact_review',
            ],
        ];

        $request = [
            'request_id' => 'AGENT-REVIEW-SIGNATURE-REQUEST-SELF-CONSTRUCTION-0001',
            'workspace' => [
                'workspace_id' => 'FORGE-WORKSPACE-ATLAS-SELF-CONSTRUCTION-0001',
                'canonical_name' => 'Obras Shared Workspace',
                'specialization' => 'Forge Workspace',
                'obra_id' => 'OBRA-ATLAS-SELF-CONSTRUCTION-OS',
            ],
            'status' => $draftReady ? 'pending_forge_workspace_integrator_signature' : 'blocked_before_signature_request',
            'source_receipt_draft_hash' => data_get($draftPayload, 'receipt_hash'),
            'signable_payload_hash' => ReadinessHash::stable($signablePayload),
            'signature_present' => false,
            'signature_valid' => false,
            'decision_recorded' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'required_signer_roles' => (array) data_get($receipt, 'signer_roles', []),
            'signable_payload' => $signablePayload,
            'operator_instructions' => [
                'review_signable_payload_hash_before_signing',
                'fill_required_inputs_in_a_future_governed_surface',
                'attach_gate_outputs_or_links',
                'record_explicit_human_decision_separately',
                'do_not_treat_this_request_as_signature',
            ],
            'non_authorizing_invariants' => [
                'signature_request_is_not_signature',
                'signature_request_does_not_record_decision',
                'signature_request_does_not_grant_approval',
                'signature_request_does_not_allow_merge',
                'signature_request_does_not_dispatch_work',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_review_signature_request.v1',
            'status' => $draftReady ? 'review_signature_pending' : 'blocked_before_review_signature_request',
            'mode' => 'read_only_provider_neutral_agent_review_signature_request',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'decision_recorded' => false,
            'signature_request' => $request,
            'signable_payload' => $signablePayload,
            'signable_payload_hash' => ReadinessHash::stable($signablePayload),
            'request_hash' => ReadinessHash::stable($request),
            'non_execution_guarantees' => [
                'agent_review_signature_request_does_not_claim_packets',
                'agent_review_signature_request_does_not_complete_packets',
                'agent_review_signature_request_does_not_record_decision',
                'agent_review_signature_request_does_not_approve_code',
                'agent_review_signature_request_does_not_merge',
                'agent_review_signature_request_does_not_dispatch_work',
            ],
            'human_summary' => $draftReady
                ? 'Agent review signature request is ready: the signable payload is hash-bound, but no signature, approval or merge is granted.'
                : 'Agent review signature request is blocked until the review receipt draft is ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function dependencyUnlockPlan(array $options = []): array
    {
        $queuePayload = $this->parent->packetQueue($options);
        $entries = (array) data_get($queuePayload, 'queue.entries', []);
        $availableIds = array_values(array_map(
            fn (array $entry): string => (string) data_get($entry, 'packet_id'),
            array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'available'),
        ));
        $blockedEntries = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'blocked_by_dependency'));
        $withheldEntries = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'withheld'));

        $unlockEdges = [];
        $blockedPackets = array_map(function (array $entry) use (&$unlockEdges, $availableIds): array {
            $dependsOn = (array) data_get($entry, 'depends_on', []);
            foreach ($dependsOn as $dependency) {
                $unlockEdges[] = [
                    'dependency_packet_id' => $dependency,
                    'unlocks_packet_id' => data_get($entry, 'packet_id'),
                    'dependency_currently_available' => in_array($dependency, $availableIds, true),
                    'durable_completion_required' => true,
                ];
            }

            return [
                'packet_id' => data_get($entry, 'packet_id'),
                'lane' => data_get($entry, 'lane'),
                'depends_on' => $dependsOn,
                'dependencies_currently_available' => array_values(array_intersect($dependsOn, $availableIds)),
                'would_become_assignable_after' => $dependsOn,
                'queue_state' => data_get($entry, 'queue_state'),
            ];
        }, $blockedEntries);

        $plan = [
            'plan_id' => 'DEPENDENCY-UNLOCK-PLAN-SELF-CONSTRUCTION-0001',
            'source_queue_hash' => data_get($queuePayload, 'queue_hash'),
            'available_packet_ids' => $availableIds,
            'blocked_packet_count' => count($blockedPackets),
            'unlock_edge_count' => count($unlockEdges),
            'withheld_packet_count' => count($withheldEntries),
            'blocked_packets' => $blockedPackets,
            'unlock_edges' => $unlockEdges,
            'withheld_packets' => array_map(fn (array $entry): array => [
                'packet_id' => data_get($entry, 'packet_id'),
                'reason' => 'Hot external work remains withheld and cannot be unlocked by cold-lane completion.',
                'forbidden_files' => data_get($entry, 'forbidden_files'),
            ], $withheldEntries),
            'state_mutation_allowed' => false,
            'execution_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_dependency_unlock_plan.v1',
            'status' => 'dependency_unlock_plan_ready',
            'mode' => 'read_only_dependency_unlock_plan',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'state_mutation_allowed' => false,
            'dispatch_allowed' => false,
            'plan' => $plan,
            'plan_hash' => ReadinessHash::stable($plan),
            'non_execution_guarantees' => [
                'dependency_unlock_plan_does_not_mutate_queue',
                'dependency_unlock_plan_does_not_persist_completion',
                'dependency_unlock_plan_does_not_dispatch_work',
                'dependency_unlock_plan_does_not_enable_execution',
            ],
            'human_summary' => 'Dependency unlock plan is ready: blocked packets and unlock edges are visible without queue mutation, completion persistence or dispatch.',
        ];
    }
}
