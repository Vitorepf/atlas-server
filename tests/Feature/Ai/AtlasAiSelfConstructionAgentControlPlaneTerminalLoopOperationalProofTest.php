<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTerminalLoopOperationalProofService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneTerminalLoopOperationalProofTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_operational_proof_runs_one_bounded_terminal_loop_cycle(): void
    {
        $proof = (new AgentControlPlaneTerminalLoopOperationalProofService)->prove([
            'actor' => 'proof-agent',
            'proof_id' => 'proof-one',
        ]);

        $this->assertSame(AgentControlPlaneTerminalLoopOperationalProofService::SCHEMA_VERSION, $proof['schema_version']);
        $this->assertSame('passed', $proof['status']);
        $this->assertSame('proof-one', $proof['proof_id']);
        $this->assertTrue($proof['invariants_all_true']);
        $this->assertSame(0, $proof['violation_count']);
        $this->assertTrue(data_get($proof, 'operational_readiness_matrix.all_true'));
        $this->assertSame(11, data_get($proof, 'operational_readiness_matrix.row_count'));
        $this->assertSame(11, data_get($proof, 'operational_readiness_matrix.passed_row_count'));
        $this->assertSame(0, data_get($proof, 'operational_readiness_matrix.failed_row_count'));
        $this->assertSame([], data_get($proof, 'operational_readiness_matrix.failed_rows'));
        $this->assertSame('auto_replenishment_completed', $proof['prepare_event']);
        $this->assertSame('available', $proof['auto_replenishment_status']);
        $this->assertSame(1, $proof['auto_replenishment_generated_task_count']);
        $this->assertSame('ready_for_worker', $proof['bootstrap_status']);
        $this->assertTrue($proof['one_shot_worker_packet_ready']);
        $this->assertSame('claimed', $proof['claim_event']);
        $this->assertSame('completed_dry_run', $proof['completion_event']);
        $this->assertSame('fleet_evidence_rollup_green', $proof['post_cycle_evidence_rollup_status']);
        $this->assertSame(3, $proof['post_cycle_completed_dry_run_task_count']);
        $this->assertSame(3, $proof['post_cycle_valid_completion_evidence_count']);
        $this->assertSame(0, $proof['post_cycle_claimed_task_count']);
        $this->assertSame(0, $proof['post_cycle_active_lease_count']);
        $this->assertSame(0, $proof['post_cycle_recoverable_lease_count']);
        $this->assertSame(0, data_get($proof, 'post_cycle_cleanup_state.claimed_task_count'));
        $this->assertSame(0, data_get($proof, 'post_cycle_cleanup_state.active_lease_count'));
        $this->assertSame(0, data_get($proof, 'post_cycle_cleanup_state.recoverable_lease_count'));
        $this->assertSame('cycle_evidence_review_ready', $proof['post_cycle_cycle_supervisor_status']);
        $this->assertSame('review_evidence', $proof['post_cycle_cycle_supervisor_cycle_state']);
        $this->assertSame('review_completed_dry_run_evidence_and_rerun_digest', $proof['post_cycle_cycle_supervisor_next_command_purpose']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $proof['post_cycle_cycle_supervisor_hash']);
        $this->assertSame('terminal_loop_end_to_end_contract_available', $proof['post_cycle_end_to_end_contract_status']);
        $this->assertTrue($proof['post_cycle_end_to_end_contract_all_required_surfaces_present']);
        $this->assertSame([], $proof['post_cycle_end_to_end_contract_failed_check_ids']);
        $this->assertContains('auto_replenishment', $proof['post_cycle_end_to_end_contract_covered_capabilities']);
        $this->assertContains('validation', $proof['post_cycle_end_to_end_contract_covered_capabilities']);
        $this->assertContains('leases', $proof['post_cycle_end_to_end_contract_covered_capabilities']);
        $this->assertContains('evidence', $proof['post_cycle_end_to_end_contract_covered_capabilities']);
        $this->assertContains('retomada', $proof['post_cycle_end_to_end_contract_covered_capabilities']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $proof['post_cycle_end_to_end_contract_hash']);
        $this->assertSame('passed', data_get($proof, 'recovery_resume_proof.status'));
        $this->assertSame('recoverable_released_task', data_get($proof, 'recovery_resume_proof.recoverability_before_recovery'));
        $this->assertSame('build_resume_packet_ready', data_get($proof, 'recovery_resume_proof.resume_packet_event'));
        $this->assertSame('requeue_released_task_via_recovery', data_get($proof, 'recovery_resume_proof.resume_safe_next_action'));
        $this->assertSame('recover_released_tasks', data_get($proof, 'recovery_resume_proof.recover_released_event'));
        $this->assertSame(1, data_get($proof, 'recovery_resume_proof.recover_released_count'));
        $this->assertSame('claimed', data_get($proof, 'recovery_resume_proof.resume_claim_event'));
        $this->assertNotSame(data_get($proof, 'recovery_resume_proof.interrupted_lease_id'), data_get($proof, 'recovery_resume_proof.resume_lease_id'));
        $this->assertSame('completed_dry_run', data_get($proof, 'recovery_resume_proof.resume_completion_event'));
        $this->assertTrue(data_get($proof, 'recovery_resume_proof.resume_completion_evidence_valid'));
        $this->assertSame('passed', data_get($proof, 'validation_rejection_proof.status'));
        $this->assertSame('complete_dry_run_blocked', data_get($proof, 'validation_rejection_proof.hash_mismatch_completion_event'));
        $this->assertSame('evidence_hash_mismatch', data_get($proof, 'validation_rejection_proof.hash_mismatch_completion_reason'));
        $this->assertContains('evidence_hash_mismatch', data_get($proof, 'validation_rejection_proof.hash_mismatch_completion_blockers'));
        $this->assertTrue(data_get($proof, 'validation_rejection_proof.hash_mismatch_blocked_by_hash'));
        $this->assertSame(str_repeat('b', 64), data_get($proof, 'validation_rejection_proof.hash_mismatch_operator_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($proof, 'validation_rejection_proof.hash_mismatch_computed_hash'));
        $this->assertFalse(data_get($proof, 'validation_rejection_proof.hash_mismatch_real_allowed'));
        $this->assertSame('complete_dry_run_blocked', data_get($proof, 'validation_rejection_proof.invalid_completion_event'));
        $this->assertSame('files_changed_outside_allowed_scope', data_get($proof, 'validation_rejection_proof.invalid_completion_reason'));
        $this->assertContains('files_changed_outside_allowed_scope', data_get($proof, 'validation_rejection_proof.invalid_completion_blockers'));
        $this->assertTrue(data_get($proof, 'validation_rejection_proof.invalid_completion_blocked_by_scope'));
        $this->assertFalse(data_get($proof, 'validation_rejection_proof.invalid_completion_real_allowed'));
        $this->assertSame('completed_dry_run', data_get($proof, 'validation_rejection_proof.valid_completion_event'));
        $this->assertTrue(data_get($proof, 'validation_rejection_proof.valid_completion_evidence_valid'));
        $this->assertSame('available', data_get($proof, 'fleet_concurrency_proof.status'));
        $this->assertTrue(data_get($proof, 'fleet_concurrency_proof.invariants_all_true'));
        $this->assertSame(0, data_get($proof, 'fleet_concurrency_proof.violation_count'));
        $this->assertSame(2, data_get($proof, 'fleet_concurrency_proof.agent_count'));
        $this->assertSame(2, data_get($proof, 'fleet_concurrency_proof.distinct_task_total'));
        $this->assertSame(2, data_get($proof, 'fleet_concurrency_proof.distinct_lease_total'));
        $this->assertSame(0, data_get($proof, 'fleet_concurrency_proof.write_set_collision_count'));
        $this->assertTrue(data_get($proof, 'fleet_concurrency_proof.cleanup_left_no_recoverable_artifacts'));
        $this->assertTrue(data_get($proof, 'fleet_concurrency_proof.runtime_safety_all_false'));
        $this->assertSame('passed', data_get($proof, 'partial_supply_launch_gate_proof.status'));
        $this->assertTrue(data_get($proof, 'partial_supply_launch_gate_proof.partial_supply_launch_blocked'));
        $this->assertTrue(data_get($proof, 'partial_supply_launch_gate_proof.replenishment_plan_ready'));
        $this->assertTrue(data_get($proof, 'partial_supply_launch_gate_proof.cycle_supervisor_replenishes_before_launch'));
        $this->assertSame(1, data_get($proof, 'partial_supply_launch_gate_proof.claimable_task_count'));
        $this->assertFalse(data_get($proof, 'partial_supply_launch_gate_proof.target_min_claimable_tasks_met'));
        $this->assertSame(0, data_get($proof, 'partial_supply_launch_gate_proof.post_cleanup_claimable_count'));
        $this->assertSame('passed', data_get($proof, 'partial_supply_bootstrap_gate_proof.status'));
        $this->assertSame('blocked', data_get($proof, 'partial_supply_bootstrap_gate_proof.bootstrap_status'));
        $this->assertSame('task_supply_below_target_blocked_before_claim', data_get($proof, 'partial_supply_bootstrap_gate_proof.claim_event'));
        $this->assertFalse(data_get($proof, 'partial_supply_bootstrap_gate_proof.runtime_claim_persisted'));
        $this->assertFalse(data_get($proof, 'partial_supply_bootstrap_gate_proof.one_shot_worker_packet_ready'));
        $this->assertSame('claimable', data_get($proof, 'partial_supply_bootstrap_gate_proof.queue_status_after_bootstrap'));
        $this->assertSame(0, data_get($proof, 'partial_supply_bootstrap_gate_proof.active_lease_count_after_bootstrap'));
        $this->assertTrue(data_get($proof, 'partial_supply_bootstrap_gate_proof.bootstrap_blocked_before_claim'));
        $this->assertSame(0, data_get($proof, 'partial_supply_bootstrap_gate_proof.post_cleanup_claimable_count'));
        $this->assertFalse($proof['completion_real_allowed']);
        $this->assertFalse($proof['provider_call_allowed']);
        $this->assertFalse($proof['token_spend_allowed']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $proof['completion_evidence_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $proof['completion_evidence_validation_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $proof['auto_replenishment_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $proof['one_shot_packet_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $proof['resume_packet_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $proof['recovery_resume_proof_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($proof, 'recovery_resume_proof.resume_contract_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($proof, 'recovery_resume_proof.resume_completion_evidence_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $proof['validation_rejection_proof_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($proof, 'validation_rejection_proof.invalid_completion_evidence_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($proof, 'validation_rejection_proof.valid_completion_evidence_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $proof['fleet_concurrency_proof_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($proof, 'fleet_concurrency_proof.certification_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $proof['partial_supply_launch_gate_proof_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $proof['partial_supply_bootstrap_gate_proof_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $proof['operational_readiness_matrix_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $proof['terminal_loop_operational_proof_hash']);
        $this->assertSame('atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1', data_get($proof, 'completion_audit_binding_packet.schema_version'));
        $this->assertSame('ready_for_read_only_completion_audit', data_get($proof, 'completion_audit_binding_packet.status'));
        $this->assertSame('agent_control_plane_terminal_loop_operational_proof', data_get($proof, 'completion_audit_binding_packet.audit_option_key'));
        $this->assertSame('/path/to/terminal-loop-operational-proof-binding.json', data_get($proof, 'completion_audit_binding_packet.expected_binding_artifact_path'));
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json', data_get($proof, 'completion_audit_binding_packet.expected_completion_audit_command'));
        $this->assertStringNotContainsString('--agent-control-plane-terminal-loop-operational-proof-json', data_get($proof, 'completion_audit_binding_packet.diagnostic_completion_audit_command'));
        $this->assertSame($proof['terminal_loop_operational_proof_hash'], data_get($proof, 'completion_audit_binding_packet.source_proof_hash'));
        $this->assertSame($proof['terminal_loop_operational_proof_hash'], data_get($proof, 'completion_audit_binding_packet.proof_payload.terminal_loop_operational_proof_hash'));
        $this->assertSame('passed', data_get($proof, 'completion_audit_binding_packet.proof_payload.status'));
        $this->assertTrue(data_get($proof, 'completion_audit_binding_packet.proof_payload.invariants_all_true'));
        $this->assertTrue(data_get($proof, 'completion_audit_binding_packet.proof_payload.operational_readiness_matrix.all_true'));
        $this->assertSame('cycle_evidence_review_ready', data_get($proof, 'completion_audit_binding_packet.proof_payload.post_cycle_cycle_supervisor.status'));
        $this->assertSame('review_evidence', data_get($proof, 'completion_audit_binding_packet.proof_payload.post_cycle_cycle_supervisor.cycle_state'));
        $this->assertSame('review_completed_dry_run_evidence_and_rerun_digest', data_get($proof, 'completion_audit_binding_packet.proof_payload.post_cycle_cycle_supervisor.next_command_purpose'));
        $this->assertSame($proof['post_cycle_cycle_supervisor_hash'], data_get($proof, 'completion_audit_binding_packet.proof_payload.post_cycle_cycle_supervisor.hash'));
        $this->assertSame('terminal_loop_end_to_end_contract_available', data_get($proof, 'completion_audit_binding_packet.proof_payload.post_cycle_end_to_end_contract.status'));
        $this->assertTrue(data_get($proof, 'completion_audit_binding_packet.proof_payload.post_cycle_end_to_end_contract.all_required_surfaces_present'));
        $this->assertSame([], data_get($proof, 'completion_audit_binding_packet.proof_payload.post_cycle_end_to_end_contract.failed_check_ids'));
        $this->assertContains('auto_replenishment', data_get($proof, 'completion_audit_binding_packet.proof_payload.post_cycle_end_to_end_contract.covered_capabilities'));
        $this->assertContains('retomada', data_get($proof, 'completion_audit_binding_packet.proof_payload.post_cycle_end_to_end_contract.covered_capabilities'));
        $this->assertSame($proof['post_cycle_end_to_end_contract_hash'], data_get($proof, 'completion_audit_binding_packet.proof_payload.post_cycle_end_to_end_contract.hash'));
        $this->assertSame(0, data_get($proof, 'completion_audit_binding_packet.proof_payload.post_cycle_cleanup_state.claimed_task_count'));
        $this->assertSame(0, data_get($proof, 'completion_audit_binding_packet.proof_payload.post_cycle_cleanup_state.active_lease_count'));
        $this->assertSame(0, data_get($proof, 'completion_audit_binding_packet.proof_payload.post_cycle_cleanup_state.recoverable_lease_count'));
        $this->assertFalse(data_get($proof, 'completion_audit_binding_packet.proof_payload.completion_real_allowed'));
        $this->assertFalse(data_get($proof, 'completion_audit_binding_packet.proof_payload.provider_call_allowed'));
        $this->assertFalse(data_get($proof, 'completion_audit_binding_packet.proof_payload.token_spend_allowed'));
        $this->assertFalse(data_get($proof, 'completion_audit_binding_packet.proof_payload.dispatch_allowed'));
        $this->assertFalse(data_get($proof, 'completion_audit_binding_packet.proof_payload.adapter_execution_allowed'));
        $this->assertFalse(data_get($proof, 'completion_audit_binding_packet.proof_payload.self_programming_allowed'));
        $this->assertFalse(data_get($proof, 'completion_audit_binding_packet.can_mark_completion_from_binding'));
        $this->assertFalse(data_get($proof, 'completion_audit_binding_packet.can_execute_from_binding'));
        $this->assertFalse(data_get($proof, 'completion_audit_binding_packet.can_dispatch_from_binding'));
        $this->assertFalse(data_get($proof, 'completion_audit_binding_packet.can_call_provider_from_binding'));
        $this->assertFalse(data_get($proof, 'completion_audit_binding_packet.can_spend_tokens_from_binding'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($proof, 'completion_audit_binding_packet.proof_payload_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $proof['completion_audit_binding_packet_hash']);
        $this->assertSame('atlas.self_construction.agent_control_plane_terminal_loop_operational_resume_packet.v1', data_get($proof, 'resume_packet.schema_version'));
        $this->assertSame('proof-one', data_get($proof, 'resume_packet.proof_id'));
        $this->assertTrue(data_get($proof, 'resume_packet.can_resume_without_chat_history'));
        $this->assertFalse(data_get($proof, 'resume_packet.safe_to_start_new_worker'));
        $this->assertSame('replenish_task_supply', data_get($proof, 'resume_packet.recommended_action'));
        $this->assertFalse(data_get($proof, 'resume_packet.resume_attention_required'));
        $this->assertSame('atlas.self_construction.agent_control_plane_terminal_loop_next_cycle_certificate.v1', data_get($proof, 'resume_packet.next_cycle_certificate.schema_version'));
        $this->assertSame('next_cycle_replenishment_required', data_get($proof, 'resume_packet.next_cycle_certificate.status'));
        $this->assertSame('replenish_and_claim_next_worker', data_get($proof, 'resume_packet.next_cycle_certificate.next_safe_command_key'));
        $this->assertTrue(data_get($proof, 'resume_packet.next_cycle_certificate.all_commands_lane_bound'));
        $this->assertFalse(data_get($proof, 'resume_packet.next_cycle_certificate.requires_provider'));
        $this->assertFalse(data_get($proof, 'resume_packet.next_cycle_certificate.requires_token_spend'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($proof, 'resume_packet.resume_rollup_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($proof, 'resume_packet.operator_handoff_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($proof, 'resume_packet.health_digest_hash'));
        $this->assertStringContainsString('--queue-tag=terminal_loop_operational_proof_proof-one', data_get($proof, 'resume_packet.resume_commands.inspect_health'));
        $this->assertStringContainsString('--queue-tag=terminal_loop_operational_proof_proof-one', data_get($proof, 'resume_packet.resume_commands.preview_next_worker_bootstrap'));
        $this->assertStringContainsString('--queue-tag=terminal_loop_operational_proof_proof-one', data_get($proof, 'resume_packet.resume_commands.replenish_and_claim_next_worker'));
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-health-digest-status', data_get($proof, 'resume_packet.resume_commands.inspect_health'));
        $this->assertStringContainsString('--terminal-worker-bootstrap-preview', data_get($proof, 'resume_packet.resume_commands.preview_next_worker_bootstrap'));
        $this->assertStringContainsString('--agent-control-plane-terminal-worker-bootstrap-status', data_get($proof, 'resume_packet.resume_commands.replenish_and_claim_next_worker'));
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-status', data_get($proof, 'resume_packet.resume_commands.run_bounded_operational_proof'));
        $this->assertStringContainsString('--proof-id=proof-one', data_get($proof, 'resume_packet.resume_commands.run_bounded_operational_proof'));
        $this->assertTrue(data_get($proof, 'invariants.before_digest_started_with_empty_lane'));
        $this->assertTrue(data_get($proof, 'invariants.auto_replenishment_generated_task'));
        $this->assertTrue(data_get($proof, 'invariants.bootstrap_ready_for_worker'));
        $this->assertTrue(data_get($proof, 'invariants.one_shot_worker_packet_ready'));
        $this->assertTrue(data_get($proof, 'invariants.completion_evidence_hash_matches_payload'));
        $this->assertTrue(data_get($proof, 'invariants.completion_evidence_files_within_scope'));
        $this->assertTrue(data_get($proof, 'invariants.recovery_release_recorded'));
        $this->assertTrue(data_get($proof, 'invariants.recovery_resume_packet_ready'));
        $this->assertTrue(data_get($proof, 'invariants.recovery_requeued_task_to_claimable'));
        $this->assertTrue(data_get($proof, 'invariants.recovery_reclaimed_with_fresh_lease'));
        $this->assertTrue(data_get($proof, 'invariants.recovery_completed_resumed_task_as_dry_run'));
        $this->assertTrue(data_get($proof, 'invariants.invalid_completion_evidence_rejected'));
        $this->assertTrue(data_get($proof, 'invariants.hash_mismatch_completion_evidence_rejected'));
        $this->assertTrue(data_get($proof, 'invariants.valid_completion_after_rejection_recorded'));
        $this->assertTrue(data_get($proof, 'invariants.fleet_concurrency_certification_available'));
        $this->assertTrue(data_get($proof, 'invariants.fleet_concurrency_claims_distinct'));
        $this->assertTrue(data_get($proof, 'invariants.fleet_concurrency_write_sets_disjoint'));
        $this->assertTrue(data_get($proof, 'invariants.fleet_concurrency_cleanup_green'));
        $this->assertTrue(data_get($proof, 'invariants.partial_supply_launch_blocked_before_worker_start'));
        $this->assertTrue(data_get($proof, 'invariants.partial_supply_replenishment_plan_ready'));
        $this->assertTrue(data_get($proof, 'invariants.partial_supply_cycle_supervisor_replenishes_before_launch'));
        $this->assertTrue(data_get($proof, 'invariants.partial_supply_probe_cleanup_green'));
        $this->assertTrue(data_get($proof, 'invariants.partial_supply_bootstrap_blocks_before_claim'));
        $this->assertTrue(data_get($proof, 'invariants.partial_supply_bootstrap_preserves_queue_and_leases'));
        $this->assertTrue(data_get($proof, 'invariants.no_claimed_task_after_completion'));
        $this->assertTrue(data_get($proof, 'invariants.post_cycle_cycle_supervisor_reviews_evidence'));
        $this->assertTrue(data_get($proof, 'invariants.post_cycle_end_to_end_contract_available'));
        $this->assertTrue(data_get($proof, 'invariants.post_cycle_end_to_end_contract_covers_required_loop_surfaces'));
        $this->assertTrue(data_get($proof, 'invariants.post_cycle_end_to_end_contract_is_read_only'));
        $this->assertTrue(data_get($proof, 'invariants.operational_readiness_matrix_all_true'));
        $this->assertTrue(data_get($proof, 'invariants.resume_packet_ready_for_next_terminal'));
        $this->assertTrue(data_get($proof, 'invariants.runtime_safety_all_false'));
    }

    public function test_cli_status_exposes_operational_proof_summary(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-terminal-loop-operational-proof-status' => true,
            '--actor' => 'cli-proof-agent',
            '--proof-id' => 'cli-proof-one',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction_agent_control_plane_terminal_loop_operational_proof_status.v1', $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
        $this->assertSame('passed', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.status'));
        $this->assertSame('cli-proof-one', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.proof_id'));
        $this->assertTrue(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.invariants_all_true'));
        $this->assertSame(0, data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.violation_count'));
        $this->assertSame('available', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.auto_replenishment_status'));
        $this->assertSame('ready_for_worker', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.bootstrap_status'));
        $this->assertTrue(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.one_shot_worker_packet_ready'));
        $this->assertSame('completed_dry_run', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.completion_event'));
        $this->assertSame('fleet_evidence_rollup_green', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.post_cycle_evidence_rollup_status'));
        $this->assertSame(0, data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.post_cycle_claimed_task_count'));
        $this->assertSame(0, data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.post_cycle_active_lease_count'));
        $this->assertSame(0, data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.post_cycle_recoverable_lease_count'));
        $this->assertSame(0, data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.post_cycle_cleanup_state.claimed_task_count'));
        $this->assertSame(0, data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.post_cycle_cleanup_state.active_lease_count'));
        $this->assertSame(0, data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.post_cycle_cleanup_state.recoverable_lease_count'));
        $this->assertSame('cycle_evidence_review_ready', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.post_cycle_cycle_supervisor_status'));
        $this->assertSame('review_evidence', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.post_cycle_cycle_supervisor_cycle_state'));
        $this->assertSame('review_completed_dry_run_evidence_and_rerun_digest', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.post_cycle_cycle_supervisor_next_command_purpose'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.post_cycle_cycle_supervisor_hash'));
        $this->assertSame('terminal_loop_end_to_end_contract_available', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.post_cycle_end_to_end_contract_status'));
        $this->assertTrue(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.post_cycle_end_to_end_contract_all_required_surfaces_present'));
        $this->assertSame([], data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.post_cycle_end_to_end_contract_failed_check_ids'));
        $this->assertContains('auto_replenishment', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.post_cycle_end_to_end_contract_covered_capabilities'));
        $this->assertContains('retomada', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.post_cycle_end_to_end_contract_covered_capabilities'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.post_cycle_end_to_end_contract_hash'));
        $this->assertSame('atlas.self_construction.agent_control_plane_terminal_loop_operational_resume_packet.v1', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_schema'));
        $this->assertSame('read_only_terminal_loop_resume_packet', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_mode'));
        $this->assertSame('cli-proof-one', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_proof_id'));
        $this->assertSame(['terminal_loop_operational_proof_cli-proof-one'], data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_queue_tags'));
        $this->assertTrue(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_can_resume_without_chat_history'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_safe_to_start_new_worker'));
        $this->assertSame('replenish_task_supply', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_recommended_action'));
        $this->assertSame('fleet_resume_rollup_clear', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_resume_rollup_status'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_resume_attention_required'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_resume_can_claim_from_rollup'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_resume_can_recover_from_rollup'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_resume_rollup_hash'));
        $this->assertSame('fleet_operator_handoff_replenish_before_launch', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_operator_handoff_status'));
        $this->assertSame('replenish_task_supply_then_recheck_digest', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_operator_handoff_next_action'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_operator_handoff_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_health_digest_hash'));
        $this->assertSame([
            'inspect_health',
            'preview_next_worker_bootstrap',
            'replenish_and_claim_next_worker',
            'run_bounded_operational_proof',
        ], data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_command_keys'));
        $this->assertStringContainsString('--queue-tag=terminal_loop_operational_proof_cli-proof-one', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_commands.inspect_health'));
        $this->assertStringContainsString('--queue-tag=terminal_loop_operational_proof_cli-proof-one', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_commands.preview_next_worker_bootstrap'));
        $this->assertStringContainsString('--queue-tag=terminal_loop_operational_proof_cli-proof-one', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_commands.replenish_and_claim_next_worker'));
        $this->assertStringContainsString('--proof-id=cli-proof-one', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_commands.run_bounded_operational_proof'));
        $this->assertSame('atlas.self_construction.agent_control_plane_terminal_loop_next_cycle_certificate.v1', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_next_cycle_certificate.schema_version'));
        $this->assertSame('next_cycle_replenishment_required', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_next_cycle_status'));
        $this->assertSame('replenish_and_claim_next_worker', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_next_safe_command_key'));
        $this->assertTrue(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_all_commands_lane_bound'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_next_cycle_certificate.requires_provider'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_next_cycle_certificate.requires_token_spend'));
        $this->assertSame([
            'do_not_call_provider',
            'do_not_spend_tokens',
            'do_not_dispatch_real_work',
            'do_not_mark_real_completion',
            'do_not_depend_on_chat_history',
        ], data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_forbidden_actions'));
        $this->assertSame(5, data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_forbidden_action_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.resume_packet_hash'));
        $this->assertSame('passed', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.recovery_resume_proof_status'));
        $this->assertSame('requeue_released_task_via_recovery', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.recovery_resume_safe_next_action'));
        $this->assertSame('recoverable_released_task', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.recovery_resume_recoverability_before_recovery'));
        $this->assertSame('claimed', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.recovery_resume_claim_event'));
        $this->assertSame('completed_dry_run', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.recovery_resume_completion_event'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.recovery_resume_proof_hash'));
        $this->assertSame('passed', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.validation_rejection_proof_status'));
        $this->assertSame('complete_dry_run_blocked', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.validation_rejection_hash_mismatch_event'));
        $this->assertSame('evidence_hash_mismatch', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.validation_rejection_hash_mismatch_reason'));
        $this->assertTrue(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.validation_rejection_hash_mismatch_blocked_by_hash'));
        $this->assertSame('complete_dry_run_blocked', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.validation_rejection_invalid_completion_event'));
        $this->assertSame('files_changed_outside_allowed_scope', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.validation_rejection_invalid_completion_reason'));
        $this->assertTrue(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.validation_rejection_blocked_by_scope'));
        $this->assertSame('completed_dry_run', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.validation_rejection_valid_completion_event'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.validation_rejection_proof_hash'));
        $this->assertSame('available', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.fleet_concurrency_proof_status'));
        $this->assertSame(2, data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.fleet_concurrency_agent_count'));
        $this->assertSame(2, data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.fleet_concurrency_distinct_task_total'));
        $this->assertSame(2, data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.fleet_concurrency_distinct_lease_total'));
        $this->assertSame(0, data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.fleet_concurrency_write_set_collision_count'));
        $this->assertTrue(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.fleet_concurrency_cleanup_green'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.fleet_concurrency_proof_hash'));
        $this->assertSame('passed', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.partial_supply_launch_gate_proof_status'));
        $this->assertTrue(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.partial_supply_launch_blocked'));
        $this->assertTrue(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.partial_supply_replenishment_plan_ready'));
        $this->assertTrue(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.partial_supply_cycle_supervisor_replenishes_before_launch'));
        $this->assertSame(0, data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.partial_supply_post_cleanup_claimable_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.partial_supply_launch_gate_proof_hash'));
        $this->assertSame('passed', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.partial_supply_bootstrap_gate_proof_status'));
        $this->assertSame('blocked', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.partial_supply_bootstrap_status'));
        $this->assertSame('task_supply_below_target_blocked_before_claim', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.partial_supply_bootstrap_claim_event'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.partial_supply_bootstrap_runtime_claim_persisted'));
        $this->assertSame(0, data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.partial_supply_bootstrap_active_lease_count_after_bootstrap'));
        $this->assertSame(0, data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.partial_supply_bootstrap_post_cleanup_claimable_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.partial_supply_bootstrap_gate_proof_hash'));
        $this->assertTrue(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.operational_readiness_matrix_all_true'));
        $this->assertSame(11, data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.operational_readiness_matrix_row_count'));
        $this->assertSame(0, data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.operational_readiness_matrix_failed_row_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.operational_readiness_matrix_hash'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.completion_real_allowed'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.runtime_execution_allowed'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.dispatch_allowed'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.provider_call_allowed'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.token_spend_allowed'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.self_programming_allowed'));
        $this->assertSame('ready_for_read_only_completion_audit', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.completion_audit_binding_packet_status'));
        $this->assertSame('agent_control_plane_terminal_loop_operational_proof', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.completion_audit_binding_packet_audit_option_key'));
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.completion_audit_binding_packet_expected_completion_audit_command'));
        $this->assertStringNotContainsString('--agent-control-plane-terminal-loop-operational-proof-json', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.completion_audit_binding_packet_diagnostic_completion_audit_command'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.completion_audit_binding_packet_proof_payload_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.completion_audit_binding_packet_hash'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.completion_audit_binding_export_requested'));
        $this->assertSame('not_requested', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.completion_audit_binding_export_status'));
        $this->assertSame('atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.completion_audit_binding_export_path'));
        $this->assertStringContainsString('terminal-loop-operational-proof-binding.json', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.completion_audit_binding_export_absolute_path'));
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=@', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.completion_audit_binding_export_audit_command'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.completion_audit_binding_export_write_performed'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.terminal_loop_operational_proof_hash'));
    }

    public function test_cli_can_persist_only_terminal_loop_operational_proof_binding_packet(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-terminal-loop-operational-proof-status' => true,
            '--persist-terminal-loop-operational-proof-binding' => true,
            '--actor' => 'cli-proof-agent',
            '--proof-id' => 'cli-proof-binding',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $path = 'atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json';

        $this->assertSame('passed', $payload['status']);
        $this->assertTrue(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.completion_audit_binding_export_requested'));
        $this->assertSame('persisted', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.completion_audit_binding_export_status'));
        $this->assertSame($path, data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.completion_audit_binding_export_path'));
        $this->assertStringContainsString('terminal-loop-operational-proof-binding.json', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.completion_audit_binding_export_absolute_path'));
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=@', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.completion_audit_binding_export_audit_command'));
        $this->assertTrue(data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.completion_audit_binding_export_write_performed'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'agent_control_plane_terminal_loop_operational_proof_status.completion_audit_binding_export_hash'));
        Storage::disk('local')->assertExists($path);

        $binding = json_decode((string) Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1', $binding['schema_version']);
        $this->assertSame('ready_for_read_only_completion_audit', $binding['status']);
        $this->assertSame('agent_control_plane_terminal_loop_operational_proof', $binding['audit_option_key']);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json', $binding['expected_completion_audit_command']);
        $this->assertFalse($binding['can_mark_completion_from_binding']);
        $this->assertFalse($binding['can_call_provider_from_binding']);
        $this->assertFalse($binding['can_spend_tokens_from_binding']);
    }
}
