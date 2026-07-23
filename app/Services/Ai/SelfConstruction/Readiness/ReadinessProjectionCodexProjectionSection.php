<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * GOD-DEBULK extracted projection family from AtlasSelfConstructionReadinessService.
 * Pure read-only projections. The mother is passed as $parent so cross-family sibling
 * calls route back through the preserved public mother surface (ReflectionMethod /
 * method_exists probes on the mother stay intact).
 */
final class ReadinessProjectionCodexProjectionSection
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function codexLaunchPlan(array $options = []): array
    {
        $queuePayload = $this->parent->packetQueue($options);
        $gatePayload = $this->parent->multiSessionReadinessGate($options);
        $entries = (array) data_get($queuePayload, 'queue.entries', []);
        $available = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'available'));
        $launchable = array_slice($available, 0, 5);

        $sessions = array_map(function (array $entry, int $index): array {
            $slot = $index + 1;
            $actor = sprintf('codex-%d', $slot);
            $session = sprintf('self-construction-session-%d', $slot);

            return [
                'slot_id' => sprintf('CODEX-LAUNCH-SLOT-%03d', $slot),
                'state' => 'ready_to_start',
                'expected_packet_id' => data_get($entry, 'packet_id'),
                'lane' => data_get($entry, 'lane'),
                'actor' => $actor,
                'session' => $session,
                'command' => 'php artisan atlas:ai:self-construction --codex-start-packet'
                    .' --actor='.$actor
                    .' --session='.$session
                    .' --json',
                'one_line_user_prompt' => 'continua a implementação da forma mais profissional e completa possível',
                'operator_instruction' => 'Open a fresh Codex session, run the command, follow the returned contract, complete or release the claimed packet, and do not touch files outside the returned allowed scope.',
            ];
        }, $launchable, array_keys($launchable));

        $plan = [
            'plan_id' => 'CODEX-LAUNCH-PLAN-SELF-CONSTRUCTION-0001',
            'source_queue_hash' => data_get($queuePayload, 'queue_hash'),
            'source_gate_hash' => data_get($gatePayload, 'gate_hash'),
            'max_sessions' => 5,
            'launchable_count' => count($sessions),
            'available_count' => data_get($queuePayload, 'queue.available_count'),
            'claimed_count' => data_get($queuePayload, 'queue.claimed_count'),
            'completed_count' => data_get($queuePayload, 'queue.completed_count'),
            'withheld_count' => data_get($queuePayload, 'queue.withheld_count'),
            'parallel_preview_allowed' => (bool) data_get($gatePayload, 'parallel_preview_allowed'),
            'dispatch_allowed' => false,
            'claim_persisted' => false,
            'execution_allowed' => false,
            'completion_allowed' => false,
            'sessions' => $sessions,
            'operator_sequence' => [
                'run_codex_launch_plan_once',
                'open_one_fresh_codex_session_per_launch_slot',
                'paste_the_slot_command_in_each_session',
                'each_session_follows_its_returned_codex_start_contract',
                'each_session_runs_scope_validator_before_and_after_edits',
                'each_session_completes_or_releases_its_packet',
                'rerun_packet_queue_and_reservation_status_after_sessions_finish',
            ],
            'stop_conditions' => [
                'launchable_count_is_zero',
                'codex_start_packet_returns_blocked',
                'scope_validator_blocks_any_session',
                'two_sessions_receive_same_packet_id',
                'required_gate_failed',
                'hot_voice_or_kernel_scope_detected',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_launch_plan.v1',
            'status' => $sessions === [] ? 'blocked_no_launchable_sessions' : 'codex_launch_plan_ready',
            'mode' => 'read_only_codex_launch_plan',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'plan' => $plan,
            'plan_hash' => ReadinessHash::stable($plan),
            'non_execution_guarantees' => [
                'codex_launch_plan_does_not_claim_packets',
                'codex_launch_plan_does_not_start_sessions',
                'codex_launch_plan_does_not_dispatch_work',
                'codex_launch_plan_does_not_enable_execution',
            ],
            'human_summary' => $sessions === []
                ? 'Codex launch plan is blocked because no available packet remains.'
                : 'Codex launch plan is ready: start commands are generated without claiming, dispatching or executing work.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function codexExecutionStatus(array $options = []): array
    {
        $queuePayload = $this->parent->packetQueue($options);
        $reservationPayload = $this->parent->reservationStatus($options);
        $launchPlanPayload = $this->parent->codexLaunchPlan($options);
        $entries = (array) data_get($queuePayload, 'queue.entries', []);

        $claimed = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'claimed'));
        $completed = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'completed'));
        $available = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'available'));
        $blocked = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'blocked_by_dependency'));
        $withheld = array_values(array_filter($entries, fn (array $entry): bool => data_get($entry, 'queue_state') === 'withheld'));

        $claimedSessions = array_map(fn (array $entry): array => [
            'packet_id' => data_get($entry, 'packet_id'),
            'lane' => data_get($entry, 'lane'),
            'actor' => data_get($entry, 'active_reservation_actor'),
            'session' => data_get($entry, 'active_reservation_session'),
            'reservation_id' => data_get($entry, 'active_reservation_id'),
            'lease_expires_at' => data_get($entry, 'lease_expires_at'),
            'next_expected_action' => 'run_scope_validator_gates_then_complete_or_release_packet',
        ], $claimed);

        $completedPackets = array_map(fn (array $entry): array => [
            'packet_id' => data_get($entry, 'packet_id'),
            'lane' => data_get($entry, 'lane'),
            'actor' => data_get($entry, 'completion_actor'),
            'reservation_id' => data_get($entry, 'completed_reservation_id'),
            'completed_at' => data_get($entry, 'completed_at'),
        ], $completed);

        $monitor = [
            'monitor_id' => 'CODEX-EXECUTION-STATUS-SELF-CONSTRUCTION-0001',
            'source_queue_hash' => data_get($queuePayload, 'queue_hash'),
            'source_reservation_hash' => data_get($reservationPayload, 'ledger_hash'),
            'source_launch_plan_hash' => data_get($launchPlanPayload, 'plan_hash'),
            'counts' => [
                'available' => count($available),
                'claimed' => count($claimed),
                'completed' => count($completed),
                'blocked' => count($blocked),
                'withheld' => count($withheld),
                'launchable' => data_get($launchPlanPayload, 'plan.launchable_count'),
                'ledger_events' => data_get($reservationPayload, 'ledger.event_count'),
            ],
            'claimed_sessions' => $claimedSessions,
            'completed_packets' => $completedPackets,
            'next_launch_commands' => array_values(array_map(
                fn (array $session): string => (string) data_get($session, 'command'),
                (array) data_get($launchPlanPayload, 'plan.sessions', [])
            )),
            'recommended_next_action' => match (true) {
                count($claimed) > 0 => 'wait_for_active_sessions_or_review_their_final_response_contracts',
                count($available) > 0 => 'launch_available_codex_sessions',
                count($blocked) > 0 => 'review_dependency_unlock_plan',
                default => 'review_completed_packets_and_external_hot_work',
            },
            'operator_commands' => [
                'refresh_status' => 'php artisan atlas:ai:self-construction --codex-execution-status --json',
                'launch_plan' => 'php artisan atlas:ai:self-construction --codex-launch-plan --json',
                'packet_queue' => 'php artisan atlas:ai:self-construction --packet-queue --json',
                'reservation_status' => 'php artisan atlas:ai:self-construction --reservation-status --json',
            ],
            'execution_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_execution_status.v1',
            'status' => 'codex_execution_status_ready',
            'mode' => 'read_only_codex_execution_status',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'monitor' => $monitor,
            'monitor_hash' => ReadinessHash::stable($monitor),
            'non_execution_guarantees' => [
                'codex_execution_status_does_not_claim_packets',
                'codex_execution_status_does_not_complete_packets',
                'codex_execution_status_does_not_start_sessions',
                'codex_execution_status_does_not_dispatch_work',
            ],
            'human_summary' => 'Codex execution status is ready: active, completed and available packet state is visible without mutating reservations or dispatching work.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function codexMergeReadiness(array $options = []): array
    {
        $integrationPayload = $this->parent->codexIntegrationReport($options);
        $report = (array) data_get($integrationPayload, 'report', []);
        $readyPackets = (array) data_get($report, 'ready_to_review_packets', []);
        $missingPackets = (array) data_get($report, 'missing_packets', []);
        $activeSessions = (int) data_get($report, 'counts.active_sessions', 0);
        $missingEvidence = array_values(array_filter(
            $readyPackets,
            fn (array $packet): bool => ! is_string(data_get($packet, 'evidence_hash')) || data_get($packet, 'evidence_hash') === ''
        ));

        $blocking = [];
        if ($activeSessions > 0) {
            $blocking[] = [
                'id' => 'active_sessions_present',
                'severity' => 'blocking',
                'detail' => 'One or more Codex sessions are still active; merge review must wait for completion or release.',
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
            'readiness_id' => 'CODEX-MERGE-READINESS-SELF-CONSTRUCTION-0001',
            'source_integration_report_hash' => data_get($integrationPayload, 'report_hash'),
            'merge_review_status' => $mergeReviewStatus,
            'ready_packet_count' => count($readyPackets),
            'missing_packet_count' => count($missingPackets),
            'active_session_count' => $activeSessions,
            'missing_evidence_count' => count($missingEvidence),
            'blocking_count' => count($blocking),
            'blocking_failures' => $blocking,
            'ready_packets' => array_map(fn (array $packet): array => [
                'packet_id' => data_get($packet, 'packet_id'),
                'lane' => data_get($packet, 'lane'),
                'actor' => data_get($packet, 'actor'),
                'reservation_id' => data_get($packet, 'reservation_id'),
                'evidence_hash' => data_get($packet, 'evidence_hash'),
                'allowed_files' => (array) data_get($packet, 'allowed_files', []),
            ], $readyPackets),
            'required_review_gates' => [
                'php artisan atlas:ai:self-construction --codex-execution-status --json',
                'php artisan atlas:ai:self-construction --codex-integration-report --json',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'merge_boundaries' => [
                'merge_readiness_is_not_merge_approval',
                'packet_completion_is_not_code_approval',
                'human_review_or_signed_governed_receipt_required',
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
            'schema_version' => 'atlas.self_construction_codex_merge_readiness.v1',
            'status' => $mergeReviewStatus,
            'mode' => 'read_only_codex_merge_readiness',
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
                'codex_merge_readiness_does_not_claim_packets',
                'codex_merge_readiness_does_not_complete_packets',
                'codex_merge_readiness_does_not_approve_code',
                'codex_merge_readiness_does_not_merge',
                'codex_merge_readiness_does_not_dispatch_work',
            ],
            'human_summary' => $blocking === []
                ? 'Codex merge readiness is ready for human or governed receipt review. It still does not grant merge approval.'
                : 'Codex merge readiness is blocked; resolve active sessions, missing packets or missing evidence before human merge review.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function codexFinalReviewPacket(array $options = []): array
    {
        $mergePayload = $this->parent->codexMergeReadiness($options);
        $readiness = (array) data_get($mergePayload, 'readiness', []);
        $readyPackets = (array) data_get($readiness, 'ready_packets', []);
        $blocking = (array) data_get($readiness, 'blocking_failures', []);
        $reviewReady = data_get($mergePayload, 'status') === 'ready_for_human_merge_review';

        $packet = [
            'packet_id' => 'CODEX-FINAL-REVIEW-PACKET-SELF-CONSTRUCTION-0001',
            'source_merge_readiness_hash' => data_get($mergePayload, 'readiness_hash'),
            'review_status' => $reviewReady ? 'ready_for_principal_integrator_review' : 'blocked_before_final_review',
            'decision_required' => true,
            'ready_packet_count' => count($readyPackets),
            'blocking_count' => count($blocking),
            'blocking_failures' => $blocking,
            'ready_packets' => $readyPackets,
            'principal_integrator_checklist' => [
                'confirm_each_codex_final_response_names_packet_and_reservation',
                'confirm_each_packet_diff_only_touches_allowed_files',
                'confirm_evidence_hash_matches_reported_final_evidence',
                'run_required_review_gates_from_merge_readiness',
                'inspect_docs_for_contract_drift',
                'verify_hot_voice_and_kernel_scopes_were_not_modified_by_self_construction',
                'write_human_review_decision_before_merge',
            ],
            'required_review_gates' => (array) data_get($readiness, 'required_review_gates', []),
            'decision_slots' => [
                [
                    'id' => 'principal_integrator_decision',
                    'required' => true,
                    'allowed_values' => ['approve_for_merge', 'request_changes', 'reject'],
                    'default' => 'request_changes',
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
                'merge_readiness_is_not_merge_approval',
                'packet_completion_is_not_code_approval',
                'human_decision_must_be_recorded_outside_this_read_only_surface',
            ],
            'recommended_next_action' => $reviewReady
                ? 'run_review_gates_and_record_principal_integrator_decision'
                : 'resolve_merge_readiness_blockers_before_final_review',
            'execution_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_final_review_packet.v1',
            'status' => $reviewReady ? 'final_review_packet_ready' : 'blocked_before_final_review',
            'mode' => 'read_only_codex_final_review_packet',
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
                'codex_final_review_packet_does_not_claim_packets',
                'codex_final_review_packet_does_not_complete_packets',
                'codex_final_review_packet_does_not_approve_code',
                'codex_final_review_packet_does_not_merge',
                'codex_final_review_packet_does_not_dispatch_work',
            ],
            'human_summary' => $reviewReady
                ? 'Codex final review packet is ready for the principal integrator. It still does not approve, merge or dispatch work.'
                : 'Codex final review packet is blocked until merge-readiness blockers are resolved.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function codexReviewDecisionTemplate(array $options = []): array
    {
        $reviewPayload = $this->parent->codexFinalReviewPacket($options);
        $packet = (array) data_get($reviewPayload, 'packet', []);
        $reviewReady = data_get($reviewPayload, 'status') === 'final_review_packet_ready';

        $template = [
            'template_id' => 'CODEX-REVIEW-DECISION-TEMPLATE-SELF-CONSTRUCTION-0001',
            'source_final_review_packet_hash' => data_get($reviewPayload, 'packet_hash'),
            'decision_status' => $reviewReady ? 'ready_for_manual_decision' : 'blocked_before_manual_decision',
            'decision_recording_allowed' => false,
            'default_decision' => 'request_changes',
            'required_inputs' => [
                'principal_integrator_name',
                'reviewed_at',
                'selected_decision',
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
                'final_review_packet_ready',
                'all_required_review_gates_passed',
                'scope_integrity_result_is_scope_clean',
                'evidence_integrity_result_is_evidence_verified',
                'no_hot_scope_edits_from_self_construction',
                'principal_integrator_rationale_present',
            ],
            'default_safe_decision_policy' => [
                'when_any_precondition_is_missing' => 'request_changes',
                'when_scope_violation_is_found' => 'reject_or_request_changes',
                'when_evidence_is_missing' => 'request_changes',
                'when_hot_scope_is_touched' => 'reject',
            ],
            'source_review_status' => data_get($packet, 'review_status'),
            'ready_packet_count' => data_get($packet, 'ready_packet_count'),
            'blocking_count' => data_get($packet, 'blocking_count'),
            'decision_slots' => (array) data_get($packet, 'decision_slots', []),
            'checklist' => (array) data_get($packet, 'principal_integrator_checklist', []),
            'required_review_gates' => (array) data_get($packet, 'required_review_gates', []),
            'output_contract' => [
                'decision_must_be_recorded_by_future_signed_receipt_or_human_review_surface',
                'template_output_must_include_source_final_review_packet_hash',
                'template_output_must_include_gate_outputs_or_links',
                'template_output_must_include_scope_and_evidence_integrity_results',
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
            'schema_version' => 'atlas.self_construction_codex_review_decision_template.v1',
            'status' => $reviewReady ? 'review_decision_template_ready' : 'blocked_before_review_decision_template',
            'mode' => 'read_only_codex_review_decision_template',
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
                'codex_review_decision_template_does_not_claim_packets',
                'codex_review_decision_template_does_not_complete_packets',
                'codex_review_decision_template_does_not_record_decision',
                'codex_review_decision_template_does_not_approve_code',
                'codex_review_decision_template_does_not_merge',
                'codex_review_decision_template_does_not_dispatch_work',
            ],
            'human_summary' => $reviewReady
                ? 'Codex review decision template is ready for manual completion. It does not record approval or allow merge.'
                : 'Codex review decision template is blocked until the final review packet is ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function codexReviewReceiptDraft(array $options = []): array
    {
        $templatePayload = $this->parent->codexReviewDecisionTemplate($options);
        $template = (array) data_get($templatePayload, 'template', []);
        $templateReady = data_get($templatePayload, 'status') === 'review_decision_template_ready';

        $receipt = [
            'receipt_id' => 'CODEX-REVIEW-RECEIPT-DRAFT-SELF-CONSTRUCTION-0001',
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
                'principal_integrator',
                'human_owner_or_governed_receipt_authority',
            ],
            'draft_fields' => [
                'principal_integrator_name' => null,
                'reviewed_at' => null,
                'selected_decision' => data_get($template, 'default_decision', 'request_changes'),
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
                'php artisan atlas:ai:self-construction --codex-review-decision-template --json',
                'php artisan atlas:ai:self-construction --codex-final-review-packet --json',
                'php artisan atlas:ai:self-construction --codex-merge-readiness --json',
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
                ? 'principal_integrator_completes_and_signs_receipt_in_future_governed_surface'
                : 'resolve_final_review_blockers_before_receipt_draft',
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_receipt_draft.v1',
            'status' => $templateReady ? 'review_receipt_draft_ready' : 'blocked_before_review_receipt_draft',
            'mode' => 'read_only_codex_review_receipt_draft',
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
                'codex_review_receipt_draft_does_not_claim_packets',
                'codex_review_receipt_draft_does_not_complete_packets',
                'codex_review_receipt_draft_does_not_record_decision',
                'codex_review_receipt_draft_does_not_approve_code',
                'codex_review_receipt_draft_does_not_merge',
                'codex_review_receipt_draft_does_not_dispatch_work',
            ],
            'human_summary' => $templateReady
                ? 'Codex review receipt draft is ready for future signature review. It is unsigned and does not approve or merge.'
                : 'Codex review receipt draft is blocked until the decision template is ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function codexReviewSignatureRequest(array $options = []): array
    {
        $draftPayload = $this->parent->codexReviewReceiptDraft($options);
        $receipt = (array) data_get($draftPayload, 'receipt', []);
        $draftReady = data_get($draftPayload, 'status') === 'review_receipt_draft_ready';

        $signablePayload = [
            'receipt_id' => data_get($receipt, 'receipt_id'),
            'receipt_hash' => data_get($draftPayload, 'receipt_hash'),
            'source_decision_template_hash' => data_get($receipt, 'source_decision_template_hash'),
            'source_final_review_packet_hash' => data_get($receipt, 'source_final_review_packet_hash'),
            'requested_signature_type' => 'principal_integrator_explicit_review_decision',
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
            ],
        ];

        $request = [
            'request_id' => 'CODEX-REVIEW-SIGNATURE-REQUEST-SELF-CONSTRUCTION-0001',
            'status' => $draftReady ? 'pending_principal_integrator_signature' : 'blocked_before_signature_request',
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
            'schema_version' => 'atlas.self_construction_codex_review_signature_request.v1',
            'status' => $draftReady ? 'review_signature_pending' : 'blocked_before_review_signature_request',
            'mode' => 'read_only_codex_review_signature_request',
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
                'codex_review_signature_request_does_not_claim_packets',
                'codex_review_signature_request_does_not_complete_packets',
                'codex_review_signature_request_does_not_record_decision',
                'codex_review_signature_request_does_not_approve_code',
                'codex_review_signature_request_does_not_merge',
                'codex_review_signature_request_does_not_dispatch_work',
            ],
            'human_summary' => $draftReady
                ? 'Codex review signature request is ready: the signable payload is hash-bound, but no signature, approval or merge is granted.'
                : 'Codex review signature request is blocked until the review receipt draft is ready.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function codexReviewPostSignatureRunbook(array $options = []): array
    {
        $signaturePayload = $this->parent->codexReviewSignatureRequest($options);
        $signatureRequest = (array) data_get($signaturePayload, 'signature_request', []);
        $signablePayload = (array) data_get($signaturePayload, 'signable_payload', []);
        $requestReady = data_get($signaturePayload, 'status') === 'review_signature_pending';

        $steps = [
            [
                'id' => 'verify_signature_payload',
                'title' => 'Verify the signable payload hash matches the signed human/governed receipt.',
                'required_evidence' => ['signable_payload_hash', 'signed_receipt_hash'],
            ],
            [
                'id' => 'verify_human_decision',
                'title' => 'Verify selected decision, rationale, scope result and evidence result are present.',
                'required_evidence' => ['selected_decision', 'decision_rationale', 'scope_integrity_result', 'evidence_integrity_result'],
            ],
            [
                'id' => 'rerun_review_gates',
                'title' => 'Rerun required review gates before any merge decision.',
                'required_evidence' => (array) data_get($signablePayload, 'verification_commands', []),
            ],
            [
                'id' => 'prepare_explicit_merge_action',
                'title' => 'Prepare a separate explicit merge action only if decision is approve_for_merge and all gates pass.',
                'required_evidence' => ['explicit_merge_action_or_manual_merge_record'],
            ],
        ];

        $runbook = [
            'runbook_id' => 'CODEX-REVIEW-POST-SIGNATURE-RUNBOOK-SELF-CONSTRUCTION-0001',
            'status' => $requestReady ? 'waiting_for_valid_signature' : 'blocked_before_signature_request',
            'source_signature_request_hash' => data_get($signaturePayload, 'request_hash'),
            'source_signable_payload_hash' => data_get($signaturePayload, 'signable_payload_hash'),
            'signature_required' => true,
            'signature_present' => false,
            'signature_valid' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'auto_merge_allowed' => false,
            'step_count' => count($steps),
            'steps' => $steps,
            'required_before_any_merge_action' => [
                'valid_signature_against_signable_payload_hash',
                'selected_decision_is_approve_for_merge',
                'all_required_review_gates_passed_after_signature',
                'scope_integrity_result_is_scope_clean',
                'evidence_integrity_result_is_evidence_verified',
                'explicit_manual_or_governed_merge_action_created',
            ],
            'still_forbidden' => array_values(array_unique(array_merge(
                (array) data_get($signablePayload, 'still_forbidden_after_signature', []),
                [
                    'auto_merge_from_runbook',
                    'merge_without_explicit_action',
                    'hot_voice_or_kernel_scope_changes',
                ]
            ))),
            'operator_commands' => [
                'signature_request' => 'php artisan atlas:ai:self-construction --codex-review-signature-request --json',
                'receipt_draft' => 'php artisan atlas:ai:self-construction --codex-review-receipt-draft --json',
                'final_review_packet' => 'php artisan atlas:ai:self-construction --codex-final-review-packet --json',
                'focused_tests' => 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs_health' => 'php artisan atlas:engineering:knowledge docs-health --json',
                'architecture_validate' => 'php artisan atlas:ai:architecture-validate --json',
                'diff_check' => 'git diff --check',
            ],
            'non_authorizing_invariants' => [
                'post_signature_runbook_does_not_validate_signature',
                'post_signature_runbook_does_not_record_decision',
                'post_signature_runbook_does_not_grant_approval',
                'post_signature_runbook_does_not_merge',
                'post_signature_runbook_does_not_dispatch_work',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_codex_review_post_signature_runbook.v1',
            'status' => $requestReady ? 'post_signature_runbook_ready' : 'blocked_before_post_signature_runbook',
            'mode' => 'read_only_codex_review_post_signature_runbook',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'completion_persisted' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'runbook' => $runbook,
            'runbook_hash' => ReadinessHash::stable($runbook),
            'non_execution_guarantees' => [
                'codex_review_post_signature_runbook_does_not_claim_packets',
                'codex_review_post_signature_runbook_does_not_complete_packets',
                'codex_review_post_signature_runbook_does_not_validate_signature',
                'codex_review_post_signature_runbook_does_not_approve_code',
                'codex_review_post_signature_runbook_does_not_merge',
                'codex_review_post_signature_runbook_does_not_dispatch_work',
            ],
            'human_summary' => $requestReady
                ? 'Codex review post-signature runbook is ready as a conditional checklist. It does not validate signature, approve or merge.'
                : 'Codex review post-signature runbook is blocked until a review signature request is ready.',
        ];
    }
}
