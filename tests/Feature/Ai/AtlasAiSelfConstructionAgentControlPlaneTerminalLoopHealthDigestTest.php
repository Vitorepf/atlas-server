<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskLeaseRecoveryService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTerminalLoopHealthDigestService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneTerminalLoopHealthDigestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-16T12:00:00Z'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_empty_queue_digest_recommends_replenishment_without_mutation(): void
    {
        $digest = $this->service()->digest([
            'actor' => 'operator-a',
            'target_min_claimable_tasks' => 3,
            'max_new_tasks' => 3,
            'queue_tags' => ['lane-a'],
        ]);

        $this->assertSame(AgentControlPlaneTerminalLoopHealthDigestService::SCHEMA_VERSION, $digest['schema_version']);
        $this->assertSame('action_required', $digest['status']);
        $this->assertSame('replenish_task_supply', data_get($digest, 'loop_decision.recommended_action'));
        $this->assertTrue(data_get($digest, 'loop_decision.should_replenish_before_next_claim'));
        $this->assertFalse(data_get($digest, 'loop_decision.should_recover_before_next_claim'));
        $this->assertSame(0, data_get($digest, 'queue_health.claimable_task_count'));
        $this->assertGreaterThanOrEqual(0, data_get($digest, 'queue_health.queue_total_count'));
        $this->assertStringContainsString('--agent-control-plane-task-auto-replenishment-status', data_get($digest, 'next_commands.replenish_tasks'));
        $this->assertStringContainsString('--queue-tag=lane-a', data_get($digest, 'next_commands.execute_bootstrap'));
        $this->assertSame('fleet_launch_plan_blocked', data_get($digest, 'terminal_loop_fleet_launch_plan.status'));
        $this->assertFalse(data_get($digest, 'terminal_loop_fleet_launch_plan.safe_to_start_now'));
        $this->assertFalse(data_get($digest, 'terminal_loop_fleet_launch_plan.can_execute_from_digest'));
        $this->assertFalse(data_get($digest, 'terminal_loop_fleet_launch_plan.can_claim_from_digest'));
        $this->assertSame(0, data_get($digest, 'terminal_loop_fleet_launch_plan.recommended_terminal_count'));
        $this->assertContains('no_claimable_packets_for_requested_lane', data_get($digest, 'terminal_loop_fleet_launch_plan.blocked_reasons'));
        $this->assertContains('task_supply_below_target_replenish_before_launch', data_get($digest, 'terminal_loop_fleet_launch_plan.blocked_reasons'));
        $this->assertSame(
            AgentControlPlaneTerminalLoopHealthDigestService::FLEET_REPLENISHMENT_PLAN_SCHEMA_VERSION,
            data_get($digest, 'terminal_loop_fleet_replenishment_plan.schema_version'),
        );
        $this->assertSame('fleet_replenishment_required', data_get($digest, 'terminal_loop_fleet_replenishment_plan.status'));
        $this->assertTrue(data_get($digest, 'terminal_loop_fleet_replenishment_plan.should_replenish_now'));
        $this->assertFalse(data_get($digest, 'terminal_loop_fleet_replenishment_plan.can_replenish_from_digest'));
        $this->assertSame(3, data_get($digest, 'terminal_loop_fleet_replenishment_plan.required_new_task_count'));
        $this->assertSame(3, data_get($digest, 'terminal_loop_fleet_replenishment_plan.bounded_new_task_count'));
        $this->assertContains('run_replenishment_command', data_get($digest, 'terminal_loop_fleet_replenishment_plan.recommended_sequence'));
        $this->assertStringContainsString('--agent-control-plane-task-auto-replenishment-status', data_get($digest, 'terminal_loop_fleet_replenishment_plan.commands.replenish_tasks'));
        $this->assertNotEmpty($digest['terminal_loop_health_digest_hash']);
        $this->assertNotEmpty(data_get($digest, 'terminal_loop_fleet_launch_plan.terminal_loop_fleet_launch_plan_hash'));
        $this->assertNotEmpty(data_get($digest, 'terminal_loop_fleet_replenishment_plan.terminal_loop_fleet_replenishment_plan_hash'));
        $this->assertSame(
            AgentControlPlaneTerminalLoopHealthDigestService::FLEET_RESUME_ROLLUP_SCHEMA_VERSION,
            data_get($digest, 'terminal_loop_fleet_resume_rollup.schema_version'),
        );
        $this->assertSame('fleet_resume_rollup_clear', data_get($digest, 'terminal_loop_fleet_resume_rollup.status'));
        $this->assertFalse(data_get($digest, 'terminal_loop_fleet_resume_rollup.resume_attention_required'));
        $this->assertFalse(data_get($digest, 'terminal_loop_fleet_resume_rollup.can_recover_from_rollup'));
        $this->assertFalse(data_get($digest, 'terminal_loop_fleet_resume_rollup.can_claim_from_rollup'));
        $this->assertNotEmpty(data_get($digest, 'terminal_loop_fleet_resume_rollup.terminal_loop_fleet_resume_rollup_hash'));
        $this->assertSame(
            AgentControlPlaneTerminalLoopHealthDigestService::FLEET_OPERATOR_HANDOFF_SCHEMA_VERSION,
            data_get($digest, 'terminal_loop_fleet_operator_handoff.schema_version'),
        );
        $this->assertSame('fleet_operator_handoff_replenish_before_launch', data_get($digest, 'terminal_loop_fleet_operator_handoff.status'));
        $this->assertSame('replenish_task_supply_then_recheck_digest', data_get($digest, 'terminal_loop_fleet_operator_handoff.next_operator_action'));
        $this->assertFalse(data_get($digest, 'terminal_loop_fleet_operator_handoff.can_execute_from_handoff'));
        $this->assertStringContainsString('--agent-control-plane-task-auto-replenishment-status', data_get($digest, 'terminal_loop_fleet_operator_handoff.primary_command'));
        $this->assertNotEmpty(data_get($digest, 'terminal_loop_fleet_operator_handoff.terminal_loop_fleet_operator_handoff_hash'));
        $this->assertSame(
            AgentControlPlaneTerminalLoopHealthDigestService::FLEET_LANE_ISOLATION_SCHEMA_VERSION,
            data_get($digest, 'terminal_loop_fleet_lane_isolation.schema_version'),
        );
        $this->assertSame('fleet_lane_isolation_tagged_lane_verified', data_get($digest, 'terminal_loop_fleet_lane_isolation.status'));
        $this->assertTrue(data_get($digest, 'terminal_loop_fleet_lane_isolation.all_commands_lane_bound'));
        $this->assertFalse(data_get($digest, 'terminal_loop_fleet_lane_isolation.can_change_tags_from_digest'));
        $this->assertContains('--queue-tag=lane-a', data_get($digest, 'terminal_loop_fleet_lane_isolation.required_tag_args'));
        $this->assertNotEmpty(data_get($digest, 'terminal_loop_fleet_lane_isolation.terminal_loop_fleet_lane_isolation_hash'));
        $this->assertSame(
            AgentControlPlaneTerminalLoopHealthDigestService::FLEET_EVIDENCE_ROLLUP_SCHEMA_VERSION,
            data_get($digest, 'terminal_loop_fleet_evidence_rollup.schema_version'),
        );
        $this->assertSame('fleet_evidence_rollup_no_completed_tasks', data_get($digest, 'terminal_loop_fleet_evidence_rollup.status'));
        $this->assertSame(0, data_get($digest, 'terminal_loop_fleet_evidence_rollup.completed_dry_run_task_count'));
        $this->assertFalse(data_get($digest, 'terminal_loop_fleet_evidence_rollup.ready_for_operator_review'));
        $this->assertNotEmpty(data_get($digest, 'terminal_loop_fleet_evidence_rollup.terminal_loop_fleet_evidence_rollup_hash'));
        $this->assertSame(
            AgentControlPlaneTerminalLoopHealthDigestService::CYCLE_SUPERVISOR_SCHEMA_VERSION,
            data_get($digest, 'terminal_loop_cycle_supervisor.schema_version'),
        );
        $this->assertSame('cycle_replenishment_required', data_get($digest, 'terminal_loop_cycle_supervisor.status'));
        $this->assertSame('replenish_before_launch', data_get($digest, 'terminal_loop_cycle_supervisor.cycle_state'));
        $this->assertSame('restore_lane_task_supply_before_worker_launch', data_get($digest, 'terminal_loop_cycle_supervisor.next_command_purpose'));
        $this->assertStringContainsString('--agent-control-plane-task-auto-replenishment-status', data_get($digest, 'terminal_loop_cycle_supervisor.next_command'));
        $this->assertTrue(data_get($digest, 'terminal_loop_cycle_supervisor.next_command_is_lane_bound'));
        $this->assertTrue(data_get($digest, 'terminal_loop_cycle_supervisor.operator_loop_contract.rerun_digest_after_next_command'));
        $this->assertFalse(data_get($digest, 'terminal_loop_cycle_supervisor.can_execute_next_command'));
        $this->assertFalse(data_get($digest, 'terminal_loop_cycle_supervisor.can_replenish_from_supervisor'));
        $this->assertFalse(data_get($digest, 'terminal_loop_cycle_supervisor.can_call_provider_from_supervisor'));
        $this->assertNotEmpty(data_get($digest, 'terminal_loop_cycle_supervisor.terminal_loop_cycle_supervisor_hash'));
        $this->assertFalse(data_get($digest, 'runtime_safety.dispatch_allowed'));
        $this->assertFalse(data_get($digest, 'runtime_safety.provider_call_allowed'));
    }

    public function test_claimable_supply_digest_allows_terminal_workers_to_continue(): void
    {
        $orchestrator = $this->orchestrator();
        $orchestrator->prepareAndEnqueue(['task_packet' => $this->input('ready-1'), 'queue' => ['tags' => ['lane-ready']]]);
        $orchestrator->prepareAndEnqueue(['task_packet' => $this->input('ready-2'), 'queue' => ['tags' => ['lane-ready']]]);

        $digest = $this->service()->digest([
            'actor' => 'operator-b',
            'target_min_claimable_tasks' => 2,
            'queue_tags' => ['lane-ready'],
        ]);

        $this->assertSame('ready', $digest['status']);
        $this->assertSame('continue_or_start_terminal_workers', data_get($digest, 'loop_decision.recommended_action'));
        $this->assertTrue(data_get($digest, 'loop_decision.safe_to_start_new_worker'));
        $this->assertSame(2, data_get($digest, 'queue_health.claimable_task_count'));
        $this->assertSame(0, data_get($digest, 'lease_health.recoverable_lease_count'));
        $this->assertTrue(data_get($digest, 'loop_decision.can_loop_without_chat_history'));
        $this->assertTrue(data_get($digest, 'loop_decision.one_terminal_one_packet_at_a_time'));
        $this->assertSame('fleet_launch_plan_ready', data_get($digest, 'terminal_loop_fleet_launch_plan.status'));
        $this->assertTrue(data_get($digest, 'terminal_loop_fleet_launch_plan.safe_to_start_now'));
        $this->assertSame(2, data_get($digest, 'terminal_loop_fleet_launch_plan.recommended_terminal_count'));
        $this->assertCount(2, data_get($digest, 'terminal_loop_fleet_launch_plan.terminal_assignments'));
        $this->assertCount(2, data_get($digest, 'terminal_loop_fleet_launch_plan.copy_paste_terminal_commands'));
        $this->assertSame(
            ['operator-b-fleet-01', 'operator-b-fleet-02'],
            array_column(data_get($digest, 'terminal_loop_fleet_launch_plan.terminal_assignments'), 'actor'),
        );
        $this->assertStringContainsString(
            '--actor=operator-b-fleet-01',
            data_get($digest, 'terminal_loop_fleet_launch_plan.terminal_assignments.0.execute_bootstrap_command'),
        );
        $this->assertStringContainsString(
            '--queue-tag=lane-ready',
            data_get($digest, 'terminal_loop_fleet_launch_plan.terminal_assignments.1.execute_bootstrap_command'),
        );
        $this->assertSame('fleet_replenishment_not_required', data_get($digest, 'terminal_loop_fleet_replenishment_plan.status'));
        $this->assertFalse(data_get($digest, 'terminal_loop_fleet_replenishment_plan.should_replenish_now'));
        $this->assertSame(0, data_get($digest, 'terminal_loop_fleet_replenishment_plan.required_new_task_count'));
        $this->assertFalse(data_get($digest, 'terminal_loop_fleet_launch_plan.can_execute_from_digest'));
        $this->assertFalse(data_get($digest, 'terminal_loop_fleet_launch_plan.can_claim_from_digest'));
        $this->assertSame('fleet_operator_handoff_launch_workers', data_get($digest, 'terminal_loop_fleet_operator_handoff.status'));
        $this->assertStringContainsString('--agent-control-plane-terminal-worker-bootstrap-status', data_get($digest, 'terminal_loop_fleet_operator_handoff.primary_command'));
        $this->assertSame('fleet_lane_isolation_tagged_lane_verified', data_get($digest, 'terminal_loop_fleet_lane_isolation.status'));
        $this->assertTrue(data_get($digest, 'terminal_loop_fleet_lane_isolation.all_commands_lane_bound'));
        $this->assertSame('cycle_worker_launch_ready', data_get($digest, 'terminal_loop_cycle_supervisor.status'));
        $this->assertSame('launch_or_continue_workers', data_get($digest, 'terminal_loop_cycle_supervisor.cycle_state'));
        $this->assertSame('start_one_lane_bound_terminal_worker_with_one_packet', data_get($digest, 'terminal_loop_cycle_supervisor.next_command_purpose'));
        $this->assertStringContainsString('--actor=operator-b-fleet-01', data_get($digest, 'terminal_loop_cycle_supervisor.next_command'));
        $this->assertStringContainsString('--queue-tag=lane-ready', data_get($digest, 'terminal_loop_cycle_supervisor.next_command'));
        $this->assertTrue(data_get($digest, 'terminal_loop_cycle_supervisor.next_command_is_lane_bound'));
        $this->assertSame(2, data_get($digest, 'terminal_loop_cycle_supervisor.operator_loop_contract.max_recommended_terminals_per_batch'));
        $this->assertFalse(data_get($digest, 'terminal_loop_cycle_supervisor.can_claim_from_supervisor'));
    }

    public function test_digest_blocks_fleet_launch_until_claimable_supply_meets_target(): void
    {
        $orchestrator = $this->orchestrator();
        $orchestrator->prepareAndEnqueue(['task_packet' => $this->input('partial-supply-1'), 'queue' => ['tags' => ['lane-partial-supply']]]);

        $digest = $this->service()->digest([
            'actor' => 'operator-partial-supply',
            'target_min_claimable_tasks' => 3,
            'max_new_tasks' => 3,
            'queue_tags' => ['lane-partial-supply'],
        ]);

        $this->assertSame('action_required', $digest['status']);
        $this->assertSame('replenish_task_supply', data_get($digest, 'loop_decision.recommended_action'));
        $this->assertFalse(data_get($digest, 'loop_decision.safe_to_start_new_worker'));
        $this->assertSame(1, data_get($digest, 'queue_health.claimable_task_count'));
        $this->assertSame('fleet_launch_plan_blocked', data_get($digest, 'terminal_loop_fleet_launch_plan.status'));
        $this->assertFalse(data_get($digest, 'terminal_loop_fleet_launch_plan.safe_to_start_now'));
        $this->assertSame(0, data_get($digest, 'terminal_loop_fleet_launch_plan.recommended_terminal_count'));
        $this->assertContains('task_supply_below_target_replenish_before_launch', data_get($digest, 'terminal_loop_fleet_launch_plan.blocked_reasons'));
        $this->assertSame('fleet_replenishment_required', data_get($digest, 'terminal_loop_fleet_replenishment_plan.status'));
        $this->assertSame(2, data_get($digest, 'terminal_loop_fleet_replenishment_plan.required_new_task_count'));
        $this->assertSame(2, data_get($digest, 'terminal_loop_fleet_replenishment_plan.bounded_new_task_count'));
        $this->assertTrue(data_get($digest, 'terminal_loop_fleet_replenishment_plan.should_replenish_now'));
        $this->assertSame('cycle_replenishment_required', data_get($digest, 'terminal_loop_cycle_supervisor.status'));
        $this->assertSame('replenish_before_launch', data_get($digest, 'terminal_loop_cycle_supervisor.cycle_state'));
    }

    public function test_digest_blocks_fleet_launch_when_worker_task_eligibility_fails(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $packet = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_task_packet.v1',
            'task_packet_id' => 'digest-operator-only-blocker',
            'task_packet_hash' => hash('sha256', 'digest-operator-only-blocker'),
            'status' => 'planned',
            'objective' => 'Digest must not launch workers for operator-only completion blockers',
            'allowed_files' => ['docs/engineering-knowledge-base/self-construction/operator-only.md'],
            'normalized_scope' => [
                'allowed_files' => ['docs/engineering-knowledge-base/self-construction/operator-only.md'],
            ],
            'continuation_context' => [
                'worker_executable' => false,
                'operator_handoff_required' => true,
                'auto_replenishment_reference' => 'end_to_end_real_provider_smoke_green',
            ],
            'acceptance_criteria' => ['operator_external_smoke_required'],
            'required_tests' => ['real_provider_smoke_is_external'],
        ];
        $this->assertSame('ok', $queue->enqueue($packet, ['tags' => ['digest_operator_only_lane']])['status']);

        $digest = $this->service()->digest([
            'actor' => 'operator-eligibility',
            'target_min_claimable_tasks' => 1,
            'queue_tags' => ['digest_operator_only_lane'],
        ]);

        $this->assertSame('action_required', $digest['status']);
        $this->assertSame('inspect_worker_task_eligibility_before_launch', data_get($digest, 'loop_decision.recommended_action'));
        $this->assertFalse(data_get($digest, 'loop_decision.safe_to_start_new_worker'));
        $this->assertSame('blocked', data_get($digest, 'worker_task_eligibility.status'));
        $this->assertSame(3, data_get($digest, 'worker_task_eligibility.violation_count'));
        $this->assertSame('fleet_launch_plan_blocked', data_get($digest, 'terminal_loop_fleet_launch_plan.status'));
        $this->assertFalse(data_get($digest, 'terminal_loop_fleet_launch_plan.safe_to_start_now'));
        $this->assertSame(0, data_get($digest, 'terminal_loop_fleet_launch_plan.recommended_terminal_count'));
        $this->assertContains('worker_task_eligibility_blocked_before_worker_launch', data_get($digest, 'terminal_loop_fleet_launch_plan.blocked_reasons'));
        $this->assertContains('worker_task_eligibility_worker_candidate_task_not_worker_executable', data_get($digest, 'terminal_loop_fleet_launch_plan.blocked_reasons'));
        $this->assertSame('blocked', data_get($digest, 'terminal_loop_fleet_launch_plan.worker_task_eligibility.status'));
        $this->assertStringContainsString(
            '--agent-control-plane-worker-task-eligibility-certification-status',
            data_get($digest, 'observability.worker_task_eligibility_certification_command'),
        );
        $this->assertStringContainsString(
            '--queue-tag=digest_operator_only_lane',
            data_get($digest, 'observability.worker_task_eligibility_certification_command'),
        );
    }

    public function test_tag_filtered_digest_explains_hidden_claimable_supply_outside_requested_lane(): void
    {
        $orchestrator = $this->orchestrator();
        $orchestrator->prepareAndEnqueue(['task_packet' => $this->input('other-lane-1'), 'queue' => ['tags' => ['lane-other']]]);
        $orchestrator->prepareAndEnqueue(['task_packet' => $this->input('other-lane-2'), 'queue' => ['tags' => ['lane-other']]]);

        $digest = $this->service()->digest([
            'actor' => 'operator-filtered',
            'target_min_claimable_tasks' => 2,
            'queue_tags' => ['lane-empty'],
        ]);

        $this->assertSame('action_required', $digest['status']);
        $this->assertSame('replenish_task_supply', data_get($digest, 'loop_decision.recommended_action'));
        $this->assertSame(0, data_get($digest, 'queue_health.claimable_task_count'));
        $this->assertSame(2, data_get($digest, 'queue_health.unfiltered_claimable_task_count'));
        $this->assertTrue(data_get($digest, 'queue_health.tag_filter_active'));
        $this->assertSame(['lane-empty'], data_get($digest, 'queue_health.requested_queue_tags'));
        $this->assertSame(2, data_get($digest, 'queue_health.hidden_claimable_outside_requested_tags'));
        $this->assertTrue(data_get($digest, 'queue_health.tag_filtered_supply_gap'));
        $this->assertSame(2, data_get($digest, 'loop_decision.hidden_claimable_outside_requested_tags'));
        $this->assertTrue(data_get($digest, 'loop_decision.tag_filtered_supply_gap'));
        $this->assertStringContainsString('outside the requested tags', data_get($digest, 'queue_health.tag_filter_explainer'));
        $this->assertStringContainsString('--queue-tag=lane-empty', data_get($digest, 'next_commands.replenish_tasks'));
        $this->assertSame('fleet_launch_plan_blocked', data_get($digest, 'terminal_loop_fleet_launch_plan.status'));
        $this->assertContains('claimable_packets_exist_outside_requested_tags_2', data_get($digest, 'terminal_loop_fleet_launch_plan.blocked_reasons'));
        $this->assertContains('task_supply_below_target_replenish_before_launch', data_get($digest, 'terminal_loop_fleet_launch_plan.blocked_reasons'));
        $this->assertSame('fleet_replenishment_required', data_get($digest, 'terminal_loop_fleet_replenishment_plan.status'));
        $this->assertTrue(data_get($digest, 'terminal_loop_fleet_replenishment_plan.tag_filtered_supply_gap'));
        $this->assertSame(2, data_get($digest, 'terminal_loop_fleet_replenishment_plan.hidden_claimable_outside_requested_tags'));
    }

    public function test_expired_active_lease_digest_recommends_recovery_before_new_claim(): void
    {
        $orchestrator = $this->orchestrator();
        $orchestrator->prepareAndEnqueue(['task_packet' => $this->input('expired-digest-1'), 'queue' => ['tags' => ['lane-expired']]]);
        $claim = $orchestrator->claimNext('agent-expired', ['ttl_seconds' => 60, 'tag' => 'lane-expired']);
        $this->assertSame('claimed', $claim['event']);

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-16T12:02:00Z'));

        $digest = $this->service()->digest([
            'actor' => 'operator-c',
            'target_min_claimable_tasks' => 1,
            'queue_tags' => ['lane-expired'],
        ]);

        $this->assertSame('action_required', $digest['status']);
        $this->assertSame('recover_stale_or_orphaned_leases', data_get($digest, 'loop_decision.recommended_action'));
        $this->assertTrue(data_get($digest, 'loop_decision.should_recover_before_next_claim'));
        $this->assertSame(1, data_get($digest, 'lease_health.recoverable_lease_count'));
        $this->assertStringContainsString('--agent-control-plane-task-lease-recovery-status', data_get($digest, 'next_commands.inspect_or_recover_leases'));
        $this->assertSame('claimed', data_get((new AgentControlPlaneTaskPacketQueueRepository)->get('expired-digest-1'), 'status'));
        $this->assertSame('fleet_launch_plan_blocked', data_get($digest, 'terminal_loop_fleet_launch_plan.status'));
        $this->assertContains('recoverable_leases_must_be_recovered_before_starting_new_terminals', data_get($digest, 'terminal_loop_fleet_launch_plan.blocked_reasons'));
        $this->assertSame('fleet_replenishment_blocked_recover_leases_first', data_get($digest, 'terminal_loop_fleet_replenishment_plan.status'));
        $this->assertTrue(data_get($digest, 'terminal_loop_fleet_replenishment_plan.recover_before_replenishment'));
        $this->assertFalse(data_get($digest, 'terminal_loop_fleet_replenishment_plan.should_replenish_now'));
        $this->assertSame('fleet_resume_rollup_recovery_required', data_get($digest, 'terminal_loop_fleet_resume_rollup.status'));
        $this->assertTrue(data_get($digest, 'terminal_loop_fleet_resume_rollup.resume_attention_required'));
        $this->assertSame(1, data_get($digest, 'terminal_loop_fleet_resume_rollup.recoverable_task_count'));
        $this->assertSame('expired-digest-1', data_get($digest, 'terminal_loop_fleet_resume_rollup.recoverable_task_summaries.0.task_packet_id'));
        $this->assertSame('recover_then_claim_fresh_lease', data_get($digest, 'terminal_loop_fleet_resume_rollup.recoverable_task_summaries.0.safe_next_action'));
        $this->assertStringContainsString('--agent-control-plane-task-lease-recovery-status', data_get($digest, 'terminal_loop_fleet_resume_rollup.recoverable_task_summaries.0.recover_command'));
        $this->assertStringContainsString('--packet=expired-digest-1', data_get($digest, 'terminal_loop_fleet_resume_rollup.recoverable_task_summaries.0.recover_command'));
        $this->assertSame('fleet_operator_handoff_recover_before_loop', data_get($digest, 'terminal_loop_fleet_operator_handoff.status'));
        $this->assertSame('recover_orphaned_or_expired_task_leases', data_get($digest, 'terminal_loop_fleet_operator_handoff.next_operator_action'));
        $this->assertStringContainsString('--packet=expired-digest-1', data_get($digest, 'terminal_loop_fleet_operator_handoff.primary_command'));
        $this->assertSame('fleet_lane_isolation_tagged_lane_verified', data_get($digest, 'terminal_loop_fleet_lane_isolation.status'));
        $this->assertTrue(data_get($digest, 'terminal_loop_fleet_lane_isolation.all_commands_lane_bound'));
        $this->assertSame('cycle_recovery_required', data_get($digest, 'terminal_loop_cycle_supervisor.status'));
        $this->assertSame('recover_before_claim', data_get($digest, 'terminal_loop_cycle_supervisor.cycle_state'));
        $this->assertSame('recover_released_expired_or_orphaned_task_before_any_new_claim', data_get($digest, 'terminal_loop_cycle_supervisor.next_command_purpose'));
        $this->assertTrue(data_get($digest, 'terminal_loop_cycle_supervisor.transition_guards.recover_before_replenish'));
        $this->assertTrue(data_get($digest, 'terminal_loop_cycle_supervisor.operator_loop_contract.recover_before_any_new_claim'));
        $this->assertFalse(data_get($digest, 'terminal_loop_cycle_supervisor.can_recover_from_supervisor'));
    }

    public function test_claimed_without_lease_metadata_digest_recommends_packet_scoped_recovery(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $packet = (new AgentControlPlaneTaskPacketBuilder)->build($this->input('metadata-orphan-digest-1'));
        $packet['status'] = 'claimed';
        $queue->enqueue($packet, [
            'tags' => ['lane-metadata-orphan'],
            'metadata' => ['agent_id' => 'agent-metadata-orphan'],
        ]);

        $digest = $this->service()->digest([
            'actor' => 'operator-metadata-orphan',
            'target_min_claimable_tasks' => 1,
            'queue_tags' => ['lane-metadata-orphan'],
        ]);

        $this->assertSame('action_required', $digest['status']);
        $this->assertSame('recover_stale_or_orphaned_leases', data_get($digest, 'loop_decision.recommended_action'));
        $this->assertSame(1, data_get($digest, 'lease_health.recoverable_orphaned_claim_count'));
        $this->assertSame('fleet_resume_rollup_recovery_required', data_get($digest, 'terminal_loop_fleet_resume_rollup.status'));
        $this->assertSame(
            AgentControlPlaneTaskLeaseRecoveryService::RECOVERABILITY_RECOVERABLE_ORPHAN,
            data_get($digest, 'terminal_loop_fleet_resume_rollup.recoverable_task_summaries.0.classification'),
        );
        $this->assertSame('', data_get($digest, 'terminal_loop_fleet_resume_rollup.recoverable_task_summaries.0.lease_id'));
        $this->assertStringContainsString('--packet=metadata-orphan-digest-1', data_get($digest, 'terminal_loop_fleet_resume_rollup.recoverable_task_summaries.0.recover_command'));
        $this->assertStringContainsString('--packet=metadata-orphan-digest-1', data_get($digest, 'terminal_loop_fleet_operator_handoff.primary_command'));
        $this->assertSame('claimed', $queue->get('metadata-orphan-digest-1')['status']);
    }

    public function test_released_task_digest_recommends_recovery_before_new_claim(): void
    {
        $orchestrator = $this->orchestrator();
        $orchestrator->prepareAndEnqueue(['task_packet' => $this->input('released-digest-1'), 'queue' => ['tags' => ['lane-released']]]);
        $claim = $orchestrator->claimNext('agent-released-digest', ['ttl_seconds' => 600, 'tag' => 'lane-released']);
        $this->assertSame('claimed', $claim['event']);
        $orchestrator->releaseLease((string) $claim['lease_id'], 'agent-released-digest', ['reason' => 'operator_interrupted_terminal']);

        $digest = $this->service()->digest([
            'actor' => 'operator-released',
            'target_min_claimable_tasks' => 1,
            'queue_tags' => ['lane-released'],
        ]);

        $this->assertSame('action_required', $digest['status']);
        $this->assertSame('recover_stale_or_orphaned_leases', data_get($digest, 'loop_decision.recommended_action'));
        $this->assertTrue(data_get($digest, 'loop_decision.should_recover_before_next_claim'));
        $this->assertSame(1, data_get($digest, 'lease_health.recoverable_lease_count'));
        $this->assertSame(1, data_get($digest, 'lease_health.recoverable_released_task_count'));
        $this->assertSame('fleet_resume_rollup_recovery_required', data_get($digest, 'terminal_loop_fleet_resume_rollup.status'));
        $this->assertSame(
            AgentControlPlaneTaskLeaseRecoveryService::RECOVERABILITY_RECOVERABLE_RELEASED,
            data_get($digest, 'terminal_loop_fleet_resume_rollup.recoverable_task_summaries.0.classification'),
        );
        $this->assertSame('recover_then_claim_fresh_lease', data_get($digest, 'terminal_loop_fleet_resume_rollup.recoverable_task_summaries.0.safe_next_action'));
        $this->assertStringContainsString('--packet=released-digest-1', data_get($digest, 'terminal_loop_fleet_resume_rollup.recoverable_task_summaries.0.recover_command'));
        $this->assertSame('fleet_operator_handoff_recover_before_loop', data_get($digest, 'terminal_loop_fleet_operator_handoff.status'));
        $this->assertStringContainsString('--packet=released-digest-1', data_get($digest, 'terminal_loop_fleet_operator_handoff.primary_command'));
    }

    public function test_fleet_launch_plan_sanitizes_actor_and_caps_terminal_count(): void
    {
        $orchestrator = $this->orchestrator();
        for ($index = 1; $index <= 8; $index++) {
            $orchestrator->prepareAndEnqueue(['task_packet' => $this->input('fleet-cap-'.$index), 'queue' => ['tags' => ['lane-cap']]]);
        }

        $digest = $this->service()->digest([
            'actor' => 'operator with spaces',
            'target_min_claimable_tasks' => 8,
            'queue_tags' => ['lane-cap'],
        ]);

        $this->assertSame('fleet_launch_plan_ready', data_get($digest, 'terminal_loop_fleet_launch_plan.status'));
        $this->assertSame(6, data_get($digest, 'terminal_loop_fleet_launch_plan.recommended_terminal_count'));
        $this->assertCount(6, data_get($digest, 'terminal_loop_fleet_launch_plan.terminal_assignments'));
        $this->assertSame('operator-with-spaces', data_get($digest, 'terminal_loop_fleet_launch_plan.actor_base'));
        $this->assertSame('operator-with-spaces-fleet-01', data_get($digest, 'terminal_loop_fleet_launch_plan.terminal_assignments.0.actor'));
        $this->assertStringContainsString('--actor=operator-with-spaces-fleet-01', data_get($digest, 'terminal_loop_fleet_launch_plan.terminal_assignments.0.execute_bootstrap_command'));
        $this->assertStringNotContainsString('--actor=operator with spaces', data_get($digest, 'terminal_loop_fleet_launch_plan.terminal_assignments.0.execute_bootstrap_command'));
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-health-digest-status',
            data_get($digest, 'terminal_loop_fleet_launch_plan.post_launch_observability_commands.health_digest'),
        );
    }

    public function test_fleet_evidence_rollup_summarizes_completed_dry_run_evidence(): void
    {
        $orchestrator = $this->orchestrator();
        $orchestrator->prepareAndEnqueue(['task_packet' => $this->input('rollup-1'), 'queue' => ['tags' => ['lane-rollup']]]);
        $claim = $orchestrator->claimNext('agent-rollup', ['tag' => 'lane-rollup']);
        $this->assertSame('claimed', $claim['event']);
        $evidence = [
            'packet_id' => (string) $claim['task_packet_id'],
            'lease_id' => (string) $claim['lease_id'],
            'actor' => 'agent-rollup',
            'files_changed' => ['app/Services/Ai/SelfConstruction/rollup-1.php'],
            'commands_run' => ['php artisan test --filter=rollup'],
            'tests_or_gates_result' => 'passed',
            'git_status_short' => 'M app/Services/Ai/SelfConstruction/rollup-1.php',
            'git_diff_check_result' => 'clean',
        ];
        $evidence['evidence_hash'] = AgentControlPlaneTaskQueueOrchestrator::canonicalCompletionEvidenceHash($evidence);
        $evidenceHash = (string) $evidence['evidence_hash'];
        $completion = $orchestrator->completeDryRun(
            (string) $claim['task_packet_id'],
            (string) $claim['lease_id'],
            $evidence,
        );
        $this->assertSame('completed_dry_run', $completion['event']);

        $digest = $this->service()->digest([
            'actor' => 'operator-rollup',
            'target_min_claimable_tasks' => 1,
            'queue_tags' => ['lane-rollup'],
        ]);

        $this->assertSame('fleet_evidence_rollup_green', data_get($digest, 'terminal_loop_fleet_evidence_rollup.status'));
        $this->assertSame(1, data_get($digest, 'terminal_loop_fleet_evidence_rollup.completed_dry_run_task_count'));
        $this->assertSame(1, data_get($digest, 'terminal_loop_fleet_evidence_rollup.valid_completion_evidence_count'));
        $this->assertSame(0, data_get($digest, 'terminal_loop_fleet_evidence_rollup.attention_required_count'));
        $this->assertTrue(data_get($digest, 'terminal_loop_fleet_evidence_rollup.ready_for_operator_review'));
        $this->assertContains($evidenceHash, data_get($digest, 'terminal_loop_fleet_evidence_rollup.evidence_hashes'));
        $this->assertSame('rollup-1', data_get($digest, 'terminal_loop_fleet_evidence_rollup.recent_completed_task_summaries.0.task_packet_id'));
        $this->assertSame('valid_completion_evidence', data_get($digest, 'terminal_loop_fleet_evidence_rollup.recent_completed_task_summaries.0.status'));
        $this->assertSame('cycle_evidence_review_ready', data_get($digest, 'terminal_loop_cycle_supervisor.status'));
        $this->assertSame('review_evidence', data_get($digest, 'terminal_loop_cycle_supervisor.cycle_state'));
        $this->assertSame('review_completed_dry_run_evidence_and_rerun_digest', data_get($digest, 'terminal_loop_cycle_supervisor.next_command_purpose'));
        $this->assertFalse(data_get($digest, 'terminal_loop_cycle_supervisor.can_complete_from_supervisor'));
    }

    public function test_cli_status_exposes_digest_summary(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-terminal-loop-health-digest-status' => true,
            '--actor' => 'cli-loop-digest',
            '--target-min-claimable-tasks' => 2,
            '--max-new-tasks' => 2,
            '--queue-tag' => ['cli-lane'],
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction_agent_control_plane_terminal_loop_health_digest_status.v1', $payload['schema_version']);
        $this->assertSame('action_required', data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.status'));
        $this->assertSame('cli-loop-digest', data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.actor'));
        $this->assertSame(['cli-lane'], data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.queue_tags'));
        $this->assertSame('replenish_task_supply', data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.recommended_action'));
        $this->assertTrue(data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.tag_filter_active'));
        $this->assertSame(0, data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.claimable_task_count'));
        $this->assertGreaterThanOrEqual(0, data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.unfiltered_claimable_task_count'));
        $this->assertGreaterThanOrEqual(0, data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.hidden_claimable_outside_requested_tags'));
        $this->assertTrue(data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.can_loop_without_chat_history'));
        $this->assertSame(
            AgentControlPlaneTerminalLoopHealthDigestService::FLEET_LAUNCH_PLAN_SCHEMA_VERSION,
            data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_launch_plan_schema'),
        );
        $this->assertSame('fleet_launch_plan_blocked', data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_launch_plan_status'));
        $this->assertSame(0, data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_recommended_terminal_count'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_safe_to_start_now'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_can_execute_from_digest'));
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_plan_hash'));
        $this->assertSame(
            AgentControlPlaneTerminalLoopHealthDigestService::FLEET_REPLENISHMENT_PLAN_SCHEMA_VERSION,
            data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_replenishment_plan_schema'),
        );
        $this->assertSame('fleet_replenishment_required', data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_replenishment_plan_status'));
        $this->assertSame(2, data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_replenishment_required_new_task_count'));
        $this->assertTrue(data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_replenishment_should_replenish_now'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_replenishment_can_replenish_from_digest'));
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_replenishment_plan_hash'));
        $this->assertSame(
            AgentControlPlaneTerminalLoopHealthDigestService::FLEET_RESUME_ROLLUP_SCHEMA_VERSION,
            data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_resume_rollup_schema'),
        );
        $this->assertSame('fleet_resume_rollup_clear', data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_resume_rollup_status'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_resume_attention_required'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_resume_can_recover_from_rollup'));
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_resume_rollup_hash'));
        $this->assertSame(
            AgentControlPlaneTerminalLoopHealthDigestService::FLEET_OPERATOR_HANDOFF_SCHEMA_VERSION,
            data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_operator_handoff_schema'),
        );
        $this->assertSame('fleet_operator_handoff_replenish_before_launch', data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_operator_handoff_status'));
        $this->assertSame('replenish_task_supply_then_recheck_digest', data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_operator_handoff_next_action'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_operator_handoff_can_execute'));
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_operator_handoff_hash'));
        $this->assertSame(
            AgentControlPlaneTerminalLoopHealthDigestService::FLEET_LANE_ISOLATION_SCHEMA_VERSION,
            data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_lane_isolation_schema'),
        );
        $this->assertSame('fleet_lane_isolation_tagged_lane_verified', data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_lane_isolation_status'));
        $this->assertTrue(data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_lane_all_commands_bound'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_lane_can_change_tags'));
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_lane_isolation_hash'));
        $this->assertSame(
            AgentControlPlaneTerminalLoopHealthDigestService::FLEET_EVIDENCE_ROLLUP_SCHEMA_VERSION,
            data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_evidence_rollup_schema'),
        );
        $this->assertSame('fleet_evidence_rollup_no_completed_tasks', data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_evidence_rollup_status'));
        $this->assertSame(0, data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_completed_dry_run_task_count'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_evidence_ready_for_operator_review'));
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_fleet_evidence_rollup_hash'));
        $this->assertSame(
            AgentControlPlaneTerminalLoopHealthDigestService::CYCLE_SUPERVISOR_SCHEMA_VERSION,
            data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_cycle_supervisor_schema'),
        );
        $this->assertSame('cycle_replenishment_required', data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_cycle_supervisor_status'));
        $this->assertSame('replenish_before_launch', data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_cycle_supervisor_cycle_state'));
        $this->assertSame('restore_lane_task_supply_before_worker_launch', data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_cycle_supervisor_next_command_purpose'));
        $this->assertTrue(data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_cycle_supervisor_next_command_is_lane_bound'));
        $this->assertFalse(data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_cycle_supervisor_can_execute'));
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_cycle_supervisor_hash'));
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_terminal_loop_health_digest_status.terminal_loop_health_digest_hash'));
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-worker-bootstrap-status',
            data_get($payload, 'agent_control_plane_terminal_loop_health_digest.next_commands.execute_bootstrap'),
        );
    }

    private function service(): AgentControlPlaneTerminalLoopHealthDigestService
    {
        return new AgentControlPlaneTerminalLoopHealthDigestService(
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneTaskLeaseRecoveryService,
        );
    }

    private function orchestrator(): AgentControlPlaneTaskQueueOrchestrator
    {
        return new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            new AgentControlPlaneTaskPacketQueueRepository,
            new AgentControlPlaneClaimLeaseRepository,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function input(string $id): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'terminal loop health digest test '.$id,
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/'.$id.'.php'],
            'acceptance_criteria' => ['digest_ok'],
            'required_evidence' => ['terminal_loop_health_digest_checked'],
        ];
    }
}
