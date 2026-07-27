<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

use Closure;

/**
 * Family 3 — Durable Reservation.
 * Bodies extracted from pre-split mother (Obra 3 SC-02). Mother helpers via __call.
 */
final class ReadinessProjectionDurableReservationSection
{
    private ?AtlasSelfConstructionReadinessService $mother = null;

    /**
     * @param  Closure(array<string,mixed>): string  $stableHash
     */
    public function __construct(
        private readonly Closure $stableHash,
    ) {}

    public function setMother(AtlasSelfConstructionReadinessService $mother): self
    {
        $this->mother = $mother;

        return $this;
    }

    public function __call(string $name, array $arguments): mixed
    {
        if ($this->mother === null) {
            throw new \RuntimeException("ReadinessProjectionDurableReservationSection mother not bound for {$name}.");
        }

        $method = new \ReflectionMethod($this->mother, $name);

        return $method->invokeArgs($this->mother, $arguments);
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationLedgerImplementationPlan(array $options = []): array
    {
        $reservationPayload = $this->reservationLedgerPreview($options);
        $completionGatePayload = $this->packetCompletionGate($options);
        $scopePayload = $this->scopeValidator($options);

        $storageObjects = [
            [
                'name' => 'atlas_self_construction_reservations',
                'type' => 'current_state_projection',
                'purpose' => 'Track the active reservation state for each packet and owner.',
                'required_fields' => [
                    'reservation_id',
                    'packet_id',
                    'owner_id',
                    'state',
                    'lease_expires_at',
                    'packet_hash',
                    'split_hash',
                    'allowed_files_hash',
                    'forbidden_files_hash',
                    'last_event_hash',
                ],
            ],
            [
                'name' => 'atlas_self_construction_reservation_events',
                'type' => 'append_only_event_ledger',
                'purpose' => 'Record claim, renewal, release, expiry, block and completion events.',
                'required_fields' => [
                    'event_id',
                    'reservation_id',
                    'event_type',
                    'owner_id',
                    'payload_hash',
                    'previous_event_hash',
                    'created_at',
                ],
            ],
            [
                'name' => 'atlas_self_construction_packet_snapshots',
                'type' => 'immutable_packet_snapshot',
                'purpose' => 'Preserve packet hash, split hash, scope and evidence inputs at claim time.',
                'required_fields' => [
                    'snapshot_id',
                    'packet_id',
                    'packet_hash',
                    'split_hash',
                    'allowed_files_json',
                    'forbidden_files_json',
                    'scope_validator_hash',
                    'created_at',
                ],
            ],
        ];

        $plan = [
            'plan_id' => 'DURABLE-RESERVATION-LEDGER-IMPLEMENTATION-PLAN-0001',
            'source_reservation_preview_hash' => data_get($reservationPayload, 'reservation_hash'),
            'source_completion_gate_hash' => data_get($completionGatePayload, 'gate_hash'),
            'source_scope_validator_hash' => data_get($scopePayload, 'validator_hash'),
            'blocker_removed_when_complete' => 'durable_reservation_ledger_missing',
            'storage_object_count' => count($storageObjects),
            'storage_objects' => $storageObjects,
            'claim_states' => [
                'available',
                'claimed',
                'renewed',
                'released',
                'expired',
                'completed',
                'blocked',
            ],
            'atomic_claim_sequence' => [
                'recompute_packet_queue_and_hashes',
                'reject_stale_packet_or_split_hash',
                'reject_active_reservation_for_same_packet',
                'reject_allowed_file_overlap_with_active_reservations',
                'reject_hot_forbidden_scope',
                'append_claim_attempt_event',
                'write_current_projection_under_lock',
                'emit_reservation_hash_and_claim_receipt',
            ],
            'required_invariants' => [
                'one_active_reservation_per_packet',
                'one_active_reservation_per_owner_session',
                'no_allowed_file_overlap_across_active_reservations',
                'no_hot_forbidden_scope_in_claim',
                'completion_requires_active_owned_claim',
                'append_only_events_are_hash_chained',
            ],
            'required_tests' => [
                'blocks_duplicate_packet_claim',
                'blocks_overlapping_allowed_files',
                'blocks_hot_scope_claim',
                'blocks_stale_packet_hash',
                'blocks_completion_after_expiry',
                'allows_release_then_reclaim',
                'preserves_append_only_event_hash_chain',
            ],
            'implementation_order' => [
                'document_storage_ap_and_migration_scope',
                'add_read_model_and_event_schema_tests',
                'implement_repository_with_transactional_claim_lock',
                'wire_scope_collision_detector',
                'wire_lease_expiry_and_release_policy',
                'update_multi_session_readiness_gate_to_consume_durable_projection',
                'keep_dispatch_disabled_until_separate_approval',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'promotion_requirements' => [
                'approved_storage_ap',
                'passing_duplicate_claim_tests',
                'passing_scope_collision_tests',
                'passing_expiry_and_release_tests',
                'architecture_validate_ok',
                'docs_health_ok',
            ],
            'execution_allowed' => false,
            'ledger_write_allowed' => false,
            'migration_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_ledger_implementation_plan.v1',
            'status' => 'durable_reservation_ledger_plan_ready',
            'mode' => 'read_only_durable_reservation_ledger_implementation_plan',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'ledger_write_allowed' => false,
            'migration_write_allowed' => false,
            'dispatch_allowed' => false,
            'plan' => $plan,
            'plan_hash' => ($this->stableHash)($plan),
            'non_execution_guarantees' => [
                'durable_reservation_ledger_plan_does_not_create_migrations',
                'durable_reservation_ledger_plan_does_not_write_ledger',
                'durable_reservation_ledger_plan_does_not_persist_claim',
                'durable_reservation_ledger_plan_does_not_dispatch_work',
                'durable_reservation_ledger_plan_does_not_enable_execution',
            ],
            'human_summary' => 'Durable reservation ledger implementation plan is ready: the missing multi-session ledger is specified, but storage writes, migrations, claims and dispatch remain disabled.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationApCandidate(array $options = []): array
    {
        $planPayload = $this->durableReservationLedgerImplementationPlan($options);
        $readinessGatePayload = $this->multiSessionReadinessGate($options);

        $packets = [
            [
                'packet_id' => 'DR-AP-STORAGE-0001',
                'title' => 'Define durable reservation storage',
                'objective' => 'Create approved storage contracts for reservations, events and packet snapshots.',
                'allowed_scope' => [
                    'future_database_migrations_after_approval',
                    'future_reservation_models_after_approval',
                    'self_construction_docs',
                ],
                'required_tests' => [
                    'storage_schema_contains_required_hash_fields',
                    'events_are_append_only',
                ],
            ],
            [
                'packet_id' => 'DR-AP-REPOSITORY-0002',
                'title' => 'Implement transactional claim repository',
                'objective' => 'Claim, renew, release and expire reservations with atomic locks.',
                'allowed_scope' => [
                    'future_self_construction_reservation_repository',
                    'future_repository_tests',
                ],
                'required_tests' => [
                    'blocks_duplicate_packet_claim',
                    'allows_release_then_reclaim',
                    'blocks_completion_after_expiry',
                ],
            ],
            [
                'packet_id' => 'DR-AP-COLLISION-0003',
                'title' => 'Implement scope collision policy',
                'objective' => 'Reject overlapping allowed files and hot forbidden scopes before claim persistence.',
                'allowed_scope' => [
                    'future_scope_collision_detector',
                    'future_collision_policy_tests',
                ],
                'required_tests' => [
                    'blocks_overlapping_allowed_files',
                    'blocks_hot_scope_claim',
                    'blocks_stale_packet_hash',
                ],
            ],
            [
                'packet_id' => 'DR-AP-READINESS-0004',
                'title' => 'Integrate durable state with readiness gates',
                'objective' => 'Remove durable_reservation_ledger_missing only after durable projection is proven.',
                'allowed_scope' => [
                    'AtlasSelfConstructionReadinessService',
                    'AtlasAiSelfConstructionCommandTest',
                    'self_construction_docs',
                ],
                'required_tests' => [
                    'multi_session_gate_reads_durable_reservation_projection',
                    'dispatch_remains_disabled_after_ledger_activation',
                ],
            ],
        ];

        $candidate = [
            'ap_id' => 'AP-CANDIDATE-DURABLE-RESERVATION-LEDGER-0001',
            'title' => 'Implement durable Self-Construction reservation ledger',
            'source_plan_hash' => data_get($planPayload, 'plan_hash'),
            'source_readiness_gate_hash' => data_get($readinessGatePayload, 'gate_hash'),
            'blocker_target' => 'durable_reservation_ledger_missing',
            'operator_decision_required' => true,
            'implementation_allowed_now' => false,
            'packet_count' => count($packets),
            'packets' => $packets,
            'global_forbidden_scopes' => $this->hotForbiddenFiles(),
            'required_evidence' => [
                'approved_ap_document',
                'migration_or_storage_diff_after_approval',
                'repository_test_output',
                'scope_collision_test_output',
                'multi_session_readiness_gate_output',
                'docs_health_output',
                'architecture_validate_output',
                'rollback_notes',
            ],
            'promotion_gates' => [
                'operator_approval_present',
                'duplicate_claim_tests_pass',
                'collision_tests_pass',
                'lease_expiry_tests_pass',
                'append_only_event_tests_pass',
                'dispatch_still_disabled',
            ],
            'rollback_strategy' => [
                'disable_durable_claim_reads',
                'return_multi_session_gate_to_preview_only',
                'preserve_append_only_events_for_audit',
                'block_dispatch_until_manual_review',
            ],
            'next_safe_action' => 'review_ap_candidate_before_any_storage_or_migration_work',
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_ap_candidate.v1',
            'status' => 'durable_reservation_ap_candidate_ready',
            'mode' => 'read_only_durable_reservation_ap_candidate',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'operator_approval_required' => true,
            'storage_write_allowed' => false,
            'migration_write_allowed' => false,
            'dispatch_allowed' => false,
            'candidate' => $candidate,
            'candidate_hash' => ($this->stableHash)($candidate),
            'non_execution_guarantees' => [
                'durable_reservation_ap_candidate_does_not_create_migrations',
                'durable_reservation_ap_candidate_does_not_write_storage',
                'durable_reservation_ap_candidate_does_not_persist_claim',
                'durable_reservation_ap_candidate_does_not_dispatch_work',
                'durable_reservation_ap_candidate_requires_operator_approval',
            ],
            'human_summary' => 'Durable reservation AP candidate is ready: a future implementation is split into storage, repository, collision and readiness packets, but approval and execution remain disabled.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationApprovalRequest(array $options = []): array
    {
        $candidatePayload = $this->durableReservationApCandidate($options);
        $planPayload = $this->durableReservationLedgerImplementationPlan($options);
        $gatePayload = $this->multiSessionReadinessGate($options);

        $requiredSigners = [
            'product_governor',
            'architecture_governor',
            'safety_governance_reviewer',
            'implementation_operator',
        ];

        $request = [
            'request_id' => 'APPROVAL-REQUEST-DURABLE-RESERVATION-LEDGER-0001',
            'approval_status' => 'not_approved_read_only_request',
            'candidate_ap_id' => data_get($candidatePayload, 'candidate.ap_id'),
            'candidate_hash' => data_get($candidatePayload, 'candidate_hash'),
            'plan_hash' => data_get($planPayload, 'plan_hash'),
            'readiness_gate_hash' => data_get($gatePayload, 'gate_hash'),
            'blocker_target' => data_get($candidatePayload, 'candidate.blocker_target'),
            'required_signer_count' => count($requiredSigners),
            'required_signers' => $requiredSigners,
            'operator_decisions_required' => [
                'approve_storage_and_migration_scope',
                'accept_ap_candidate_packet_split',
                'keep_dispatch_disabled_after_ledger_activation',
                'accept_rollback_strategy',
                'confirm_hot_scopes_remain_forbidden',
            ],
            'approval_blockers' => [
                'candidate_hash_changed_after_review',
                'plan_hash_changed_after_review',
                'docs_health_failed',
                'architecture_validate_failed',
                'hot_scope_present_in_allowed_files',
                'dispatch_enabled_in_same_ap',
                'rollback_strategy_missing',
            ],
            'required_evidence' => [
                'candidate_hash',
                'plan_hash',
                'multi_session_readiness_gate_hash',
                'docs_health_output',
                'architecture_validate_output',
                'rollback_strategy',
                'forbidden_scope_list',
            ],
            'rollback_strategy' => data_get($candidatePayload, 'candidate.rollback_strategy', []),
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'post_approval_limits' => [
                'implementation_may_start_only_after_signed_approval',
                'dispatch_remains_disabled',
                'completion_requires_packet_completion_gate',
                'hot_scopes_remain_forbidden',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_approval_request.v1',
            'status' => 'durable_reservation_approval_request_ready',
            'mode' => 'read_only_durable_reservation_approval_request',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'approval_granted' => false,
            'storage_write_allowed' => false,
            'migration_write_allowed' => false,
            'dispatch_allowed' => false,
            'request' => $request,
            'request_hash' => ($this->stableHash)($request),
            'non_execution_guarantees' => [
                'durable_reservation_approval_request_does_not_grant_approval',
                'durable_reservation_approval_request_does_not_create_migrations',
                'durable_reservation_approval_request_does_not_write_storage',
                'durable_reservation_approval_request_does_not_persist_claim',
                'durable_reservation_approval_request_does_not_dispatch_work',
            ],
            'human_summary' => 'Durable reservation approval request is ready: signers, decisions, evidence and blockers are explicit, but no approval or execution was granted.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationApprovalDecisionTemplate(array $options = []): array
    {
        $requestPayload = $this->durableReservationApprovalRequest($options);
        $candidatePayload = $this->durableReservationApCandidate($options);
        $planPayload = $this->durableReservationLedgerImplementationPlan($options);
        $gatePayload = $this->multiSessionReadinessGate($options);

        $signerSlots = array_map(
            fn (string $role): array => [
                'role' => $role,
                'signer_id' => null,
                'signed_at' => null,
                'decision' => 'pending',
            ],
            (array) data_get($requestPayload, 'request.required_signers', []),
        );

        $decision = [
            'decision_id' => 'APPROVAL-DECISION-DURABLE-RESERVATION-LEDGER-0001',
            'decision_status' => 'template_not_signed',
            'allowed_decisions' => [
                'approved_for_scoped_implementation',
                'rejected',
                'needs_revision',
                'expired',
            ],
            'default_decision' => 'needs_revision',
            'approval_request_hash' => data_get($requestPayload, 'request_hash'),
            'candidate_hash' => data_get($candidatePayload, 'candidate_hash'),
            'plan_hash' => data_get($planPayload, 'plan_hash'),
            'readiness_gate_hash' => data_get($gatePayload, 'gate_hash'),
            'signer_slot_count' => count($signerSlots),
            'signer_slots' => $signerSlots,
            'approved_scope_if_signed' => [
                'durable_reservation_storage_after_approval',
                'durable_reservation_repository_after_approval',
                'durable_reservation_collision_policy_after_approval',
                'multi_session_gate_projection_after_approval',
            ],
            'forbidden_scope' => $this->hotForbiddenFiles(),
            'post_decision_limits' => [
                'approval_decision_must_match_current_hashes',
                'dispatch_requires_separate_future_ap',
                'completion_requires_packet_completion_gate',
                'hot_scopes_remain_forbidden',
            ],
            'expiry_checks' => [
                'request_hash_changed',
                'candidate_hash_changed',
                'plan_hash_changed',
                'readiness_gate_hash_changed',
                'required_evidence_missing',
            ],
            'rollback_strategy' => data_get($requestPayload, 'request.rollback_strategy', []),
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_approval_decision_template.v1',
            'status' => 'durable_reservation_approval_decision_template_ready',
            'mode' => 'read_only_durable_reservation_approval_decision_template',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'approval_granted' => false,
            'decision_signed' => false,
            'storage_write_allowed' => false,
            'migration_write_allowed' => false,
            'dispatch_allowed' => false,
            'decision' => $decision,
            'decision_hash' => ($this->stableHash)($decision),
            'non_execution_guarantees' => [
                'durable_reservation_approval_decision_template_does_not_grant_approval',
                'durable_reservation_approval_decision_template_does_not_sign_decision',
                'durable_reservation_approval_decision_template_does_not_write_storage',
                'durable_reservation_approval_decision_template_does_not_persist_claim',
                'durable_reservation_approval_decision_template_does_not_dispatch_work',
            ],
            'human_summary' => 'Durable reservation approval decision template is ready: approval states, signer slots, hash bindings and limits are explicit, but no decision was signed.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationPostApprovalPreflight(array $options = []): array
    {
        $decisionPayload = $this->durableReservationApprovalDecisionTemplate($options);
        $requestPayload = $this->durableReservationApprovalRequest($options);
        $candidatePayload = $this->durableReservationApCandidate($options);
        $planPayload = $this->durableReservationLedgerImplementationPlan($options);
        $gatePayload = $this->multiSessionReadinessGate($options);

        $checks = [
            [
                'check' => 'approval_decision_signed',
                'status' => 'blocked',
                'reason' => 'Current surface emits a decision template only; no signer has signed.',
            ],
            [
                'check' => 'approved_for_scoped_implementation',
                'status' => 'blocked',
                'reason' => 'Decision value is not signed as approved_for_scoped_implementation.',
            ],
            [
                'check' => 'hash_bindings_current',
                'status' => 'ready_for_future_validation',
                'bound_hashes' => [
                    'decision_hash' => data_get($decisionPayload, 'decision_hash'),
                    'request_hash' => data_get($requestPayload, 'request_hash'),
                    'candidate_hash' => data_get($candidatePayload, 'candidate_hash'),
                    'plan_hash' => data_get($planPayload, 'plan_hash'),
                    'readiness_gate_hash' => data_get($gatePayload, 'gate_hash'),
                ],
            ],
            [
                'check' => 'dispatch_disabled',
                'status' => 'passed',
                'reason' => 'Durable reservation approval chain still keeps dispatch disabled.',
            ],
            [
                'check' => 'hot_scopes_forbidden',
                'status' => 'passed',
                'forbidden_scopes' => $this->hotForbiddenFiles(),
            ],
        ];

        $blockingChecks = array_values(array_filter(
            $checks,
            fn (array $check): bool => data_get($check, 'status') === 'blocked',
        ));

        $preflight = [
            'preflight_id' => 'POST-APPROVAL-PREFLIGHT-DURABLE-RESERVATION-LEDGER-0001',
            'decision' => 'blocked_until_signed_approval',
            'blocking_check_count' => count($blockingChecks),
            'checks' => $checks,
            'required_before_implementation' => [
                'signed_approval_decision',
                'all_required_signer_slots_filled',
                'decision_value_approved_for_scoped_implementation',
                'hash_bindings_match_current_payloads',
                'docs_health_ok',
                'architecture_validate_ok',
                'rollback_strategy_accepted',
            ],
            'implementation_limits_after_pass' => [
                'storage_scope_only_as_approved',
                'repository_scope_only_as_approved',
                'collision_policy_scope_only_as_approved',
                'dispatch_remains_disabled',
                'completion_requires_packet_completion_gate',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'rollback_strategy' => data_get($decisionPayload, 'decision.rollback_strategy', []),
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_post_approval_preflight.v1',
            'status' => 'durable_reservation_post_approval_preflight_ready',
            'mode' => 'read_only_durable_reservation_post_approval_preflight',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'approval_granted' => false,
            'preflight_passed' => false,
            'storage_write_allowed' => false,
            'migration_write_allowed' => false,
            'dispatch_allowed' => false,
            'preflight' => $preflight,
            'preflight_hash' => ($this->stableHash)($preflight),
            'non_execution_guarantees' => [
                'durable_reservation_post_approval_preflight_does_not_sign_decision',
                'durable_reservation_post_approval_preflight_does_not_create_migrations',
                'durable_reservation_post_approval_preflight_does_not_write_storage',
                'durable_reservation_post_approval_preflight_does_not_persist_claim',
                'durable_reservation_post_approval_preflight_does_not_dispatch_work',
            ],
            'human_summary' => 'Durable reservation post-approval preflight is ready: implementation remains blocked until a signed approval passes hash, evidence and scope checks.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationImplementationPacket(array $options = []): array
    {
        $preflightPayload = $this->durableReservationPostApprovalPreflight($options);
        $candidatePayload = $this->durableReservationApCandidate($options);

        $workPackets = [
            [
                'id' => 'DR-IMPL-STORAGE-0001',
                'title' => 'Implement durable reservation storage',
                'allowed_future_scope' => [
                    'database/migrations/approved_self_construction_reservations',
                    'future_self_construction_reservation_models',
                ],
                'required_tests' => [
                    'storage_schema_contains_hash_fields',
                    'events_are_append_only',
                ],
            ],
            [
                'id' => 'DR-IMPL-REPOSITORY-0002',
                'title' => 'Implement reservation repository and locks',
                'allowed_future_scope' => [
                    'future_reservation_repository',
                    'future_repository_tests',
                ],
                'required_tests' => [
                    'blocks_duplicate_claim',
                    'allows_release_then_reclaim',
                    'blocks_expired_completion',
                ],
            ],
            [
                'id' => 'DR-IMPL-COLLISION-0003',
                'title' => 'Implement collision and hot-scope guards',
                'allowed_future_scope' => [
                    'future_scope_collision_detector',
                    'future_collision_tests',
                ],
                'required_tests' => [
                    'blocks_overlapping_allowed_files',
                    'blocks_hot_scope_claim',
                    'blocks_stale_hash_claim',
                ],
            ],
            [
                'id' => 'DR-IMPL-READINESS-0004',
                'title' => 'Integrate durable projection with readiness gate',
                'allowed_future_scope' => [
                    'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                    'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                    'self_construction_docs',
                ],
                'required_tests' => [
                    'multi_session_gate_reads_durable_projection',
                    'dispatch_remains_disabled',
                ],
            ],
        ];

        $packet = [
            'packet_id' => 'IMPLEMENTATION-PACKET-DURABLE-RESERVATION-LEDGER-0001',
            'packet_status' => 'blocked_until_post_approval_preflight_passes',
            'source_preflight_hash' => data_get($preflightPayload, 'preflight_hash'),
            'source_candidate_hash' => data_get($candidatePayload, 'candidate_hash'),
            'preflight_decision' => data_get($preflightPayload, 'preflight.decision'),
            'work_packet_count' => count($workPackets),
            'work_packets' => $workPackets,
            'required_first_commands' => [
                'git status --short',
                'git diff --stat',
                'git diff --name-only',
                'php artisan atlas:ai:self-construction --durable-reservation-post-approval-preflight --json',
                'php artisan atlas:ai:self-construction --scope-validator --json',
            ],
            'required_gates' => [
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'stop_conditions' => [
                'post_approval_preflight_not_passed',
                'approval_hash_drift_detected',
                'hot_scope_detected',
                'dispatch_scope_requested',
                'rollback_strategy_missing',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'implementation_allowed_now' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_implementation_packet.v1',
            'status' => 'durable_reservation_implementation_packet_ready',
            'mode' => 'read_only_durable_reservation_implementation_packet',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'storage_write_allowed' => false,
            'migration_write_allowed' => false,
            'dispatch_allowed' => false,
            'packet' => $packet,
            'packet_hash' => ($this->stableHash)($packet),
            'non_execution_guarantees' => [
                'durable_reservation_implementation_packet_does_not_create_migrations',
                'durable_reservation_implementation_packet_does_not_write_storage',
                'durable_reservation_implementation_packet_does_not_persist_claim',
                'durable_reservation_implementation_packet_does_not_dispatch_work',
                'durable_reservation_implementation_packet_requires_passed_preflight',
            ],
            'human_summary' => 'Durable reservation implementation packet is ready: future work is ordered, but implementation remains blocked until signed approval and preflight pass.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationStorageSchema(array $options = []): array
    {
        $implementationPayload = $this->durableReservationImplementationPacket($options);

        $tables = [
            [
                'name' => 'atlas_self_construction_reservations',
                'role' => 'current_reservation_projection',
                'fields' => [
                    'id',
                    'packet_id',
                    'state',
                    'owner_type',
                    'owner_id',
                    'session_id',
                    'packet_hash',
                    'allowed_files_hash',
                    'lease_expires_at',
                    'claimed_at',
                    'released_at',
                    'completed_at',
                    'blocked_reason',
                    'last_event_hash',
                    'created_at',
                    'updated_at',
                ],
                'indexes' => [
                    'packet_state_lease',
                    'owner_session_state',
                    'allowed_files_hash_state',
                    'last_event_hash',
                ],
            ],
            [
                'name' => 'atlas_self_construction_reservation_events',
                'role' => 'append_only_event_source',
                'fields' => [
                    'id',
                    'reservation_id',
                    'packet_id',
                    'event_type',
                    'actor_type',
                    'actor_id',
                    'session_id',
                    'owner_token_hash',
                    'packet_hash',
                    'allowed_files_hash',
                    'payload_json',
                    'previous_event_hash',
                    'event_hash',
                    'occurred_at',
                    'created_at',
                ],
                'indexes' => [
                    'unique_event_hash',
                    'reservation_id_occurred_at',
                    'packet_id_event_type',
                ],
            ],
            [
                'name' => 'atlas_self_construction_packet_snapshots',
                'role' => 'immutable_packet_snapshot',
                'fields' => ['id', 'snapshot_id', 'packet_id', 'packet_hash', 'split_hash', 'allowed_files_json', 'forbidden_files_json', 'scope_validator_hash', 'created_at'],
                'indexes' => ['snapshot_id', 'packet_id', 'packet_hash'],
            ],
        ];

        $storageSchema = [
            'schema_id' => 'STORAGE-SCHEMA-DURABLE-RESERVATION-LEDGER-0001',
            'schema_status' => 'blocked_until_post_approval_preflight_passes',
            'source_implementation_packet_hash' => data_get($implementationPayload, 'packet_hash'),
            'table_count' => count($tables),
            'tables' => $tables,
            'states' => [
                'available',
                'claimed',
                'renewed',
                'released',
                'expired',
                'completed',
                'blocked',
            ],
            'event_types' => [
                'claim_requested',
                'claim_granted',
                'claim_rejected',
                'lease_renewed',
                'reservation_released',
                'reservation_expired',
                'completion_requested',
                'completion_recorded',
                'reservation_blocked',
            ],
            'invariants' => [
                'events_are_append_only',
                'events_chain_previous_event_hash',
                'one_active_claim_per_packet',
                'active_allowed_file_overlap_blocks_new_claim',
                'expired_reservation_cannot_complete',
                'released_reservation_can_be_reclaimed',
                'hot_voice_kernel_scope_is_not_claimable',
                'dispatch_remains_disabled',
            ],
            'required_tests' => [
                'storage_schema_contains_hash_actor_state_lease_and_payload_fields',
                'duplicate_active_packet_claim_is_blocked',
                'overlapping_active_file_scope_is_blocked',
                'expired_reservation_cannot_complete',
                'released_reservation_can_be_reclaimed',
                'projection_can_be_rebuilt_from_events',
                'storage_schema_command_does_not_create_migrations_or_write_storage',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'migration_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_storage_schema.v1',
            'status' => 'durable_reservation_storage_schema_ready',
            'mode' => 'read_only_durable_reservation_storage_schema',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'migration_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'storage_schema' => $storageSchema,
            'schema_hash' => ($this->stableHash)($storageSchema),
            'non_execution_guarantees' => [
                'durable_reservation_storage_schema_does_not_create_migrations',
                'durable_reservation_storage_schema_does_not_write_storage',
                'durable_reservation_storage_schema_does_not_persist_claim',
                'durable_reservation_storage_schema_does_not_dispatch_work',
                'durable_reservation_storage_schema_requires_passed_preflight',
            ],
            'human_summary' => 'Durable reservation storage schema is ready: future migrations have a precise event/projection contract, but no storage work is allowed yet.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationRepositoryContract(array $options = []): array
    {
        $schemaPayload = $this->durableReservationStorageSchema($options);

        $methods = [
            [
                'name' => 'preview',
                'purpose' => 'validate packet, actor and scope without durable claim persistence',
                'writes_events' => false,
                'writes_projection' => false,
            ],
            [
                'name' => 'claim',
                'purpose' => 'atomically claim an available packet with lease and scope locks',
                'writes_events' => true,
                'writes_projection' => true,
            ],
            [
                'name' => 'renew',
                'purpose' => 'extend an active lease owned by the same actor',
                'writes_events' => true,
                'writes_projection' => true,
            ],
            [
                'name' => 'release',
                'purpose' => 'release an active reservation and free the packet for future claim',
                'writes_events' => true,
                'writes_projection' => true,
            ],
            [
                'name' => 'expire',
                'purpose' => 'mark expired leases from event/projection state',
                'writes_events' => true,
                'writes_projection' => true,
            ],
            [
                'name' => 'complete',
                'purpose' => 'record completion only after packet completion gate and evidence pass',
                'writes_events' => true,
                'writes_projection' => true,
            ],
            [
                'name' => 'current',
                'purpose' => 'read current projection for one packet',
                'writes_events' => false,
                'writes_projection' => false,
            ],
            [
                'name' => 'activeCollisions',
                'purpose' => 'read active overlapping allowed-file claims',
                'writes_events' => false,
                'writes_projection' => false,
            ],
            [
                'name' => 'rebuildProjection',
                'purpose' => 'rebuild current state from reservation events',
                'writes_events' => false,
                'writes_projection' => true,
            ],
        ];

        $repositoryContract = [
            'contract_id' => 'REPOSITORY-CONTRACT-DURABLE-RESERVATION-LEDGER-0001',
            'contract_status' => 'blocked_until_storage_schema_and_preflight_pass',
            'source_storage_schema_hash' => data_get($schemaPayload, 'schema_hash'),
            'method_count' => count($methods),
            'methods' => $methods,
            'errors' => [
                'packet_already_claimed',
                'allowed_files_overlap_active_reservation',
                'hot_scope_forbidden',
                'packet_hash_stale',
                'lease_expired',
                'completion_gate_missing',
                'actor_not_owner',
                'event_chain_mismatch',
            ],
            'transaction_rules' => [
                'acquire_packet_and_allowed_file_scope_lock_before_claim',
                'append_event_before_projection_update',
                'reject_projection_update_when_event_hash_chain_is_broken',
                'release_and_completion_are_terminal_for_current_lease',
                'dispatch_is_never_enabled_by_repository_methods',
            ],
            'required_tests' => [
                'claim_writes_claim_event_then_projection',
                'duplicate_active_claim_is_rejected',
                'overlapping_allowed_files_are_rejected',
                'non_owner_cannot_release_or_complete',
                'expired_lease_cannot_complete',
                'stale_packet_hash_is_rejected',
                'projection_rebuild_matches_current_projection',
                'repository_contract_command_does_not_persist_claims_or_write_storage',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'repository_write_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_repository_contract.v1',
            'status' => 'durable_reservation_repository_contract_ready',
            'mode' => 'read_only_durable_reservation_repository_contract',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'repository_write_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'repository_contract' => $repositoryContract,
            'contract_hash' => ($this->stableHash)($repositoryContract),
            'non_execution_guarantees' => [
                'durable_reservation_repository_contract_does_not_create_repository',
                'durable_reservation_repository_contract_does_not_write_storage',
                'durable_reservation_repository_contract_does_not_persist_claim',
                'durable_reservation_repository_contract_does_not_dispatch_work',
                'durable_reservation_repository_contract_requires_storage_schema',
            ],
            'human_summary' => 'Durable reservation repository contract is ready: future claim methods, errors, transactions and tests are precise, but no repository or storage writes are allowed yet.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationCollisionGuard(array $options = []): array
    {
        $repositoryPayload = $this->durableReservationRepositoryContract($options);

        $collisionGuard = [
            'guard_id' => 'COLLISION-GUARD-DURABLE-RESERVATION-LEDGER-0001',
            'guard_status' => 'blocked_until_repository_and_projection_exist',
            'source_repository_contract_hash' => data_get($repositoryPayload, 'contract_hash'),
            'inputs' => [
                'candidate_packet_id',
                'candidate_allowed_files',
                'candidate_forbidden_files',
                'candidate_packet_hash',
                'candidate_dependency_ids',
                'active_reservation_projections',
                'hot_forbidden_scope_list',
                'current_changed_files',
                'completion_gate_status',
            ],
            'blocking_decisions' => [
                'hot_scope_forbidden',
                'active_file_overlap',
                'packet_hash_stale',
                'dependency_incomplete',
                'completion_gate_blocked',
                'owner_conflict',
            ],
            'blocking_decision_count' => 6,
            'outputs' => [
                'decision',
                'blocking_reasons',
                'overlapping_files',
                'active_reservation_ids',
                'stale_hashes',
                'dependency_blockers',
                'hot_scope_matches',
                'required_next_command',
            ],
            'decision_states' => [
                'allow_preview',
                'block_claim',
                'require_human_review',
            ],
            'required_tests' => [
                'hot_voice_kernel_scope_is_blocked',
                'overlapping_active_file_scope_is_blocked',
                'stale_packet_hash_is_blocked',
                'incomplete_dependency_is_blocked',
                'clean_disjoint_packet_remains_preview_allowable',
                'collision_guard_command_does_not_persist_claims_or_write_storage',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_collision_guard.v1',
            'status' => 'durable_reservation_collision_guard_ready',
            'mode' => 'read_only_durable_reservation_collision_guard',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'collision_guard' => $collisionGuard,
            'guard_hash' => ($this->stableHash)($collisionGuard),
            'non_execution_guarantees' => [
                'durable_reservation_collision_guard_does_not_create_detector',
                'durable_reservation_collision_guard_does_not_write_storage',
                'durable_reservation_collision_guard_does_not_persist_claim',
                'durable_reservation_collision_guard_does_not_dispatch_work',
                'durable_reservation_collision_guard_requires_repository_projection',
            ],
            'human_summary' => 'Durable reservation collision guard is ready: future claim safety decisions are precise, but no detector, claim or storage write is allowed yet.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationLeaseLifecycle(array $options = []): array
    {
        $collisionPayload = $this->durableReservationCollisionGuard($options);

        $states = [
            'available',
            'claimed',
            'renewed',
            'released',
            'expired',
            'completed',
            'blocked',
        ];

        $leaseLifecycle = [
            'lifecycle_id' => 'LEASE-LIFECYCLE-DURABLE-RESERVATION-LEDGER-0001',
            'lifecycle_status' => 'blocked_until_repository_projection_and_collision_guard_exist',
            'source_collision_guard_hash' => data_get($collisionPayload, 'guard_hash'),
            'state_count' => count($states),
            'states' => $states,
            'transitions' => [
                'available_to_claimed',
                'claimed_to_renewed',
                'claimed_to_released',
                'claimed_to_expired',
                'renewed_to_released',
                'renewed_to_expired',
                'claimed_to_completed',
                'renewed_to_completed',
                'any_to_blocked_when_guard_rejects_action',
            ],
            'timing_rules' => [
                'default_lease_duration_must_be_explicit_in_config_or_policy',
                'renew_requires_same_owner_session_and_active_lease',
                'release_requires_same_owner_session_and_active_lease',
                'expire_may_be_system_driven_and_must_append_event',
                'completion_requires_active_lease_same_owner_and_passing_completion_gate',
                'expired_or_released_leases_cannot_complete',
            ],
            'required_tests' => [
                'owner_can_renew_active_lease',
                'non_owner_cannot_renew_or_release',
                'expired_lease_cannot_complete',
                'released_lease_cannot_complete',
                'expired_packet_can_be_reclaimed_after_expiry_event',
                'completion_requires_packet_completion_gate_pass',
                'lease_lifecycle_command_does_not_persist_claims_or_write_storage',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_lease_lifecycle.v1',
            'status' => 'durable_reservation_lease_lifecycle_ready',
            'mode' => 'read_only_durable_reservation_lease_lifecycle',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'lease_lifecycle' => $leaseLifecycle,
            'lifecycle_hash' => ($this->stableHash)($leaseLifecycle),
            'non_execution_guarantees' => [
                'durable_reservation_lease_lifecycle_does_not_create_lifecycle_runtime',
                'durable_reservation_lease_lifecycle_does_not_write_storage',
                'durable_reservation_lease_lifecycle_does_not_persist_claim',
                'durable_reservation_lease_lifecycle_does_not_dispatch_work',
                'durable_reservation_lease_lifecycle_requires_collision_guard',
            ],
            'human_summary' => 'Durable reservation lease lifecycle is ready: future timing, renewal, release, expiry and completion semantics are precise, but no runtime or storage write is allowed yet.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationReadinessProjection(array $options = []): array
    {
        $lifecyclePayload = $this->durableReservationLeaseLifecycle($options);

        $queueStates = [
            'available',
            'claimed',
            'blocked_by_collision',
            'blocked_by_dependency',
            'blocked_by_hot_scope',
            'blocked_by_stale_hash',
            'completed',
        ];

        $readinessProjection = [
            'projection_id' => 'READINESS-PROJECTION-DURABLE-RESERVATION-LEDGER-0001',
            'projection_status' => 'blocked_until_durable_projection_exists',
            'source_lease_lifecycle_hash' => data_get($lifecyclePayload, 'lifecycle_hash'),
            'inputs' => [
                'active_reservations',
                'expired_reservations',
                'completed_packets',
                'blocked_packets',
                'packet_dependencies',
                'allowed_file_scopes',
                'hot_forbidden_scopes',
                'current_changed_files',
                'completion_gate_status',
            ],
            'queue_state_count' => count($queueStates),
            'queue_states' => $queueStates,
            'outputs' => [
                'queue_summary',
                'claimable_packet_ids',
                'blocked_packet_ids_and_reasons',
                'active_reservation_owners',
                'dependency_unlock_hints',
                'multi_session_decision',
                'safe_single_session_fallback_instruction',
            ],
            'integration_targets' => [
                'packet_queue',
                'collision_matrix',
                'dependency_unlock_plan',
                'multi_session_readiness_gate',
                'single_session_instruction_packet',
            ],
            'required_tests' => [
                'active_reservation_removes_packet_from_claimable_queue',
                'completed_dependency_unlocks_dependent_packet',
                'hot_scope_blocks_packet_before_queue_assignment',
                'stale_packet_hash_blocks_claim',
                'multi_session_gate_blocks_when_durable_ledger_is_missing',
                'single_session_fallback_remains_available_when_parallel_dispatch_is_blocked',
                'readiness_projection_command_does_not_persist_claims_or_write_storage',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_readiness_projection.v1',
            'status' => 'durable_reservation_readiness_projection_ready',
            'mode' => 'read_only_durable_reservation_readiness_projection',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'readiness_projection' => $readinessProjection,
            'projection_hash' => ($this->stableHash)($readinessProjection),
            'non_execution_guarantees' => [
                'durable_reservation_readiness_projection_does_not_create_projection_runtime',
                'durable_reservation_readiness_projection_does_not_write_storage',
                'durable_reservation_readiness_projection_does_not_persist_claim',
                'durable_reservation_readiness_projection_does_not_dispatch_work',
                'durable_reservation_readiness_projection_requires_durable_projection',
            ],
            'human_summary' => 'Durable reservation readiness projection is ready: future queue, collision and multi-session gates know how to consume durable state, but no projection runtime or storage write is allowed yet.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationImplementationPreflight(array $options = []): array
    {
        $postApprovalPayload = $this->durableReservationPostApprovalPreflight($options);
        $implementationPayload = $this->durableReservationImplementationPacket($options);
        $schemaPayload = $this->durableReservationStorageSchema($options);
        $repositoryPayload = $this->durableReservationRepositoryContract($options);
        $collisionPayload = $this->durableReservationCollisionGuard($options);
        $lifecyclePayload = $this->durableReservationLeaseLifecycle($options);
        $projectionPayload = $this->durableReservationReadinessProjection($options);

        $contractHashes = [
            ['id' => 'post_approval_preflight', 'hash' => data_get($postApprovalPayload, 'preflight_hash')],
            ['id' => 'implementation_packet', 'hash' => data_get($implementationPayload, 'packet_hash')],
            ['id' => 'storage_schema', 'hash' => data_get($schemaPayload, 'schema_hash')],
            ['id' => 'repository_contract', 'hash' => data_get($repositoryPayload, 'contract_hash')],
            ['id' => 'collision_guard', 'hash' => data_get($collisionPayload, 'guard_hash')],
            ['id' => 'lease_lifecycle', 'hash' => data_get($lifecyclePayload, 'lifecycle_hash')],
            ['id' => 'readiness_projection', 'hash' => data_get($projectionPayload, 'projection_hash')],
        ];

        $implementationPreflight = [
            'preflight_id' => 'IMPLEMENTATION-PREFLIGHT-DURABLE-RESERVATION-LEDGER-0001',
            'preflight_status' => 'blocked_until_signed_approval_and_contract_hashes_pass',
            'contract_hash_count' => count($contractHashes),
            'contract_hashes' => $contractHashes,
            'required_gates' => [
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'php artisan atlas:ai:self-construction --traceability --json',
                'php artisan atlas:engineering:knowledge docs-health --json',
                'php artisan atlas:ai:architecture-validate --json',
                'git diff --check',
            ],
            'blocking_conditions' => [
                'any_contract_hash_missing',
                'contract_hash_drift_detected',
                'traceability_not_clean',
                'docs_health_failed',
                'architecture_validate_failed',
                'migration_scope_broader_than_approval',
                'storage_write_requested_before_approval',
                'dispatch_requested',
            ],
            'required_first_commands' => [
                'git status --short',
                'git diff --stat',
                'git diff --name-only',
                'php artisan atlas:ai:self-construction --durable-reservation-implementation-preflight --json',
                'php artisan atlas:ai:self-construction --scope-validator --json',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'implementation_allowed_now' => false,
            'migration_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_implementation_preflight.v1',
            'status' => 'durable_reservation_implementation_preflight_ready',
            'mode' => 'read_only_durable_reservation_implementation_preflight',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'migration_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'implementation_preflight' => $implementationPreflight,
            'preflight_hash' => ($this->stableHash)($implementationPreflight),
            'non_execution_guarantees' => [
                'durable_reservation_implementation_preflight_does_not_approve_work',
                'durable_reservation_implementation_preflight_does_not_create_migrations',
                'durable_reservation_implementation_preflight_does_not_write_storage',
                'durable_reservation_implementation_preflight_does_not_persist_claim',
                'durable_reservation_implementation_preflight_does_not_dispatch_work',
            ],
            'human_summary' => 'Durable reservation implementation preflight is ready: all contract hashes are bundled, but implementation remains blocked until signed approval and gates pass.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationMigrationBlueprint(array $options = []): array
    {
        $preflightPayload = $this->durableReservationImplementationPreflight($options);
        $schemaPayload = $this->durableReservationStorageSchema($options);

        $tables = [
            [
                'name' => 'atlas_self_construction_reservations',
                'purpose' => 'current_reservation_projection_for_fast_claim_checks',
                'columns' => [
                    'id',
                    'reservation_id',
                    'packet_id',
                    'owner_id',
                    'session_id',
                    'state',
                    'packet_hash',
                    'allowed_files_hash',
                    'lease_expires_at',
                    'completed_at',
                    'released_at',
                    'blocker_reason',
                    'created_at',
                    'updated_at',
                ],
                'indexes' => [
                    'active_packet_claim_guard',
                    'packet_id',
                    'owner_session',
                    'state',
                    'lease_expires_at',
                    'allowed_files_hash',
                ],
            ],
            [
                'name' => 'atlas_self_construction_reservation_events',
                'purpose' => 'append_only_reservation_event_source',
                'columns' => [
                    'id',
                    'reservation_id',
                    'packet_id',
                    'event_type',
                    'actor_id',
                    'session_id',
                    'packet_hash',
                    'allowed_files_hash',
                    'previous_event_hash',
                    'event_hash',
                    'payload',
                    'created_at',
                ],
                'indexes' => [
                    'reservation_id',
                    'packet_id',
                    'event_type',
                    'session_id',
                    'event_hash_unique',
                    'created_at',
                ],
            ],
            [
                'name' => 'atlas_self_construction_packet_snapshots',
                'purpose' => 'immutable_packet_snapshot_at_claim_time',
                'columns' => ['id', 'snapshot_id', 'packet_id', 'packet_hash', 'split_hash', 'allowed_files_json', 'forbidden_files_json', 'scope_validator_hash', 'created_at'],
                'indexes' => ['snapshot_id_unique', 'packet_id', 'packet_hash'],
            ],
        ];

        $migrationBlueprint = [
            'blueprint_id' => 'MIGRATION-BLUEPRINT-DURABLE-RESERVATION-LEDGER-0001',
            'blueprint_status' => 'blocked_until_signed_preflight_and_migration_scope_approval',
            'source_preflight_hash' => data_get($preflightPayload, 'preflight_hash'),
            'source_storage_schema_hash' => data_get($schemaPayload, 'schema_hash'),
            'migration_files' => [
                'create_atlas_self_construction_reservations_table',
                'create_atlas_self_construction_reservation_events_table',
                'create_atlas_self_construction_packet_snapshots_table',
            ],
            'table_count' => count($tables),
            'tables' => $tables,
            'rollback_order' => [
                'drop_atlas_self_construction_packet_snapshots',
                'drop_atlas_self_construction_reservation_events',
                'drop_atlas_self_construction_reservations',
            ],
            'required_tests' => [
                'migration_creates_reservation_events_table_with_required_columns',
                'migration_creates_reservations_projection_table_with_required_columns',
                'migration_creates_packet_snapshots_table_with_required_columns',
                'event_hash_is_unique',
                'lease_expiry_is_indexed_and_queryable',
                'projection_can_be_rebuilt_from_events',
                'rollback_drops_projection_before_events',
                'migration_blueprint_command_does_not_create_migrations_or_write_storage',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'implementation_allowed_now' => false,
            'migration_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_migration_blueprint.v1',
            'status' => 'durable_reservation_migration_blueprint_ready',
            'mode' => 'read_only_durable_reservation_migration_blueprint',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'migration_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'migration_blueprint' => $migrationBlueprint,
            'blueprint_hash' => ($this->stableHash)($migrationBlueprint),
            'non_execution_guarantees' => [
                'durable_reservation_migration_blueprint_does_not_create_migration_files',
                'durable_reservation_migration_blueprint_does_not_run_migrations',
                'durable_reservation_migration_blueprint_does_not_write_storage',
                'durable_reservation_migration_blueprint_does_not_persist_claim',
                'durable_reservation_migration_blueprint_does_not_dispatch_work',
            ],
            'human_summary' => 'Durable reservation migration blueprint is ready: table names, columns, indexes, rollback and tests are fixed, but migration creation remains blocked.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationRepositoryBlueprint(array $options = []): array
    {
        $repositoryPayload = $this->durableReservationRepositoryContract($options);
        $migrationPayload = $this->durableReservationMigrationBlueprint($options);

        $classes = [
            'App\Services\Ai\SelfConstruction\Reservations\DurableReservationRepository',
            'App\Services\Ai\SelfConstruction\Reservations\DurableReservationCollisionGuard',
            'App\Services\Ai\SelfConstruction\Reservations\ReservationEventHasher',
            'App\Services\Ai\SelfConstruction\Reservations\ReservationProjectionBuilder',
            'App\Services\Ai\SelfConstruction\Reservations\Data\ReservationClaimRequest',
            'App\Services\Ai\SelfConstruction\Reservations\Data\ReservationClaimResult',
            'App\Services\Ai\SelfConstruction\Reservations\Exceptions\ReservationRejectedException',
        ];

        $methods = [
            'preview',
            'claim',
            'renew',
            'release',
            'expire',
            'complete',
            'current',
            'activeCollisions',
            'rebuildProjection',
        ];

        $repositoryBlueprint = [
            'blueprint_id' => 'REPOSITORY-BLUEPRINT-DURABLE-RESERVATION-LEDGER-0001',
            'blueprint_status' => 'blocked_until_migration_blueprint_and_repository_scope_approval',
            'source_repository_contract_hash' => data_get($repositoryPayload, 'contract_hash'),
            'source_migration_blueprint_hash' => data_get($migrationPayload, 'blueprint_hash'),
            'class_count' => count($classes),
            'classes' => $classes,
            'method_count' => count($methods),
            'methods' => $methods,
            'error_codes' => [
                'packet_already_claimed',
                'allowed_files_overlap_active_reservation',
                'hot_scope_forbidden',
                'packet_hash_stale',
                'lease_expired',
                'completion_gate_missing',
                'actor_not_owner',
                'event_chain_mismatch',
            ],
            'transaction_rules' => [
                'claim_renew_release_expire_and_complete_run_inside_database_transactions',
                'claim_locks_packet_scope_before_appending_event',
                'event_hash_includes_previous_event_hash_actor_packet_hash_and_payload',
                'projection_updates_only_after_accepted_events',
                'completion_requires_packet_completion_gate_evidence',
                'repository_methods_never_dispatch_work',
            ],
            'required_tests' => [
                'preview_reports_rejection_without_writing_events',
                'claim_appends_event_and_updates_projection_atomically',
                'duplicate_active_packet_claim_is_rejected',
                'overlapping_active_file_scope_is_rejected',
                'stale_packet_hash_is_rejected',
                'non_owner_cannot_renew_release_or_complete',
                'event_chain_mismatch_blocks_projection_update',
                'repository_blueprint_command_does_not_create_php_files_or_write_storage',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'implementation_allowed_now' => false,
            'migration_allowed_now' => false,
            'runtime_file_creation_allowed' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_repository_blueprint.v1',
            'status' => 'durable_reservation_repository_blueprint_ready',
            'mode' => 'read_only_durable_reservation_repository_blueprint',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'migration_allowed_now' => false,
            'runtime_file_creation_allowed' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'repository_blueprint' => $repositoryBlueprint,
            'blueprint_hash' => ($this->stableHash)($repositoryBlueprint),
            'non_execution_guarantees' => [
                'durable_reservation_repository_blueprint_does_not_create_php_files',
                'durable_reservation_repository_blueprint_does_not_run_migrations',
                'durable_reservation_repository_blueprint_does_not_write_storage',
                'durable_reservation_repository_blueprint_does_not_persist_claim',
                'durable_reservation_repository_blueprint_does_not_dispatch_work',
            ],
            'human_summary' => 'Durable reservation repository blueprint is ready: classes, methods, errors, transactions and tests are fixed, but runtime file creation remains blocked.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationCollisionGuardBlueprint(array $options = []): array
    {
        $collisionPayload = $this->durableReservationCollisionGuard($options);
        $repositoryBlueprintPayload = $this->durableReservationRepositoryBlueprint($options);

        $blockers = [
            'hot_scope_forbidden',
            'active_file_overlap',
            'packet_hash_stale',
            'dependency_incomplete',
            'completion_gate_blocked',
            'owner_conflict',
        ];

        $outputs = [
            'decision_state',
            'blocker_code',
            'human_reason',
            'conflicting_reservation_ids',
            'conflicting_file_paths',
            'packet_hash_used_for_decision',
            'guard_hash',
        ];

        $collisionGuardBlueprint = [
            'blueprint_id' => 'COLLISION-GUARD-BLUEPRINT-DURABLE-RESERVATION-LEDGER-0001',
            'blueprint_status' => 'blocked_until_repository_blueprint_and_guard_scope_approval',
            'source_collision_guard_hash' => data_get($collisionPayload, 'guard_hash'),
            'source_repository_blueprint_hash' => data_get($repositoryBlueprintPayload, 'blueprint_hash'),
            'inputs' => [
                'candidate_packet_id',
                'candidate_packet_hash',
                'candidate_allowed_files',
                'current_active_reservations',
                'current_changed_files',
                'hot_forbidden_scopes',
                'packet_dependency_status',
                'packet_completion_gate_status',
            ],
            'blocker_count' => count($blockers),
            'blockers' => $blockers,
            'decision_states' => [
                'allow_preview',
                'allow_claim',
                'block_claim',
                'require_human_review',
            ],
            'output_count' => count($outputs),
            'outputs' => $outputs,
            'required_tests' => [
                'hot_voice_kernel_scope_is_blocked',
                'overlapping_active_file_scope_is_blocked',
                'stale_packet_hash_is_blocked',
                'incomplete_dependency_is_blocked',
                'clean_disjoint_packet_can_be_claimable',
                'guard_explains_conflicting_reservation_ids_and_file_paths',
                'collision_guard_blueprint_command_does_not_create_php_files_or_write_storage',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'implementation_allowed_now' => false,
            'runtime_file_creation_allowed' => false,
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_collision_guard_blueprint.v1',
            'status' => 'durable_reservation_collision_guard_blueprint_ready',
            'mode' => 'read_only_durable_reservation_collision_guard_blueprint',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'runtime_file_creation_allowed' => false,
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'collision_guard_blueprint' => $collisionGuardBlueprint,
            'blueprint_hash' => ($this->stableHash)($collisionGuardBlueprint),
            'non_execution_guarantees' => [
                'durable_reservation_collision_guard_blueprint_does_not_create_php_files',
                'durable_reservation_collision_guard_blueprint_does_not_write_storage',
                'durable_reservation_collision_guard_blueprint_does_not_persist_claim',
                'durable_reservation_collision_guard_blueprint_does_not_dispatch_work',
            ],
            'human_summary' => 'Durable reservation collision guard blueprint is ready: inputs, blockers, outputs and tests are fixed, but runtime file creation remains blocked.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationLeaseLifecycleBlueprint(array $options = []): array
    {
        $lifecyclePayload = $this->durableReservationLeaseLifecycle($options);
        $guardBlueprintPayload = $this->durableReservationCollisionGuardBlueprint($options);

        $states = [
            'available',
            'claimed',
            'renewed',
            'released',
            'expired',
            'completed',
            'blocked',
        ];

        $transitions = [
            'available_to_claimed',
            'claimed_to_renewed',
            'claimed_to_released',
            'claimed_to_expired',
            'renewed_to_released',
            'renewed_to_expired',
            'claimed_to_completed',
            'renewed_to_completed',
            'released_or_expired_to_claimed_by_new_owner',
            'any_to_blocked_when_guard_rejects_action',
        ];

        $leaseLifecycleBlueprint = [
            'blueprint_id' => 'LEASE-LIFECYCLE-BLUEPRINT-DURABLE-RESERVATION-LEDGER-0001',
            'blueprint_status' => 'blocked_until_collision_guard_blueprint_and_lifecycle_scope_approval',
            'source_lease_lifecycle_hash' => data_get($lifecyclePayload, 'lifecycle_hash'),
            'source_collision_guard_blueprint_hash' => data_get($guardBlueprintPayload, 'blueprint_hash'),
            'state_count' => count($states),
            'states' => $states,
            'transition_count' => count($transitions),
            'transitions' => $transitions,
            'timing_rules' => [
                'default_lease_duration_must_come_from_config_or_policy',
                'renew_requires_same_owner_session_and_active_lease',
                'release_requires_same_owner_session_and_active_lease',
                'expiry_may_be_system_driven_and_must_append_event',
                'completion_requires_same_owner_active_lease_and_passing_completion_gate',
                'expired_or_released_leases_cannot_complete',
            ],
            'required_tests' => [
                'owner_can_renew_active_lease',
                'non_owner_cannot_renew_release_or_complete',
                'expired_lease_cannot_complete',
                'released_lease_cannot_complete',
                'expired_packet_can_be_reclaimed_after_expiry_event',
                'released_packet_can_be_reclaimed_after_release_event',
                'completion_requires_packet_completion_gate_evidence',
                'lease_lifecycle_blueprint_command_does_not_create_php_files_or_write_storage',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'implementation_allowed_now' => false,
            'runtime_file_creation_allowed' => false,
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_lease_lifecycle_blueprint.v1',
            'status' => 'durable_reservation_lease_lifecycle_blueprint_ready',
            'mode' => 'read_only_durable_reservation_lease_lifecycle_blueprint',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'runtime_file_creation_allowed' => false,
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'lease_lifecycle_blueprint' => $leaseLifecycleBlueprint,
            'blueprint_hash' => ($this->stableHash)($leaseLifecycleBlueprint),
            'non_execution_guarantees' => [
                'durable_reservation_lease_lifecycle_blueprint_does_not_create_php_files',
                'durable_reservation_lease_lifecycle_blueprint_does_not_write_storage',
                'durable_reservation_lease_lifecycle_blueprint_does_not_persist_claim',
                'durable_reservation_lease_lifecycle_blueprint_does_not_dispatch_work',
            ],
            'human_summary' => 'Durable reservation lease lifecycle blueprint is ready: states, transitions, timing rules and tests are fixed, but runtime file creation remains blocked.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationReadinessProjectionBlueprint(array $options = []): array
    {
        $projectionPayload = $this->durableReservationReadinessProjection($options);
        $lifecycleBlueprintPayload = $this->durableReservationLeaseLifecycleBlueprint($options);

        $queueStates = [
            'available',
            'claimed',
            'blocked_by_collision',
            'blocked_by_dependency',
            'blocked_by_hot_scope',
            'blocked_by_stale_hash',
            'completed',
        ];

        $outputs = [
            'queue_summary',
            'claimable_packet_ids',
            'blocked_packet_ids_and_reasons',
            'active_reservation_owners',
            'dependency_unlock_hints',
            'multi_session_decision',
            'safe_single_session_fallback_instruction',
        ];

        $readinessProjectionBlueprint = [
            'blueprint_id' => 'READINESS-PROJECTION-BLUEPRINT-DURABLE-RESERVATION-LEDGER-0001',
            'blueprint_status' => 'blocked_until_lease_lifecycle_blueprint_and_projection_scope_approval',
            'source_readiness_projection_hash' => data_get($projectionPayload, 'projection_hash'),
            'source_lease_lifecycle_blueprint_hash' => data_get($lifecycleBlueprintPayload, 'blueprint_hash'),
            'inputs' => [
                'active_reservations',
                'expired_reservations',
                'completed_packets',
                'blocked_packets',
                'packet_dependencies',
                'allowed_file_scopes',
                'hot_forbidden_scopes',
                'current_changed_files',
                'packet_completion_gate_status',
            ],
            'queue_state_count' => count($queueStates),
            'queue_states' => $queueStates,
            'output_count' => count($outputs),
            'outputs' => $outputs,
            'integration_targets' => [
                'packet_queue',
                'collision_matrix',
                'dependency_unlock_plan',
                'multi_session_readiness_gate',
                'single_session_instruction_packet',
            ],
            'required_tests' => [
                'active_reservation_removes_packet_from_claimable_queue',
                'completed_dependency_unlocks_dependent_packet',
                'hot_scope_blocks_packet_before_queue_assignment',
                'stale_packet_hash_blocks_claim',
                'completed_packet_is_not_claimable',
                'multi_session_gate_blocks_when_durable_projection_is_missing',
                'single_session_fallback_remains_available_when_parallel_dispatch_is_blocked',
                'readiness_projection_blueprint_command_does_not_create_php_files_or_write_storage',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'implementation_allowed_now' => false,
            'runtime_file_creation_allowed' => false,
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_readiness_projection_blueprint.v1',
            'status' => 'durable_reservation_readiness_projection_blueprint_ready',
            'mode' => 'read_only_durable_reservation_readiness_projection_blueprint',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'runtime_file_creation_allowed' => false,
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'readiness_projection_blueprint' => $readinessProjectionBlueprint,
            'blueprint_hash' => ($this->stableHash)($readinessProjectionBlueprint),
            'non_execution_guarantees' => [
                'durable_reservation_readiness_projection_blueprint_does_not_create_php_files',
                'durable_reservation_readiness_projection_blueprint_does_not_write_storage',
                'durable_reservation_readiness_projection_blueprint_does_not_persist_claim',
                'durable_reservation_readiness_projection_blueprint_does_not_dispatch_work',
            ],
            'human_summary' => 'Durable reservation readiness projection blueprint is ready: inputs, queue states, outputs and integrations are fixed, but runtime file creation remains blocked.',
        ];
    }


    /**
     * @param  array{workspace?: string|null, target?: string|null}  $options
     * @return array<string, mixed>
     */
    public function durableReservationRuntimeBuildPacket(array $options = []): array
    {
        $migrationBlueprintPayload = $this->durableReservationMigrationBlueprint($options);
        $repositoryBlueprintPayload = $this->durableReservationRepositoryBlueprint($options);
        $collisionGuardBlueprintPayload = $this->durableReservationCollisionGuardBlueprint($options);
        $leaseLifecycleBlueprintPayload = $this->durableReservationLeaseLifecycleBlueprint($options);
        $readinessProjectionBlueprintPayload = $this->durableReservationReadinessProjectionBlueprint($options);

        $sourceBlueprints = [
            ['id' => 'migration_blueprint', 'hash' => data_get($migrationBlueprintPayload, 'blueprint_hash')],
            ['id' => 'repository_blueprint', 'hash' => data_get($repositoryBlueprintPayload, 'blueprint_hash')],
            ['id' => 'collision_guard_blueprint', 'hash' => data_get($collisionGuardBlueprintPayload, 'blueprint_hash')],
            ['id' => 'lease_lifecycle_blueprint', 'hash' => data_get($leaseLifecycleBlueprintPayload, 'blueprint_hash')],
            ['id' => 'readiness_projection_blueprint', 'hash' => data_get($readinessProjectionBlueprintPayload, 'blueprint_hash')],
        ];

        $implementationSlices = [
            'migrations',
            'dtos_results_and_exceptions',
            'durable_reservation_repository',
            'collision_guard_service',
            'lease_lifecycle_service',
            'readiness_projection_service',
            'command_integration_and_read_only_adapter',
            'feature_and_failure_mode_tests',
        ];

        $futureFiles = [
            'database/migrations/*_create_atlas_self_construction_reservations_table.php',
            'database/migrations/*_create_atlas_self_construction_reservation_events_table.php',
            'app/Services/Ai/SelfConstruction/Reservations/DurableReservationRepository.php',
            'app/Services/Ai/SelfConstruction/Reservations/DurableReservationCollisionGuard.php',
            'app/Services/Ai/SelfConstruction/Reservations/DurableReservationLeaseLifecycle.php',
            'app/Services/Ai/SelfConstruction/Reservations/DurableReservationReadinessProjection.php',
            'tests/Feature/Ai/SelfConstruction/DurableReservationRepositoryTest.php',
            'tests/Feature/Ai/SelfConstruction/DurableReservationConcurrencyTest.php',
        ];

        $requiredGates = [
            'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
            'php artisan test tests/Feature/Ai/SelfConstruction/DurableReservationRepositoryTest.php',
            'php artisan test tests/Feature/Ai/SelfConstruction/DurableReservationConcurrencyTest.php',
            'php artisan atlas:ai:self-construction --traceability --json',
            'php artisan atlas:engineering:knowledge docs-health --json',
            'php artisan atlas:ai:architecture-validate --json',
            'git diff --check',
        ];

        $runtimeBuildPacket = [
            'packet_id' => 'RUNTIME-BUILD-PACKET-DURABLE-RESERVATION-LEDGER-0001',
            'build_status' => 'blocked_until_signed_approval_preflight_and_runtime_scope_approval',
            'source_blueprint_count' => count($sourceBlueprints),
            'source_blueprints' => $sourceBlueprints,
            'slice_count' => count($implementationSlices),
            'implementation_slices' => $implementationSlices,
            'future_file_count' => count($futureFiles),
            'future_files' => $futureFiles,
            'required_gate_count' => count($requiredGates),
            'required_gates' => $requiredGates,
            'required_evidence' => [
                'source_blueprint_hashes',
                'implementation_slice_statuses',
                'changed_files',
                'migration_dry_run_or_rollback_notes',
                'test_outputs',
                'scope_validation_output',
                'residual_risk_summary',
            ],
            'stop_conditions' => [
                'missing_signed_approval',
                'blueprint_hash_drift',
                'hot_forbidden_scope_touched',
                'migration_rollback_undefined',
                'repository_tests_missing',
                'collision_tests_missing',
                'readiness_projection_not_connected_to_packet_queue_and_multi_session_gate',
            ],
            'forbidden_scopes' => $this->hotForbiddenFiles(),
            'implementation_allowed_now' => false,
            'runtime_file_creation_allowed' => false,
            'migration_creation_allowed' => false,
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_durable_reservation_runtime_build_packet.v1',
            'status' => 'durable_reservation_runtime_build_packet_ready',
            'mode' => 'read_only_durable_reservation_runtime_build_packet',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'implementation_allowed_now' => false,
            'runtime_file_creation_allowed' => false,
            'migration_creation_allowed' => false,
            'claim_allowed_now' => false,
            'storage_write_allowed' => false,
            'dispatch_allowed' => false,
            'runtime_build_packet' => $runtimeBuildPacket,
            'build_packet_hash' => ($this->stableHash)($runtimeBuildPacket),
            'non_execution_guarantees' => [
                'durable_reservation_runtime_build_packet_does_not_create_migrations',
                'durable_reservation_runtime_build_packet_does_not_create_php_files',
                'durable_reservation_runtime_build_packet_does_not_write_storage',
                'durable_reservation_runtime_build_packet_does_not_persist_claim',
                'durable_reservation_runtime_build_packet_does_not_dispatch_work',
            ],
            'human_summary' => 'Durable reservation runtime build packet is ready: source blueprints, implementation slices, future files, gates and evidence are fixed, but runtime creation remains blocked.',
        ];
    }
}
