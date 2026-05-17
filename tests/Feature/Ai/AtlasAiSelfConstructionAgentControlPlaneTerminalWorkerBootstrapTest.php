<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneClaimLeaseRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneContinuationSummaryBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneEvidenceLedgerDryRun;
use App\Services\Ai\SelfConstruction\AgentControlPlaneOneShotWorkerPacketService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneScopeLockRuntimeValidator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskAutoReplenishmentService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTerminalWorkerBootstrapService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneTerminalWorkerBootstrapTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_bootstrap_replenishes_claims_and_returns_one_shot_prompt(): void
    {
        $result = $this->service()->bootstrap($this->context(), [
            'actor' => 'claude-terminal-1',
            'target_min_claimable_tasks' => 3,
            'max_new_tasks' => 3,
        ]);

        $this->assertSame('ready_for_worker', $result['status']);
        $this->assertSame('claude-terminal-1', $result['actor']);
        $this->assertSame('claimed', $result['claim_event']);
        $this->assertTrue($result['runtime_claim_persisted']);
        $this->assertTrue($result['one_shot_worker_packet_ready']);
        $this->assertNotEmpty($result['task_packet_id']);
        $this->assertNotEmpty($result['lease_id']);
        $this->assertNotEmpty($result['worker_prompt_full']);
        $this->assertNotEmpty($result['one_shot_packet_hash']);
        $this->assertStringContainsString('--agent-control-plane-task-queue-complete-dry-run-status', $result['completion_command']);
        $this->assertStringContainsString('--evidence-hash=<sha256-of-final-evidence>', $result['completion_command']);
        $this->assertStringContainsString('--completion-evidence-json=@/path/to/completion-evidence.json', $result['completion_command']);
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_task_queue_completion_evidence.v1',
            data_get($result, 'completion_evidence_template.schema_version'),
        );
        $this->assertSame($result['task_packet_id'], data_get($result, 'completion_evidence_template.packet_id'));
        $this->assertSame($result['lease_id'], data_get($result, 'completion_evidence_template.lease_id'));
        $this->assertStringContainsString(
            '"git_diff_check_result": "clean"',
            $result['completion_evidence_template_json'],
        );
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_terminal_loop_operator_commands.v1',
            data_get($result, 'terminal_loop_operator_commands.schema_version'),
        );
        $this->assertSame($result['completion_command'], data_get($result, 'terminal_loop_operator_commands.complete_current_dry_run'));
        $this->assertSame('/path/to/completion-evidence.json', data_get($result, 'terminal_loop_operator_commands.completion_evidence_template_path'));
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_terminal_long_running_loop_contract.v1',
            data_get($result, 'terminal_loop_operator_commands.long_running_loop_contract.schema_version'),
        );
        $this->assertSame(600, data_get($result, 'terminal_loop_operator_commands.long_running_loop_contract.lease_renewal_cadence_seconds'));
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-worker-bootstrap-status',
            data_get($result, 'terminal_loop_operator_commands.long_running_loop_contract.next_iteration_command'),
        );
        $this->assertContains(
            'completion_evidence_validation_not_valid',
            data_get($result, 'terminal_loop_operator_commands.long_running_loop_contract.stop_conditions'),
        );
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_worker_resumption_contract.v1',
            data_get($result, 'resumption_contract.schema_version'),
        );
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_terminal_loop_resumption_checkpoint.v1',
            data_get($result, 'terminal_loop_resumption_checkpoint.schema_version'),
        );
        $this->assertSame(
            'claimed_packet_ready_for_one_shot_worker',
            data_get($result, 'terminal_loop_resumption_checkpoint.current_step'),
        );
        $this->assertTrue((bool) data_get($result, 'terminal_loop_resumption_checkpoint.can_resume_without_chat_history'));
        $this->assertSame($result['task_packet_id'], data_get($result, 'terminal_loop_resumption_checkpoint.task_packet_id'));
        $this->assertSame($result['lease_id'], data_get($result, 'terminal_loop_resumption_checkpoint.lease_id'));
        $this->assertNotEmpty($result['terminal_loop_resumption_checkpoint_hash']);
        $this->assertSame(
            $result['terminal_loop_resumption_checkpoint_hash'],
            data_get($result, 'terminal_loop_resumption_checkpoint.resumption_checkpoint_hash'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-task-lease-recovery-status',
            data_get($result, 'terminal_loop_resumption_checkpoint.resume_commands.recover_or_resume_current_packet'),
        );
        $this->assertStringContainsString('--agent-control-plane-task-lease-recovery-status', $result['resume_after_interruption_command']);
        $this->assertStringContainsString($result['task_packet_id'], $result['resume_after_interruption_command']);
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_terminal_loop_iteration_runbook.v1',
            data_get($result, 'terminal_loop_iteration_runbook.schema_version'),
        );
        $this->assertSame('ready_for_single_packet_iteration', data_get($result, 'terminal_loop_iteration_runbook.status'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_iteration_runbook.can_loop_without_chat_history'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_iteration_runbook.one_terminal_one_packet_at_a_time'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_iteration_runbook.auto_replenishment_runs_at_iteration_start'));
        $this->assertSame(6, count(data_get($result, 'terminal_loop_iteration_runbook.iteration_steps')));
        $this->assertSame([
            'inspect_or_recover_current_packet',
            'execute_one_shot_worker_prompt',
            'renew_lease_during_long_work',
            'write_structured_completion_evidence_json',
            'complete_current_dry_run',
            'claim_next_iteration',
        ], array_column(data_get($result, 'terminal_loop_iteration_runbook.iteration_steps'), 'id'));
        $this->assertSame(
            data_get($result, 'terminal_loop_resumption_checkpoint.resumption_checkpoint_hash'),
            data_get($result, 'terminal_loop_iteration_runbook.resumption_checkpoint_hash'),
        );
        $this->assertContains(
            'do_not_complete_without_structured_completion_evidence_json',
            data_get($result, 'terminal_loop_iteration_runbook.forbidden_loop_shortcuts'),
        );
        $this->assertContains(
            'terminal_loop_iteration_runbook_does_not_complete_packet',
            data_get($result, 'terminal_loop_iteration_runbook.non_execution_guarantees'),
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['terminal_loop_iteration_runbook_hash']);
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_terminal_loop_shell_recipe.v1',
            data_get($result, 'terminal_loop_shell_recipe.schema_version'),
        );
        $this->assertSame('shell_recipe_ready', data_get($result, 'terminal_loop_shell_recipe.status'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_shell_recipe.safe_to_copy_after_operator_review'));
        $this->assertFalse((bool) data_get($result, 'terminal_loop_shell_recipe.can_execute_from_bootstrap'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_shell_recipe.requires_operator_to_run_worker_prompt'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_shell_recipe.requires_operator_to_write_completion_evidence_json'));
        $this->assertSame(6, data_get($result, 'terminal_loop_shell_recipe.max_cycles_recommended'));
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-worker-bootstrap-status',
            data_get($result, 'terminal_loop_shell_recipe.cycle_commands.bootstrap_or_claim'),
        );
        $this->assertStringContainsString(
            'worker_prompt_full',
            implode("\n", data_get($result, 'terminal_loop_shell_recipe.copy_paste_shell_loop_skeleton')),
        );
        $this->assertContains(
            'terminal_loop_shell_recipe_does_not_execute_shell',
            data_get($result, 'terminal_loop_shell_recipe.non_execution_guarantees'),
        );
        $this->assertSame(
            $result['terminal_loop_iteration_runbook_hash'],
            data_get($result, 'terminal_loop_shell_recipe.iteration_runbook_hash'),
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['terminal_loop_shell_recipe_hash']);
        $this->assertSame('available', $result['auto_replenishment_status']);
    }

    public function test_multiple_bootstraps_can_claim_distinct_parallel_lanes(): void
    {
        $service = $this->service();

        $first = $service->bootstrap($this->context(), [
            'actor' => 'codex-terminal-1',
            'target_min_claimable_tasks' => 3,
            'max_new_tasks' => 3,
        ]);
        $second = $service->bootstrap($this->context(), [
            'actor' => 'claude-terminal-2',
            'target_min_claimable_tasks' => 3,
            'max_new_tasks' => 3,
        ]);

        $this->assertSame('ready_for_worker', $first['status']);
        $this->assertSame('ready_for_worker', $second['status']);
        $this->assertNotSame($first['task_packet_id'], $second['task_packet_id']);
        $this->assertNotSame($first['lease_id'], $second['lease_id']);

        $firstWriteSet = (array) data_get($first, 'one_shot_worker_packet.lease.write_set', []);
        $secondWriteSet = (array) data_get($second, 'one_shot_worker_packet.lease.write_set', []);
        $this->assertSame([], array_values(array_intersect($firstWriteSet, $secondWriteSet)));
    }

    public function test_bootstrap_can_be_isolated_by_queue_tag(): void
    {
        $service = $this->service();
        $first = $service->bootstrap($this->context(), [
            'actor' => 'tagged-codex',
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['isolated_terminal_lane_a'],
        ]);
        $second = $service->bootstrap($this->context(), [
            'actor' => 'tagged-claude',
            'target_min_claimable_tasks' => 2,
            'max_new_tasks' => 2,
            'queue_tags' => ['isolated_terminal_lane_b'],
        ]);

        $this->assertSame('ready_for_worker', $first['status']);
        $this->assertSame('ready_for_worker', $second['status']);
        $this->assertSame('isolated_terminal_lane_a', $first['claim_tag']);
        $this->assertSame('isolated_terminal_lane_b', $second['claim_tag']);
        $this->assertNotSame($first['task_packet_id'], $second['task_packet_id']);
        $this->assertContains('isolated_terminal_lane_a', (array) data_get($first, 'claim.queue_entry.tags', []));
        $this->assertContains('isolated_terminal_lane_b', (array) data_get($second, 'claim.queue_entry.tags', []));
        $this->assertStringContainsString('--queue-tag=isolated_terminal_lane_a', data_get($first, 'terminal_loop_operator_commands.long_running_loop_contract.next_iteration_command'));
        $this->assertStringContainsString('--target-min-claimable-tasks=1', data_get($first, 'terminal_loop_operator_commands.long_running_loop_contract.next_iteration_command'));
        $this->assertStringContainsString('--max-new-tasks=1', data_get($first, 'terminal_loop_operator_commands.long_running_loop_contract.next_iteration_command'));
        $this->assertTrue((bool) data_get($first, 'terminal_loop_operator_commands.long_running_loop_contract.next_iteration_preserves_queue_tags'));
        $this->assertTrue((bool) data_get($first, 'terminal_loop_operator_commands.long_running_loop_contract.next_iteration_preserves_replenishment_bounds'));
    }

    public function test_bootstrap_preview_does_not_replenish_claim_or_create_lease(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $leases = new AgentControlPlaneClaimLeaseRepository;
        $orchestrator = new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            $queue,
            $leases,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );
        $service = new AgentControlPlaneTerminalWorkerBootstrapService(
            new AgentControlPlaneTaskAutoReplenishmentService($orchestrator, $queue),
            $orchestrator,
            new AgentControlPlaneOneShotWorkerPacketService($leases, $queue),
            $queue,
            $leases,
        );

        $result = $service->bootstrap($this->context(), [
            'actor' => 'preview-terminal',
            'target_min_claimable_tasks' => 3,
            'max_new_tasks' => 3,
            'queue_tags' => ['preview_lane'],
            'preview_only' => true,
        ]);

        $this->assertSame('preview_blocked_no_claimable_task', $result['status']);
        $this->assertTrue($result['preview_only']);
        $this->assertSame('preview_only_not_run', $result['auto_replenishment_status']);
        $this->assertSame('preview_only_no_claim_attempted', $result['claim_event']);
        $this->assertFalse($result['runtime_claim_persisted']);
        $this->assertFalse($result['one_shot_worker_packet_ready']);
        $this->assertSame(0, $result['generated_task_count']);
        $this->assertSame(0, $result['preview_claimable_count']);
        $this->assertTrue($result['preview_would_replenish']);
        $this->assertSame(3, $result['preview_would_generate_task_count']);
        $this->assertSame([], $result['preview_claimable_packet_ids']);
        $this->assertSame(0, data_get($queue->registry(), 'total_count'));
        $this->assertSame(0, count($leases->activeLeases()));
        $this->assertStringContainsString('--agent-control-plane-terminal-worker-bootstrap-status', $result['preview_execute_bootstrap_command']);
        $this->assertStringContainsString('--queue-tag=preview_lane', $result['preview_execute_bootstrap_command']);
        $this->assertStringNotContainsString('--terminal-worker-bootstrap-preview', $result['preview_execute_bootstrap_command']);
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_terminal_loop_resumption_checkpoint.v1',
            data_get($result, 'terminal_loop_resumption_checkpoint.schema_version'),
        );
        $this->assertSame('preview_inspection', data_get($result, 'terminal_loop_resumption_checkpoint.current_step'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_resumption_checkpoint.preview_only'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_resumption_checkpoint.can_resume_without_chat_history'));
        $this->assertNotEmpty($result['terminal_loop_resumption_checkpoint_hash']);
        $this->assertSame('preview_iteration_plan_ready', data_get($result, 'terminal_loop_iteration_runbook.status'));
        $this->assertFalse((bool) data_get($result, 'terminal_loop_iteration_runbook.auto_replenishment_runs_at_iteration_start'));
        $this->assertSame(6, count(data_get($result, 'terminal_loop_iteration_runbook.iteration_steps')));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['terminal_loop_iteration_runbook_hash']);
        $this->assertSame('preview_shell_recipe_ready', data_get($result, 'terminal_loop_shell_recipe.status'));
        $this->assertTrue((bool) data_get($result, 'terminal_loop_shell_recipe.preview_only'));
        $this->assertFalse((bool) data_get($result, 'terminal_loop_shell_recipe.can_execute_from_bootstrap'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['terminal_loop_shell_recipe_hash']);
        $this->assertContains('terminal_worker_bootstrap_preview_does_not_replenish_queue', $result['non_execution_guarantees']);
        $this->assertContains('terminal_worker_bootstrap_preview_does_not_claim_lease', $result['non_execution_guarantees']);
    }

    public function test_bootstrap_preview_reports_existing_claimable_packets_without_claiming(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $leases = new AgentControlPlaneClaimLeaseRepository;
        $orchestrator = new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            $queue,
            $leases,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );
        $service = new AgentControlPlaneTerminalWorkerBootstrapService(
            new AgentControlPlaneTaskAutoReplenishmentService($orchestrator, $queue),
            $orchestrator,
            new AgentControlPlaneOneShotWorkerPacketService($leases, $queue),
            $queue,
            $leases,
        );
        $packet = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_task_packet.v1',
            'task_packet_id' => 'preview-existing-packet',
            'task_packet_hash' => hash('sha256', 'preview-existing-packet'),
            'status' => 'planned',
            'objective' => 'Preview should see this packet without claiming it',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/PreviewOnly.php'],
            'normalized_scope' => [
                'allowed_files' => ['app/Services/Ai/SelfConstruction/PreviewOnly.php'],
            ],
            'acceptance_criteria' => ['preview_lists_claimable_packet'],
            'required_tests' => ['php artisan test --filter=preview_lists_claimable_packet'],
        ];
        $this->assertSame('ok', $queue->enqueue($packet, ['tags' => ['preview_existing_lane']])['status']);

        $result = $service->bootstrap([], [
            'actor' => 'preview-existing-terminal',
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 1,
            'queue_tags' => ['preview_existing_lane'],
            'preview_only' => true,
        ]);

        $this->assertSame('preview_available', $result['status']);
        $this->assertSame(1, $result['preview_claimable_count']);
        $this->assertSame(['preview-existing-packet'], $result['preview_claimable_packet_ids']);
        $this->assertFalse($result['preview_would_replenish']);
        $this->assertSame(0, $result['preview_would_generate_task_count']);
        $this->assertSame('claimable', data_get($queue->get('preview-existing-packet'), 'status'));
        $this->assertSame(0, count($leases->activeLeases()));
    }

    public function test_bootstrap_blocks_and_releases_lease_when_claimed_packet_has_invalid_worker_scope(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $leases = new AgentControlPlaneClaimLeaseRepository;
        $orchestrator = new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            $queue,
            $leases,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );
        $service = new AgentControlPlaneTerminalWorkerBootstrapService(
            new AgentControlPlaneTaskAutoReplenishmentService($orchestrator, $queue),
            $orchestrator,
            new AgentControlPlaneOneShotWorkerPacketService($leases, $queue),
            $queue,
            $leases,
        );
        $packet = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_task_packet.v1',
            'task_packet_id' => 'invalid-worker-scope-packet',
            'task_packet_hash' => hash('sha256', 'invalid-worker-scope-packet'),
            'status' => 'planned',
            'objective' => 'Invalid worker scope should never reach a terminal worker',
            'allowed_files' => [],
            'normalized_scope' => [
                'allowed_files' => [],
            ],
            'acceptance_criteria' => ['blocked_before_worker_handoff'],
            'required_tests' => ['php artisan test --filter=invalid_worker_scope'],
        ];
        $enqueue = $queue->enqueue($packet, ['tags' => ['invalid_worker_scope_lane']]);
        $this->assertSame('ok', $enqueue['status']);

        $result = $service->bootstrap([], [
            'actor' => 'invalid-scope-worker',
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 0,
            'queue_tags' => ['invalid_worker_scope_lane'],
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('claimed', $result['claim_event']);
        $this->assertFalse($result['one_shot_worker_packet_ready']);
        $this->assertSame('unsafe_worker_scope', $result['worker_packet_blocked_reason']);
        $this->assertContains('allowed_files_empty', $result['worker_packet_scope_blockers']);
        $this->assertTrue($result['lease_released_after_worker_packet_blocked']);
        $this->assertSame('ok', data_get($result, 'blocked_worker_packet_lease_release.release.status'));
        $this->assertSame('worker_packet_blocked_before_handoff', data_get($result, 'blocked_worker_packet_lease_release.release.lease.release_reason'));
        $this->assertSame('released', data_get($queue->get('invalid-worker-scope-packet'), 'status'));
        $this->assertSame(0, count($leases->activeLeases()));
    }

    public function test_bootstrap_blocks_before_claim_when_task_supply_is_below_target(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $leases = new AgentControlPlaneClaimLeaseRepository;
        $orchestrator = new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            $queue,
            $leases,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );
        $service = new AgentControlPlaneTerminalWorkerBootstrapService(
            new AgentControlPlaneTaskAutoReplenishmentService($orchestrator, $queue),
            $orchestrator,
            new AgentControlPlaneOneShotWorkerPacketService($leases, $queue),
            $queue,
            $leases,
        );
        $packet = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_task_packet.v1',
            'task_packet_id' => 'partial-supply-worker-packet',
            'task_packet_hash' => hash('sha256', 'partial-supply-worker-packet'),
            'status' => 'planned',
            'objective' => 'Partial supply should block worker launch before any claim is persisted',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/PartialSupply.php'],
            'normalized_scope' => [
                'allowed_files' => ['app/Services/Ai/SelfConstruction/PartialSupply.php'],
            ],
            'acceptance_criteria' => ['partial_supply_blocks_before_claim'],
            'required_tests' => ['php artisan test --filter=partial_supply_blocks_before_claim'],
        ];
        $this->assertSame('ok', $queue->enqueue($packet, ['tags' => ['partial_supply_lane']])['status']);

        $result = $service->bootstrap([], [
            'actor' => 'partial-supply-worker',
            'target_min_claimable_tasks' => 3,
            'max_new_tasks' => 0,
            'queue_tags' => ['partial_supply_lane'],
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('task_supply_below_target_blocked_before_claim', $result['claim_event']);
        $this->assertFalse($result['runtime_claim_persisted']);
        $this->assertFalse($result['one_shot_worker_packet_ready']);
        $this->assertSame('task_supply_below_target_blocked_before_claim', $result['worker_packet_blocked_reason']);
        $this->assertContains('claimable_task_count_below_target_min', $result['worker_packet_scope_blockers']);
        $this->assertContains('run_replenishment_and_recheck_health_digest_before_claim', $result['worker_packet_scope_blockers']);
        $this->assertSame('task_supply_below_target_guard_blocked', data_get($result, 'claim.reason'));
        $this->assertSame('claimable', data_get($queue->get('partial-supply-worker-packet'), 'status'));
        $this->assertSame(0, count($leases->activeLeases()));
        $this->assertSame('claim_or_replenishment_blocked', data_get($result, 'terminal_loop_resumption_checkpoint.current_step'));
        $this->assertContains('terminal_worker_bootstrap_task_supply_gate_blocks_before_claim', $result['non_execution_guarantees']);
    }

    public function test_bootstrap_blocks_before_claim_when_lane_contains_operator_handoff_task(): void
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $leases = new AgentControlPlaneClaimLeaseRepository;
        $orchestrator = new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            $queue,
            $leases,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );
        $service = new AgentControlPlaneTerminalWorkerBootstrapService(
            new AgentControlPlaneTaskAutoReplenishmentService($orchestrator, $queue),
            $orchestrator,
            new AgentControlPlaneOneShotWorkerPacketService($leases, $queue),
            $queue,
            $leases,
        );
        $packet = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_task_packet.v1',
            'task_packet_id' => 'operator-only-completion-blocker',
            'task_packet_hash' => hash('sha256', 'operator-only-completion-blocker'),
            'status' => 'planned',
            'objective' => 'Operator-only blocker must not be handed to a worker terminal',
            'allowed_files' => ['docs/engineering-knowledge-base/self-construction/operator-only.md'],
            'normalized_scope' => [
                'allowed_files' => ['docs/engineering-knowledge-base/self-construction/operator-only.md'],
            ],
            'continuation_context' => [
                'worker_executable' => false,
                'operator_handoff_required' => true,
                'auto_replenishment_reference' => 'human_signed_os_complete_receipt_present',
            ],
            'acceptance_criteria' => ['operator_receipt_required'],
            'required_tests' => ['operator_receipt_is_external'],
        ];
        $this->assertSame('ok', $queue->enqueue($packet, ['tags' => ['operator_only_lane']])['status']);

        $result = $service->bootstrap([], [
            'actor' => 'operator-only-worker',
            'target_min_claimable_tasks' => 1,
            'max_new_tasks' => 0,
            'queue_tags' => ['operator_only_lane'],
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('worker_task_eligibility_blocked_before_claim', $result['claim_event']);
        $this->assertFalse($result['runtime_claim_persisted']);
        $this->assertFalse($result['one_shot_worker_packet_ready']);
        $this->assertSame('blocked', data_get($result, 'worker_task_eligibility_guard.status'));
        $this->assertSame(3, data_get($result, 'worker_task_eligibility_guard.violation_count'));
        $this->assertContains('claimable_task_not_worker_executable', data_get($result, 'worker_task_eligibility_guard.blocked_reasons'));
        $this->assertContains('claimable_task_requires_operator_handoff', data_get($result, 'worker_task_eligibility_guard.blocked_reasons'));
        $this->assertContains('claimable_task_references_operator_only_completion_blocker', data_get($result, 'worker_task_eligibility_guard.blocked_reasons'));
        $this->assertSame('claimable', data_get($queue->get('operator-only-completion-blocker'), 'status'));
        $this->assertSame(0, count($leases->activeLeases()));
        $this->assertSame('worker_task_eligibility_blocked_before_claim', data_get($result, 'terminal_loop_resumption_checkpoint.current_step'));
        $this->assertContains('terminal_worker_bootstrap_worker_task_eligibility_guard_blocks_before_claim', $result['non_execution_guarantees']);
    }

    public function test_runtime_flags_remain_false(): void
    {
        $result = $this->service()->bootstrap($this->context(), [
            'actor' => 'safe-terminal',
        ]);

        foreach ([
            'runtime_execution_allowed',
            'dispatch_allowed',
            'provider_call_allowed',
            'token_spend_allowed',
            'self_programming_allowed',
            'ledger_write_allowed',
            'completion_real_allowed',
        ] as $flag) {
            $this->assertFalse((bool) $result[$flag], $flag);
        }
        $this->assertContains('terminal_worker_bootstrap_does_not_call_provider', $result['non_execution_guarantees']);
        $this->assertContains('terminal_worker_bootstrap_does_not_dispatch_work', $result['non_execution_guarantees']);
    }

    public function test_cli_status_and_quartet_are_exposed(): void
    {
        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-terminal-worker-bootstrap-status' => true,
            '--actor' => 'cli-terminal',
            '--target-min-claimable-tasks' => 1,
            '--max-new-tasks' => 1,
            '--queue-tag' => ['cli_terminal_lane'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction_agent_control_plane_terminal_worker_bootstrap_status.v1', $payload['schema_version']);
        $this->assertSame('ready_for_worker', data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.status'));
        $this->assertSame('cli-terminal', data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.actor'));
        $this->assertTrue((bool) data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.runtime_claim_persisted'));
        $this->assertTrue((bool) data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.one_shot_worker_packet_ready'));
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_terminal_worker_bootstrap.worker_prompt_full'));
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_task_queue_completion_evidence.v1',
            data_get($payload, 'agent_control_plane_terminal_worker_bootstrap.completion_evidence_template.schema_version'),
        );
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_terminal_loop_operator_commands.v1',
            data_get($payload, 'agent_control_plane_terminal_worker_bootstrap.terminal_loop_operator_commands.schema_version'),
        );
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_terminal_long_running_loop_contract.v1',
            data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.terminal_loop_long_running_contract_schema'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-worker-bootstrap-status',
            data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.terminal_loop_next_iteration_command'),
        );
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_terminal_loop_resumption_checkpoint.v1',
            data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.terminal_loop_resumption_checkpoint_schema'),
        );
        $this->assertSame(
            'claimed_packet_ready_for_one_shot_worker',
            data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.terminal_loop_resumption_current_step'),
        );
        $this->assertTrue((bool) data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.terminal_loop_can_resume_without_chat_history'));
        $this->assertNotEmpty(data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.terminal_loop_resumption_checkpoint_hash'));
        $this->assertStringContainsString(
            '--queue-tag=cli_terminal_lane',
            data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.terminal_loop_next_iteration_command'),
        );
        $this->assertStringContainsString(
            '--target-min-claimable-tasks=1',
            data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.terminal_loop_next_iteration_command'),
        );
        $this->assertStringContainsString(
            '--max-new-tasks=1',
            data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.terminal_loop_next_iteration_command'),
        );
        $this->assertContains(
            'completion_evidence_validation_not_valid',
            data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.terminal_loop_stop_conditions'),
        );
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_terminal_loop_iteration_runbook.v1',
            data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.terminal_loop_iteration_runbook_schema'),
        );
        $this->assertSame(
            'ready_for_single_packet_iteration',
            data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.terminal_loop_iteration_runbook_status'),
        );
        $this->assertSame(6, data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.terminal_loop_iteration_step_count'));
        $this->assertTrue((bool) data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.terminal_loop_can_loop_without_chat_history'));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.terminal_loop_iteration_runbook_hash'),
        );
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_terminal_loop_shell_recipe.v1',
            data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.terminal_loop_shell_recipe_schema'),
        );
        $this->assertSame(
            'shell_recipe_ready',
            data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.terminal_loop_shell_recipe_status'),
        );
        $this->assertTrue((bool) data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.terminal_loop_shell_recipe_safe_to_copy_after_operator_review'));
        $this->assertFalse((bool) data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.terminal_loop_shell_recipe_can_execute_from_bootstrap'));
        $this->assertTrue((bool) data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.terminal_loop_shell_recipe_requires_operator_to_run_worker_prompt'));
        $this->assertSame(6, data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.terminal_loop_shell_recipe_max_cycles_recommended'));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.terminal_loop_shell_recipe_hash'),
        );

        foreach (['contract', 'preflight', 'implementation-packet'] as $stage) {
            Artisan::call('atlas:ai:self-construction', [
                "--agent-control-plane-terminal-worker-bootstrap-{$stage}" => true,
                '--json' => true,
            ]);
            $quartet = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $stageKey = str_replace('-', '_', $stage);
            $this->assertSame("atlas.self_construction_agent_control_plane_terminal_worker_bootstrap_{$stageKey}.v1", $quartet['schema_version']);
        }
    }

    public function test_cli_preview_status_is_read_only(): void
    {
        $queueCountBefore = (int) (new AgentControlPlaneTaskPacketQueueRepository)->registry()['total_count'];
        $activeLeaseCountBefore = count((new AgentControlPlaneClaimLeaseRepository)->activeLeases());

        Artisan::call('atlas:ai:self-construction', [
            '--agent-control-plane-terminal-worker-bootstrap-status' => true,
            '--terminal-worker-bootstrap-preview' => true,
            '--actor' => 'cli-preview-terminal',
            '--target-min-claimable-tasks' => 2,
            '--max-new-tasks' => 2,
            '--queue-tag' => ['cli_preview_lane'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.self_construction_agent_control_plane_terminal_worker_bootstrap_status.v1', $payload['schema_version']);
        $this->assertSame('preview_blocked_no_claimable_task', data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.status'));
        $this->assertTrue((bool) data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.preview_only'));
        $this->assertFalse((bool) data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.runtime_claim_persisted'));
        $this->assertSame('preview_only_no_claim_attempted', data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.claim_event'));
        $this->assertSame(0, data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.preview_claimable_count'));
        $this->assertTrue((bool) data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.preview_would_replenish'));
        $this->assertSame(2, data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.preview_would_generate_task_count'));
        $this->assertSame($queueCountBefore, data_get($payload, 'agent_control_plane_terminal_worker_bootstrap.queue_summary.total_count'));
        $this->assertSame($activeLeaseCountBefore, data_get($payload, 'agent_control_plane_terminal_worker_bootstrap.lease_summary.active_lease_count'));
        $this->assertSame([], (new AgentControlPlaneTaskPacketQueueRepository)->list(['tag' => 'cli_preview_lane']));
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-worker-bootstrap-status',
            data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.preview_execute_bootstrap_command'),
        );
        $this->assertStringNotContainsString(
            '--terminal-worker-bootstrap-preview',
            data_get($payload, 'agent_control_plane_terminal_worker_bootstrap_status.preview_execute_bootstrap_command'),
        );
    }

    private function service(): AgentControlPlaneTerminalWorkerBootstrapService
    {
        $queue = new AgentControlPlaneTaskPacketQueueRepository;
        $leases = new AgentControlPlaneClaimLeaseRepository;
        $orchestrator = new AgentControlPlaneTaskQueueOrchestrator(
            new AgentControlPlaneTaskPacketBuilder,
            new AgentControlPlaneScopeLockRuntimeValidator,
            $queue,
            $leases,
            new AgentControlPlaneEvidenceLedgerDryRun,
            new AgentControlPlaneContinuationSummaryBuilder,
        );

        return new AgentControlPlaneTerminalWorkerBootstrapService(
            new AgentControlPlaneTaskAutoReplenishmentService($orchestrator, $queue),
            $orchestrator,
            new AgentControlPlaneOneShotWorkerPacketService($leases, $queue),
            $queue,
            $leases,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function context(): array
    {
        return [
            'control_plane' => [
                'control_plane' => [
                    'persistent_runtime' => [
                        'next_required_slice' => 'activate_signed_one_shot_scheduler_tick_codex_real_invoker_post_start_receipt_contract',
                    ],
                    'not_yet_runtime_capable' => [
                        'adapter_execution_runtime',
                        'automatic_cost_import_runtime',
                    ],
                ],
            ],
            'completion_audit' => [
                'failed_criteria' => [
                    'runtime_gap_matrix_all_runtime_y',
                    'end_to_end_real_provider_smoke_green',
                ],
            ],
        ];
    }
}
