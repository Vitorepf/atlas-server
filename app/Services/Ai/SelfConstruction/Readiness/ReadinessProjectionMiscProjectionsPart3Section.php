<?php

namespace App\Services\Ai\SelfConstruction\Readiness;

use App\Models\AtlasSelfConstructionAgentWakeupItem;
use Illuminate\Support\Facades\Schema;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;

/**
 * GOD-DEBULK extracted projection family from AtlasSelfConstructionReadinessService.
 * Pure read-only projections. The mother is passed as $parent so cross-family sibling
 * calls route back through the preserved public mother surface (ReflectionMethod /
 * method_exists probes on the mother stay intact).
 */
final class ReadinessProjectionMiscProjectionsPart3Section
{
    public function __construct(
        private readonly AtlasSelfConstructionReadinessService $parent,
    ) {}

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function multiSessionReadinessGate(array $options = []): array
    {
        $queuePayload = $this->parent->packetQueue($options);
        $parallelPlan = $this->parent->parallelSessionPlan($options);
        $collisionMatrix = $this->parent->collisionMatrix($options);
        $unlockPlan = $this->parent->dependencyUnlockPlan($options);
        $reservationPreview = $this->parent->reservationLedgerPreview($options);
        $reservationStatus = $this->parent->reservationStatus($options);

        $assignable = (int) data_get($parallelPlan, 'plan.preview_assignable_count', 0);
        $safePairs = (int) data_get($collisionMatrix, 'matrix.safe_pair_count', 0);
        $reservationDurable = (bool) data_get($reservationStatus, 'ledger.ledger_available', false);

        $blockingReasons = [];
        if ($assignable < 2) {
            $blockingReasons[] = 'less_than_two_preview_assignable_packets';
        }
        if (! $reservationDurable) {
            $blockingReasons[] = 'durable_reservation_ledger_missing';
        }

        $decision = $blockingReasons === []
            ? 'ready_for_multi_session_preview'
            : ($assignable >= 2 ? 'parallel_preview_ready_but_not_durable' : ($assignable >= 1 ? 'preview_only_single_session' : 'blocked_for_multi_session'));

        $gate = [
            'gate_id' => 'MULTI-SESSION-READINESS-GATE-SELF-CONSTRUCTION-0001',
            'decision' => $decision,
            'multi_session_allowed' => false,
            'parallel_preview_allowed' => $assignable >= 2,
            'single_session_preview_allowed' => $assignable >= 1,
            'durable_dispatch_allowed' => false,
            'execution_allowed' => false,
            'claim_persisted' => false,
            'reservation_persisted' => $reservationDurable,
            'inspected_hashes' => [
                'queue_hash' => data_get($queuePayload, 'queue_hash'),
                'parallel_plan_hash' => data_get($parallelPlan, 'plan_hash'),
                'collision_matrix_hash' => data_get($collisionMatrix, 'matrix_hash'),
                'dependency_unlock_plan_hash' => data_get($unlockPlan, 'plan_hash'),
                'reservation_preview_hash' => data_get($reservationPreview, 'reservation_hash'),
                'reservation_status_hash' => data_get($reservationStatus, 'ledger_hash'),
            ],
            'counts' => [
                'queue_entries' => data_get($queuePayload, 'queue.entry_count'),
                'preview_assignable_packets' => $assignable,
                'safe_parallel_pairs' => $safePairs,
                'blocked_packets' => data_get($queuePayload, 'queue.blocked_count'),
                'claimed_packets' => data_get($queuePayload, 'queue.claimed_count'),
                'withheld_packets' => data_get($queuePayload, 'queue.withheld_count'),
                'unlock_edges' => data_get($unlockPlan, 'plan.unlock_edge_count'),
            ],
            'non_blocking_warnings' => array_values(array_filter([
                ((int) data_get($queuePayload, 'queue.withheld_count', 0) > 0) ? 'hot_external_work_withheld_from_cold_lane' : null,
            ])),
            'blocking_reasons' => $blockingReasons,
            'safe_next_instruction' => $assignable >= 2
                ? 'continue_parallel_preview_with_packet_scoped_bootstrap'
                : ($assignable >= 1
                    ? 'continue_one_session_with_ai_session_bootstrap'
                    : 'do_not_continue_until_queue_has_assignable_packet'),
        ];

        return [
            'schema_version' => 'atlas.self_construction_multi_session_readiness_gate.v1',
            'status' => 'multi_session_readiness_gate_ready',
            'mode' => 'read_only_multi_session_readiness_gate',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'multi_session_allowed' => false,
            'parallel_preview_allowed' => $assignable >= 2,
            'single_session_preview_allowed' => $assignable >= 1,
            'dispatch_allowed' => false,
            'gate' => $gate,
            'gate_hash' => ReadinessHash::stable($gate),
            'non_execution_guarantees' => [
                'multi_session_readiness_gate_does_not_start_sessions',
                'multi_session_readiness_gate_does_not_persist_claim',
                'multi_session_readiness_gate_does_not_write_ledger',
                'multi_session_readiness_gate_does_not_enable_execution',
            ],
            'human_summary' => 'Multi-session readiness gate is ready: current state allows one preview session, while durable multi-session dispatch remains blocked.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function agentWakeupQueue(array $options = []): array
    {
        if (! Schema::hasTable('atlas_self_construction_agent_wakeup_items')) {
            $queue = [
                'status' => 'blocked',
                'blocking_reasons' => ['agent_control_plane_wakeup_queue_schema_missing'],
                'required_migration' => 'database/migrations/2026_05_12_010000_create_atlas_self_construction_agent_control_plane_tables.php',
                'wakeup_items_table_ready' => false,
            ];

            return [
                'schema_version' => 'atlas.self_construction_agent_wakeup_queue.v1',
                'status' => 'blocked',
                'mode' => 'read_only_agent_wakeup_queue_projection',
                'execution_allowed' => false,
                'dispatch_allowed' => false,
                'ledger_write_allowed' => false,
                'runtime_write_allowed' => false,
                'wakeup_queue' => $queue,
                'wakeup_queue_hash' => ReadinessHash::stable($queue),
                'human_summary' => 'Agent wakeup queue is blocked until the Agent Control Plane wakeup queue table exists.',
            ];
        }

        $livenessPayload = $this->parent->agentRunLiveness($options);
        $runtimeItems = AtlasSelfConstructionAgentWakeupItem::query()
            ->orderBy('scheduled_for')
            ->limit(50)
            ->get()
            ->map(fn (AtlasSelfConstructionAgentWakeupItem $item): array => [
                'wakeup_item_id' => $item->id,
                'wakeup_key' => $item->wakeup_key,
                'run_id' => $item->agent_run_id,
                'packet_id' => $item->packet_id,
                'actor' => $item->actor,
                'provider' => $item->provider,
                'reason' => $item->reason,
                'priority' => $item->priority,
                'status' => $item->status,
                'scheduled_for' => $item->scheduled_for?->toIso8601String(),
            ])
            ->values()
            ->all();

        $projectedItems = collect((array) data_get($livenessPayload, 'liveness.runs', []))
            ->filter(fn (array $run): bool => (bool) data_get($run, 'attention_needed')
                || data_get($run, 'derived_liveness') === 'terminal')
            ->map(function (array $run): array {
                $derived = (string) data_get($run, 'derived_liveness', 'unknown');
                $priority = match ($derived) {
                    'expired_lease_no_heartbeat' => 'high',
                    'stale_heartbeat' => 'medium',
                    'terminal' => 'normal',
                    default => 'low',
                };

                return [
                    'projected_wakeup_key' => 'WAKEUP-PROJECTED-'.strtoupper(substr(hash('sha256', (string) data_get($run, 'run_key').'|'.$derived), 0, 24)),
                    'run_id' => data_get($run, 'run_id'),
                    'run_key' => data_get($run, 'run_key'),
                    'packet_id' => data_get($run, 'packet_id'),
                    'actor' => data_get($run, 'actor'),
                    'provider' => data_get($run, 'provider'),
                    'reason' => match ($derived) {
                        'terminal' => 'integration_or_archive_review',
                        'stale_heartbeat' => 'agent_status_check',
                        'expired_lease_no_heartbeat' => 'release_or_reclaim_review',
                        default => 'runtime_signal_investigation',
                    },
                    'priority' => $priority,
                    'source_liveness' => $derived,
                    'next_required_action' => data_get($run, 'next_required_action'),
                    'dispatch_allowed' => false,
                    'runtime_write_allowed' => false,
                ];
            })
            ->values()
            ->all();

        $queue = [
            'status' => 'projected',
            'counts' => [
                'runtime_items' => count($runtimeItems),
                'projected_items' => count($projectedItems),
                'attention_needed' => data_get($livenessPayload, 'liveness.counts.attention_needed', 0),
            ],
            'runtime_items' => $runtimeItems,
            'projected_items' => $projectedItems,
            'policy' => [
                'projection_is_read_only' => true,
                'does_not_schedule_jobs' => true,
                'does_not_start_providers' => true,
                'does_not_claim_or_release_packets' => true,
                'wakeup_writer_requires_future_signed_policy' => true,
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_agent_wakeup_queue.v1',
            'status' => 'agent_wakeup_queue_ready',
            'mode' => 'read_only_agent_wakeup_queue_projection',
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'runtime_write_allowed' => false,
            'wakeup_queue' => $queue,
            'wakeup_queue_hash' => ReadinessHash::stable($queue),
            'non_execution_guarantees' => [
                'agent_wakeup_queue_does_not_start_providers',
                'agent_wakeup_queue_does_not_claim_packets',
                'agent_wakeup_queue_does_not_release_packets',
                'agent_wakeup_queue_does_not_schedule_jobs',
            ],
            'human_summary' => 'Agent wakeup queue projection is ready: Atlas can see which runs need resume, status check, reclaim review or integration review without dispatching providers.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function singleSessionInstructionPacket(array $options = []): array
    {
        $gatePayload = $this->parent->multiSessionReadinessGate($options);
        $bootstrapPayload = $this->parent->aiSessionBootstrap($options);
        $runbookPayload = $this->parent->packetRunbook($options);
        $assignmentPayload = $this->parent->assignmentPreview($options);
        $assignment = (array) data_get($bootstrapPayload, 'bootstrap', []);

        $instruction = [
            'instruction_id' => 'SINGLE-SESSION-INSTRUCTION-SELF-CONSTRUCTION-0001',
            'selected_packet_id' => data_get($assignment, 'selected_packet_id'),
            'safe_next_instruction' => data_get($gatePayload, 'gate.safe_next_instruction'),
            'operator_instruction' => 'Continue exactly one Self-Construction session using the selected packet, run required gates, report evidence and stop on any scope or hot-file blocker.',
            'one_line_prompt' => 'Continue Self-Construction using php artisan atlas:ai:self-construction --single-session-instruction-packet --json, follow the selected packet, and do not touch hot Voice/Kernel scopes.',
            'required_first_commands' => (array) data_get($assignment, 'required_first_commands', []),
            'selected_bootstrap_hash' => data_get($bootstrapPayload, 'bootstrap_hash'),
            'readiness_gate_hash' => data_get($gatePayload, 'gate_hash'),
            'runbook_hash' => data_get($runbookPayload, 'runbook_hash'),
            'allowed_files' => (array) data_get($assignmentPayload, 'assignment.allowed_files', []),
            'forbidden_hot_scopes' => (array) data_get($assignment, 'forbidden_hot_scopes', []),
            'required_gates' => (array) data_get($runbookPayload, 'runbook.required_gates', []),
            'required_evidence' => (array) data_get($runbookPayload, 'runbook.required_evidence', []),
            'stop_conditions' => array_values(array_unique(array_merge(
                (array) data_get($assignment, 'stop_conditions', []),
                (array) data_get($gatePayload, 'gate.blocking_reasons', []),
            ))),
            'execution_allowed' => false,
            'claim_persisted' => false,
            'reservation_persisted' => false,
            'dispatch_allowed' => false,
            'completion_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_single_session_instruction_packet.v1',
            'status' => 'single_session_instruction_packet_ready',
            'mode' => 'read_only_single_session_instruction_packet',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'claim_persisted' => false,
            'reservation_persisted' => false,
            'dispatch_allowed' => false,
            'instruction' => $instruction,
            'instruction_hash' => ReadinessHash::stable($instruction),
            'non_execution_guarantees' => [
                'single_session_instruction_packet_does_not_start_session',
                'single_session_instruction_packet_does_not_persist_claim',
                'single_session_instruction_packet_does_not_write_ledger',
                'single_session_instruction_packet_does_not_enable_execution',
            ],
            'human_summary' => 'Single-session instruction packet is ready: one AI can continue from a canonical instruction while claims, dispatch and execution remain disabled.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function coldLaneCertification(array $options = []): array
    {
        $surfaceMatrix = $this->parent->surfaceMatrix($options);
        $phaseLedger = $this->parent->phaseLedger($options);
        $externalBlockers = $this->parent->externalBlockers($options);
        $nextAction = $this->parent->nextAction($options);

        $certification = [
            'id' => 'COLD-LANE-CERTIFICATION-SELF-CONSTRUCTION-PHASE-5',
            'status' => 'certified_with_external_blockers',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'certified_scope' => [
                'app/Console/Commands/AtlasAiSelfConstructionCommand.php',
                'app/Services/Ai/SelfConstruction/AtlasSelfConstructionReadinessService.php',
                'tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'docs/engineering-knowledge-base/atlas-ai-self-construction-os.md',
                'docs/engineering-knowledge-base/self-construction/runtime-implementation-roadmap.md',
            ],
            'certified_properties' => [
                'self_construction_surfaces_are_read_only',
                'phase_status_is_explicit',
                'next_action_is_human_signature_review',
                'external_hot_blockers_are_reported_not_edited',
                'execution_and_completion_remain_blocked',
            ],
            'required_local_gates' => [
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'php artisan atlas:ai:self-construction --surface-matrix --json',
                'php artisan atlas:ai:self-construction --phase-ledger --json',
                'php artisan atlas:ai:self-construction --external-blockers --json',
                'php artisan atlas:ai:self-construction --next-action --json',
                'git diff --check',
            ],
            'global_blockers_not_owned' => data_get($externalBlockers, 'blockers'),
        ];

        return [
            'schema_version' => 'atlas.self_construction_cold_lane_certification.v1',
            'status' => 'cold_lane_certified_with_external_blockers',
            'mode' => 'read_only_cold_lane_certification',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'surface_count' => data_get($surfaceMatrix, 'surface_count'),
            'phase_ledger_status' => data_get($phaseLedger, 'status'),
            'next_action_id' => data_get($nextAction, 'selected_action.id'),
            'external_blocker_count' => data_get($externalBlockers, 'blocker_count'),
            'certification' => $certification,
            'certification_hash' => ReadinessHash::stable($certification),
            'non_execution_guarantees' => [
                'cold_lane_certification_does_not_edit_hot_files',
                'cold_lane_certification_does_not_apply_patch',
                'cold_lane_certification_does_not_mark_completion',
                'cold_lane_certification_does_not_enable_execution',
            ],
            'human_summary' => 'Cold lane is certified read-only with external blockers reported; execution remains blocked.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function operatorChecklist(array $options = []): array
    {
        $certification = $this->parent->coldLaneCertification($options);
        $nextAction = $this->parent->nextAction($options);
        $externalBlockers = $this->parent->externalBlockers($options);

        $checklist = [
            [
                'id' => 'verify_cold_lane_hash',
                'order' => 1,
                'required' => true,
                'command' => 'php artisan atlas:ai:self-construction --cold-lane-certification --json',
                'expected' => 'status=cold_lane_certified_with_external_blockers and execution_allowed=false',
            ],
            [
                'id' => 'review_next_action',
                'order' => 2,
                'required' => true,
                'command' => 'php artisan atlas:ai:self-construction --next-action --json',
                'expected' => 'selected_action.id=request_human_signature_review',
            ],
            [
                'id' => 'confirm_hot_blockers_are_external',
                'order' => 3,
                'required' => true,
                'command' => 'php artisan atlas:ai:self-construction --external-blockers --json',
                'expected' => 'hot Voice, LiveKit and Kernel scanner deltas are reported, not edited',
            ],
            [
                'id' => 'run_focused_self_construction_tests',
                'order' => 4,
                'required' => true,
                'command' => 'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'expected' => 'all Self-Construction command surfaces pass',
            ],
            [
                'id' => 'run_global_governance_gates',
                'order' => 5,
                'required' => true,
                'command' => 'php artisan atlas:engineering:knowledge docs-health --json && php artisan atlas:ai:architecture-validate --json && git diff --check',
                'expected' => 'docs, architecture and whitespace gates stay green',
            ],
        ];

        $packet = [
            'id' => 'OPERATOR-CHECKLIST-SELF-CONSTRUCTION-PHASE-5',
            'status' => 'ready_for_human_signature_review',
            'next_action_id' => data_get($nextAction, 'selected_action.id'),
            'cold_lane_certification_hash' => data_get($certification, 'certification_hash'),
            'external_blocker_count' => data_get($externalBlockers, 'blocker_count'),
            'checklist' => $checklist,
        ];

        return [
            'schema_version' => 'atlas.self_construction_operator_checklist.v1',
            'status' => 'operator_checklist_ready',
            'mode' => 'read_only_operator_checklist',
            'execution_allowed' => false,
            'completion_allowed' => false,
            'checklist_count' => count($checklist),
            'checklist_packet' => $packet,
            'checklist_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'operator_checklist_does_not_edit_hot_files',
                'operator_checklist_does_not_apply_patch',
                'operator_checklist_does_not_sign_receipt',
                'operator_checklist_does_not_enable_execution',
            ],
            'human_summary' => 'Operator checklist is ready: it orders the next review steps without signing, patching or touching hot files.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function promotionBlockers(array $options = []): array
    {
        $phaseLedger = $this->parent->phaseLedger($options);
        $completionReadiness = $this->parent->completionReadiness($options);
        $residualRisk = $this->parent->residualRisk($options);
        $externalBlockers = $this->parent->externalBlockers($options);

        $blockers = [
            [
                'id' => 'human_signature_missing',
                'scope' => 'self_construction_phase_5',
                'severity' => 'blocks_execution',
                'source' => 'phase_ledger',
                'required_action' => 'Human owner must review and sign the scoped Decision Receipt before any execution.',
            ],
            [
                'id' => 'signed_execution_evidence_missing',
                'scope' => 'self_construction_completion',
                'severity' => 'blocks_completion',
                'source' => 'completion_readiness',
                'required_action' => 'Provide signed receipt, scoped diff, focused gates and final evidence packet before completion.',
            ],
            [
                'id' => 'residual_risk_open',
                'scope' => 'self_construction_promotion',
                'severity' => data_get($residualRisk, 'risk_summary.highest_severity'),
                'source' => 'residual_risk',
                'required_action' => 'Resolve or explicitly accept residual risks before promotion.',
            ],
        ];

        foreach ((array) data_get($externalBlockers, 'blockers', []) as $blocker) {
            $blockers[] = [
                'id' => (string) data_get($blocker, 'id'),
                'scope' => (string) data_get($blocker, 'scope'),
                'severity' => (string) data_get($blocker, 'severity'),
                'source' => 'external_blockers',
                'required_action' => (string) data_get($blocker, 'recommended_action'),
            ];
        }

        $packet = [
            'id' => 'PROMOTION-BLOCKERS-SELF-CONSTRUCTION-PHASE-5',
            'phase_ledger_status' => data_get($phaseLedger, 'status'),
            'completion_status' => data_get($completionReadiness, 'status'),
            'residual_risk_status' => data_get($residualRisk, 'status'),
            'external_blocker_count' => data_get($externalBlockers, 'blocker_count'),
            'blockers' => $blockers,
        ];

        return [
            'schema_version' => 'atlas.self_construction_promotion_blockers.v1',
            'status' => 'promotion_blockers_open',
            'mode' => 'read_only_promotion_blockers',
            'execution_allowed' => false,
            'promotion_allowed' => false,
            'completion_allowed' => false,
            'blocker_count' => count($blockers),
            'blocker_packet' => $packet,
            'blocker_hash' => ReadinessHash::stable($packet),
            'non_execution_guarantees' => [
                'promotion_blockers_does_not_edit_hot_files',
                'promotion_blockers_does_not_apply_patch',
                'promotion_blockers_does_not_sign_receipt',
                'promotion_blockers_does_not_enable_execution',
            ],
            'human_summary' => 'Promotion blockers are consolidated read-only; execution, promotion and completion remain blocked.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function readinessDigest(array $options = []): array
    {
        $snapshot = $this->parent->snapshot($options);
        $phaseLedger = $this->parent->phaseLedger($options);
        $surfaceMatrix = $this->parent->surfaceMatrix($options);
        $promotionBlockers = $this->parent->promotionBlockers($options);
        $operatorChecklist = $this->parent->operatorChecklist($options);

        $digest = [
            'id' => 'READINESS-DIGEST-SELF-CONSTRUCTION-PHASE-5',
            'runtime_phase' => data_get($snapshot, 'summary.runtime_phase'),
            'current_phase' => data_get($phaseLedger, 'current_phase'),
            'next_action_id' => data_get($phaseLedger, 'next_action_id'),
            'surface_count' => data_get($surfaceMatrix, 'surface_count'),
            'blocker_count' => data_get($promotionBlockers, 'blocker_count'),
            'operator_checklist_count' => data_get($operatorChecklist, 'checklist_count'),
            'execution_allowed' => false,
            'promotion_allowed' => false,
            'completion_allowed' => false,
            'required_next_commands' => [
                'php artisan atlas:ai:self-construction --readiness-digest --json',
                'php artisan atlas:ai:self-construction --operator-checklist --json',
                'php artisan atlas:ai:self-construction --promotion-blockers --json',
                'php artisan test tests/Feature/Ai/AtlasAiSelfConstructionCommandTest.php',
                'git diff --check',
            ],
        ];

        return [
            'schema_version' => 'atlas.self_construction_readiness_digest.v1',
            'status' => 'readiness_digest_ready',
            'mode' => 'read_only_readiness_digest',
            'execution_allowed' => false,
            'promotion_allowed' => false,
            'completion_allowed' => false,
            'digest' => $digest,
            'digest_hash' => ReadinessHash::stable($digest),
            'non_execution_guarantees' => [
                'readiness_digest_does_not_edit_hot_files',
                'readiness_digest_does_not_apply_patch',
                'readiness_digest_does_not_sign_receipt',
                'readiness_digest_does_not_enable_execution',
            ],
            'human_summary' => 'Readiness digest is ready: compact handoff state is available without execution, signing or hot-file edits.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function governanceScorecard(array $options = []): array
    {
        $digest = $this->parent->readinessDigest($options);
        $surfaceMatrix = $this->parent->surfaceMatrix($options);
        $promotionBlockers = $this->parent->promotionBlockers($options);
        $coldLaneCertification = $this->parent->coldLaneCertification($options);

        $criteria = [
            [
                'id' => 'documentation_complete',
                'weight' => 20,
                'score' => data_get($digest, 'digest.runtime_phase') === 'phase_2_read_only_gap_report' ? 20 : 0,
                'evidence' => 'required Self-Construction docs are present and indexed',
            ],
            [
                'id' => 'command_surface_complete',
                'weight' => 20,
                'score' => data_get($surfaceMatrix, 'surface_count') >= 22 ? 20 : 10,
                'evidence' => 'surface matrix declares read-only command surfaces',
            ],
            [
                'id' => 'execution_locked',
                'weight' => 20,
                'score' => data_get($digest, 'execution_allowed') === false ? 20 : 0,
                'evidence' => 'execution, promotion and completion flags remain false',
            ],
            [
                'id' => 'blockers_explicit',
                'weight' => 20,
                'score' => data_get($promotionBlockers, 'blocker_count') > 0 ? 20 : 0,
                'evidence' => 'promotion blockers are enumerated with required actions',
            ],
            [
                'id' => 'cold_lane_certified',
                'weight' => 20,
                'score' => data_get($coldLaneCertification, 'status') === 'cold_lane_certified_with_external_blockers' ? 20 : 0,
                'evidence' => 'cold lane certification hash exists and external blockers are separated',
            ],
        ];

        $score = array_sum(array_column($criteria, 'score'));
        $scorecard = [
            'id' => 'GOVERNANCE-SCORECARD-SELF-CONSTRUCTION-PHASE-5',
            'score' => $score,
            'max_score' => array_sum(array_column($criteria, 'weight')),
            'rating' => $score >= 90 ? 'strong_governed_readiness' : 'needs_governance_repair',
            'promotion_allowed' => false,
            'execution_allowed' => false,
            'completion_allowed' => false,
            'criteria' => $criteria,
            'blocking_reason' => 'Scorecard is advisory only; human signature and execution evidence remain mandatory.',
        ];

        return [
            'schema_version' => 'atlas.self_construction_governance_scorecard.v1',
            'status' => 'governance_scorecard_ready',
            'mode' => 'read_only_governance_scorecard',
            'execution_allowed' => false,
            'promotion_allowed' => false,
            'completion_allowed' => false,
            'scorecard' => $scorecard,
            'scorecard_hash' => ReadinessHash::stable($scorecard),
            'non_execution_guarantees' => [
                'governance_scorecard_does_not_edit_hot_files',
                'governance_scorecard_does_not_apply_patch',
                'governance_scorecard_does_not_sign_receipt',
                'governance_scorecard_does_not_enable_execution',
            ],
            'human_summary' => 'Governance scorecard is ready: readiness is scored while execution, promotion and completion stay blocked.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function integrityManifest(array $options = []): array
    {
        $entries = [
            [
                'id' => 'readiness_digest',
                'schema' => 'atlas.self_construction_readiness_digest.v1',
                'hash' => data_get($this->parent->readinessDigest($options), 'digest_hash'),
            ],
            [
                'id' => 'governance_scorecard',
                'schema' => 'atlas.self_construction_governance_scorecard.v1',
                'hash' => data_get($this->parent->governanceScorecard($options), 'scorecard_hash'),
            ],
            [
                'id' => 'promotion_blockers',
                'schema' => 'atlas.self_construction_promotion_blockers.v1',
                'hash' => data_get($this->parent->promotionBlockers($options), 'blocker_hash'),
            ],
            [
                'id' => 'operator_checklist',
                'schema' => 'atlas.self_construction_operator_checklist.v1',
                'hash' => data_get($this->parent->operatorChecklist($options), 'checklist_hash'),
            ],
            [
                'id' => 'cold_lane_certification',
                'schema' => 'atlas.self_construction_cold_lane_certification.v1',
                'hash' => data_get($this->parent->coldLaneCertification($options), 'certification_hash'),
            ],
            [
                'id' => 'handoff_packet',
                'schema' => 'atlas.self_construction_handoff_packet.v1',
                'hash' => data_get($this->parent->handoffPacket($options), 'handoff_hash'),
            ],
        ];

        $manifest = [
            'id' => 'INTEGRITY-MANIFEST-SELF-CONSTRUCTION-PHASE-5',
            'status' => 'ready',
            'entry_count' => count($entries),
            'entries' => $entries,
            'execution_allowed' => false,
            'promotion_allowed' => false,
            'completion_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_integrity_manifest.v1',
            'status' => 'integrity_manifest_ready',
            'mode' => 'read_only_integrity_manifest',
            'execution_allowed' => false,
            'promotion_allowed' => false,
            'completion_allowed' => false,
            'entry_count' => count($entries),
            'manifest' => $manifest,
            'manifest_hash' => ReadinessHash::stable($manifest),
            'non_execution_guarantees' => [
                'integrity_manifest_does_not_edit_hot_files',
                'integrity_manifest_does_not_apply_patch',
                'integrity_manifest_does_not_sign_receipt',
                'integrity_manifest_does_not_enable_execution',
            ],
            'human_summary' => 'Integrity manifest is ready: governed packet hashes are bundled for audit without execution.',
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function continuationToken(array $options = []): array
    {
        $digest = $this->parent->readinessDigest($options);
        $manifest = $this->parent->integrityManifest($options);

        $token = [
            'id' => 'CONTINUATION-TOKEN-SELF-CONSTRUCTION-PHASE-5',
            'resume_mode' => 'read_only_cold_lane',
            'current_phase' => data_get($digest, 'digest.current_phase'),
            'next_action_id' => data_get($digest, 'digest.next_action_id'),
            'manifest_hash' => data_get($manifest, 'manifest_hash'),
            'digest_hash' => data_get($digest, 'digest_hash'),
            'must_run_first' => [
                'git status --short',
                'git diff --stat',
                'git diff --name-only',
                'php artisan atlas:ai:self-construction --continuation-token --json',
            ],
            'must_not_touch' => [
                'runtimes/python/voice_realtime/**',
                'app/Services/Ai/Voice/**',
                'app/Services/Ai/Kernel/Architecture/KernelArchitectureStaticScanner.php',
                'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md',
            ],
            'allowed_next_cold_blocks' => [
                'read_only_report_surface',
                'test_only_guardrail',
                'documentation_index_entry',
                'operator_handoff_refinement',
            ],
            'execution_allowed' => false,
            'promotion_allowed' => false,
            'completion_allowed' => false,
        ];

        return [
            'schema_version' => 'atlas.self_construction_continuation_token.v1',
            'status' => 'continuation_token_ready',
            'mode' => 'read_only_continuation_token',
            'execution_allowed' => false,
            'promotion_allowed' => false,
            'completion_allowed' => false,
            'token' => $token,
            'token_hash' => ReadinessHash::stable($token),
            'non_execution_guarantees' => [
                'continuation_token_does_not_edit_hot_files',
                'continuation_token_does_not_apply_patch',
                'continuation_token_does_not_sign_receipt',
                'continuation_token_does_not_enable_execution',
            ],
            'human_summary' => 'Continuation token is ready: the next operator can resume the cold lane from a compact audited state.',
        ];
    }
}
