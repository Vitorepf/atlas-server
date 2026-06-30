<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\TerminalWorkerBootstrap\AgentControlPlaneTerminalLoopGuidanceBuilder;
use PHPUnit\Framework\TestCase;

/**
 * ITEM8 — proves the cohesive read-only terminal-loop guidance-artifact builder concern extracted
 * from AgentControlPlaneTerminalWorkerBootstrapService into AgentControlPlaneTerminalLoopGuidanceBuilder.
 *
 * Seven methods migrated verbatim:
 *  - terminalLoopOperatorCommands: the wide operator-commands artefact.
 *  - queueLaneContract: per-terminal-lane contract (with stable hash).
 *  - terminalLoopResumptionCheckpoint: resumable-state checkpoint (with stable hash).
 *  - terminalLoopIterationRunbook: per-iteration runbook (with stable hash).
 *  - iterationStep: one step in the runbook.
 *  - terminalLoopShellRecipe: copy-paste shell loop skeleton (with stable hash).
 *  - terminalLoopCurrentStep: derive human-readable current step.
 *
 * Pure / stateless — pure PHPUnit suffices.
 */
final class AgentControlPlaneTerminalLoopGuidanceBuilderTest extends TestCase
{
    private AgentControlPlaneTerminalLoopGuidanceBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new AgentControlPlaneTerminalLoopGuidanceBuilder;
    }

    // --- terminalLoopCurrentStep -----------------------------------------

    public function test_current_step_returns_preview_inspection_when_preview_only(): void
    {
        $this->assertSame(
            'preview_inspection',
            $this->builder->terminalLoopCurrentStep('ready_for_worker', 'claimed', true, true),
        );
    }

    public function test_current_step_returns_worker_task_eligibility_blocked_for_specific_claim_event(): void
    {
        $this->assertSame(
            'worker_task_eligibility_blocked_before_claim',
            $this->builder->terminalLoopCurrentStep(
                'ready_for_worker',
                'worker_task_eligibility_blocked_before_claim',
                true,
                false,
            ),
        );
    }

    public function test_current_step_returns_claimed_ready_for_one_shot_worker_when_all_signals_match(): void
    {
        $this->assertSame(
            'claimed_packet_ready_for_one_shot_worker',
            $this->builder->terminalLoopCurrentStep('ready_for_worker', 'claimed', true, false),
        );
    }

    public function test_current_step_returns_claimed_blocked_when_one_shot_packet_not_ready(): void
    {
        $this->assertSame(
            'claimed_packet_blocked_before_worker_handoff',
            $this->builder->terminalLoopCurrentStep('ready_for_worker', 'claimed', false, false),
        );
    }

    public function test_current_step_returns_default_when_no_signals_match(): void
    {
        $this->assertSame(
            'claim_or_replenishment_blocked',
            $this->builder->terminalLoopCurrentStep('blocked', 'no_signal', false, false),
        );
    }

    // --- iterationStep --------------------------------------------------

    public function test_iteration_step_emits_six_keys_with_command_hash_when_command_present(): void
    {
        $step = $this->builder->iterationStep(
            'renew_lease',
            'lease_maintenance',
            'php artisan lease:renew',
            'run_before_lease_expires',
            ['renewed_lease'],
            false,
        );

        $this->assertSame('renew_lease', $step['id']);
        $this->assertSame('lease_maintenance', $step['phase']);
        $this->assertSame('php artisan lease:renew', $step['command']);
        $this->assertSame(hash('sha256', 'php artisan lease:renew'), $step['command_hash']);
        $this->assertSame('run_before_lease_expires', $step['operator_action']);
        $this->assertSame(['renewed_lease'], $step['produces']);
        $this->assertFalse($step['can_run_automatically']);
    }

    public function test_iteration_step_emits_empty_command_hash_when_command_is_empty(): void
    {
        $step = $this->builder->iterationStep(
            'execute_worker_prompt',
            'worker_execution',
            '',
            'operator_runs_prompt_manually',
            ['work_product'],
            false,
        );

        $this->assertSame('', $step['command']);
        $this->assertSame('', $step['command_hash']);
    }

    // --- queueLaneContract ---------------------------------------------

    public function test_queue_lane_contract_emits_global_untagged_lane_for_empty_tags(): void
    {
        $contract = $this->builder->queueLaneContract('hermes-1', [], 'php artisan x');

        $this->assertSame('atlas.self_construction.agent_control_plane_terminal_loop_queue_lane_contract.v1', $contract['schema_version']);
        $this->assertSame('global', $contract['queue_lane_id']);
        $this->assertFalse($contract['queue_lane_explicit']);
        $this->assertSame('global_untagged_lane', $contract['queue_lane_mode']);
        $this->assertSame('', $contract['primary_queue_tag']);
        $this->assertFalse($contract['next_iteration_command_contains_primary_queue_tag']);
        $this->assertTrue($contract['next_iteration_preserves_queue_lane'], 'no --queue-tag in command => preserves lane');
        $this->assertSame('operator_should_pass_queue_tag_for_parallel_terminal_fleets', $contract['parallel_fleet_recommendation']);
        $this->assertFalse($contract['can_switch_lane_from_contract']);
        $this->assertFalse($contract['can_claim_from_contract']);
    }

    public function test_queue_lane_contract_emits_explicit_tagged_lane_for_non_empty_tags(): void
    {
        $contract = $this->builder->queueLaneContract(
            'hermes-1',
            ['terminal-loop-foo', 'terminal-loop-bar'],
            'php artisan x --queue-tag=terminal-loop-foo',
        );

        $this->assertSame('terminal-loop-foo', $contract['queue_lane_id']);
        $this->assertTrue($contract['queue_lane_explicit']);
        $this->assertSame('explicit_tagged_lane', $contract['queue_lane_mode']);
        $this->assertSame('terminal-loop-foo', $contract['primary_queue_tag']);
        $this->assertTrue($contract['next_iteration_command_contains_primary_queue_tag']);
        $this->assertTrue($contract['next_iteration_preserves_queue_lane']);
        $this->assertSame('safe_parallel_lane_explicitly_scoped', $contract['parallel_fleet_recommendation']);
        $this->assertSame('terminal-loop-hermes-1', $contract['recommended_queue_tag']);
    }

    public function test_queue_lane_contract_emits_stable_hash_for_audit_traceability(): void
    {
        $a = $this->builder->queueLaneContract('hermes-1', ['tag1'], 'cmd');
        $b = $this->builder->queueLaneContract('hermes-1', ['tag1'], 'cmd');

        $this->assertSame($a['queue_lane_contract_hash'], $b['queue_lane_contract_hash'], 'same inputs => same hash');
    }

    public function test_queue_lane_contract_includes_four_non_execution_guarantees(): void
    {
        $contract = $this->builder->queueLaneContract('a', [], 'cmd');

        $this->assertCount(4, $contract['non_execution_guarantees']);
        $this->assertContains('queue_lane_contract_does_not_replenish_queue', $contract['non_execution_guarantees']);
        $this->assertContains('queue_lane_contract_does_not_dispatch_work', $contract['non_execution_guarantees']);
    }

    // --- terminalLoopOperatorCommands ----------------------------------

    public function test_operator_commands_emits_canonical_keys_with_all_four_pillars(): void
    {
        $cmds = $this->builder->terminalLoopOperatorCommands(
            'hermes-1', 'TP1', 'L1',
            'php artisan complete', 'php artisan renew', 'php artisan recover', 'php artisan bootstrap',
            3, 7,
            ['tag1'], ['queue_lane_explicit' => false, 'queue_lane_id' => 'global'],
        );

        $this->assertSame('atlas.self_construction.agent_control_plane_terminal_loop_operator_commands.v1', $cmds['schema_version']);
        $this->assertSame('hermes-1', $cmds['actor']);
        $this->assertSame(3, $cmds['target_min_claimable_tasks']);
        $this->assertSame(7, $cmds['max_new_tasks']);
        $this->assertSame(['tag1'], $cmds['queue_tags']);
        $this->assertSame('TP1', $cmds['task_packet_id']);
        $this->assertSame('L1', $cmds['lease_id']);
        $this->assertSame('php artisan bootstrap', $cmds['claim_or_replenish_next']);
        $this->assertSame('php artisan renew', $cmds['renew_current_lease']);
        $this->assertSame('php artisan complete', $cmds['complete_current_dry_run']);
        $this->assertSame('php artisan recover', $cmds['recover_or_resume_current_packet']);
    }

    public function test_operator_commands_emits_long_running_loop_contract_with_canonical_pillars(): void
    {
        $cmds = $this->builder->terminalLoopOperatorCommands(
            'a', 'TP', 'L', 'c', 'r', 'x', 'b', 1, 5, [], ['queue_lane_explicit' => false],
        );

        $lrl = $cmds['long_running_loop_contract'];
        $this->assertSame('atlas.self_construction.agent_control_plane_terminal_long_running_loop_contract.v1', $lrl['schema_version']);
        $this->assertSame(600, $lrl['lease_renewal_cadence_seconds']);
        $this->assertSame(600, $lrl['renew_before_seconds_remaining']);
        $this->assertSame(240, $lrl['max_single_packet_minutes']);
        $this->assertCount(3, $lrl['pre_iteration_checks']);
        $this->assertCount(3, $lrl['post_completion_checks']);
        $this->assertCount(5, $lrl['stop_conditions']);
    }

    public function test_operator_commands_inspect_active_leases_includes_queue_tag_args(): void
    {
        $cmds = $this->builder->terminalLoopOperatorCommands(
            'hermes-1', 'TP', 'L', 'c', 'r', 'x', 'b', 1, 5, ['terminal-loop-foo'], [],
        );

        $this->assertStringContainsString('--actor=hermes-1', $cmds['inspect_active_leases']);
        $this->assertStringContainsString('--queue-tag=terminal-loop-foo', $cmds['inspect_active_leases']);
    }

    public function test_operator_commands_inspect_queue_uses_canonical_homebrew_php_path(): void
    {
        $cmds = $this->builder->terminalLoopOperatorCommands(
            'hermes-1', 'TP', 'L', 'c', 'r', 'x', 'b', 1, 5, [], [],
        );

        $this->assertStringStartsWith(
            AgentControlPlaneTerminalLoopGuidanceBuilder::PHP_BIN.' artisan',
            $cmds['inspect_queue'],
        );
    }

    public function test_operator_commands_inspect_active_leases_uses_canonical_homebrew_php_path(): void
    {
        $cmds = $this->builder->terminalLoopOperatorCommands(
            'hermes-1', 'TP', 'L', 'c', 'r', 'x', 'b', 1, 5, [], [],
        );

        $this->assertStringStartsWith(
            AgentControlPlaneTerminalLoopGuidanceBuilder::PHP_BIN.' artisan',
            $cmds['inspect_active_leases'],
        );
    }

    public function test_operator_commands_emits_six_operator_loop_contract_lines(): void
    {
        $cmds = $this->builder->terminalLoopOperatorCommands(
            'a', 'TP', 'L', 'c', 'r', 'x', 'b', 1, 5, [], [],
        );

        $this->assertCount(6, $cmds['operator_loop_contract']);
        $this->assertContains('one_terminal_runs_one_packet_at_a_time', $cmds['operator_loop_contract']);
        $this->assertContains('never_reuse_expired_or_foreign_lease', $cmds['operator_loop_contract']);
    }

    // --- terminalLoopResumptionCheckpoint -------------------------------

    public function test_resumption_checkpoint_emits_schema_version_and_current_step(): void
    {
        $op = $this->builder->terminalLoopOperatorCommands(
            'hermes-1', 'TP1', 'L1', 'c', 'r', 'x', 'b', 1, 5, [], [],
        );
        $resumptionContract = ['resume_commands' => ['regenerate_current_one_shot_packet_if_lease_still_active' => 'php artisan regenerate']];

        $ck = $this->builder->terminalLoopResumptionCheckpoint(
            'ready_for_worker', 'hermes-1', 'TP1', 'L1', 'claimed',
            true, $op, $resumptionContract, false,
        );

        $this->assertSame('atlas.self_construction.agent_control_plane_terminal_loop_resumption_checkpoint.v1', $ck['schema_version']);
        $this->assertSame('claimed_packet_ready_for_one_shot_worker', $ck['current_step']);
        $this->assertSame('hermes-1', $ck['actor']);
        $this->assertSame('TP1', $ck['task_packet_id']);
        $this->assertSame('L1', $ck['lease_id']);
        $this->assertSame('claimed', $ck['claim_event']);
        $this->assertTrue($ck['one_shot_worker_packet_ready']);
        $this->assertFalse($ck['preview_only']);
        $this->assertTrue($ck['can_resume_without_chat_history']);
        $this->assertTrue($ck['requires_active_lease_before_editing']);
        $this->assertTrue($ck['requires_fresh_bootstrap_after_completion']);
        $this->assertTrue($ck['requires_structured_completion_evidence_before_complete_dry_run']);
    }

    public function test_resumption_checkpoint_echoes_resume_commands_from_operator_commands(): void
    {
        $op = $this->builder->terminalLoopOperatorCommands(
            'a', 'TP', 'L', 'complete-cmd', 'renew-cmd', 'recover-cmd', 'bootstrap-cmd', 1, 5, [], [],
        );

        $ck = $this->builder->terminalLoopResumptionCheckpoint(
            'ready_for_worker', 'a', 'TP', 'L', 'claimed', true, $op, [], false,
        );

        $this->assertSame('recover-cmd', $ck['resume_commands']['recover_or_resume_current_packet']);
        $this->assertSame('renew-cmd', $ck['resume_commands']['renew_current_lease']);
        $this->assertSame('complete-cmd', $ck['resume_commands']['complete_current_dry_run']);
        $this->assertSame('bootstrap-cmd', $ck['resume_commands']['claim_or_replenish_next']);
        $this->assertSame('bootstrap-cmd', $ck['resume_commands']['next_after_completion']);
    }

    public function test_resumption_checkpoint_default_required_resume_evidence_when_contract_omits(): void
    {
        $op = $this->builder->terminalLoopOperatorCommands('a', 'TP', 'L', 'c', 'r', 'x', 'b', 1, 5, [], []);
        $ck = $this->builder->terminalLoopResumptionCheckpoint('blocked', 'a', 'TP', 'L', 'no_signal', false, $op, [], false);

        $this->assertSame(
            ['last_git_status_short', 'recovery_command_output', 'new_lease_id_when_reclaimed'],
            $ck['required_resume_evidence'],
        );
        $this->assertCount(4, $ck['forbidden_resume_shortcuts']);
    }

    public function test_resumption_checkpoint_emits_stable_hash(): void
    {
        $op = $this->builder->terminalLoopOperatorCommands('a', 'TP', 'L', 'c', 'r', 'x', 'b', 1, 5, [], []);
        $a = $this->builder->terminalLoopResumptionCheckpoint('ready_for_worker', 'a', 'TP', 'L', 'claimed', true, $op, [], false);
        $b = $this->builder->terminalLoopResumptionCheckpoint('ready_for_worker', 'a', 'TP', 'L', 'claimed', true, $op, [], false);

        $this->assertSame($a['resumption_checkpoint_hash'], $b['resumption_checkpoint_hash']);
        $this->assertNotSame('', $a['resumption_checkpoint_hash']);
    }

    // --- terminalLoopIterationRunbook -----------------------------------

    public function test_iteration_runbook_emits_six_iteration_steps(): void
    {
        $op = $this->builder->terminalLoopOperatorCommands('a', 'TP', 'L', 'c', 'r', 'x', 'b', 1, 5, [], []);
        $ck = $this->builder->terminalLoopResumptionCheckpoint('ready_for_worker', 'a', 'TP', 'L', 'claimed', true, $op, [], false);

        $rb = $this->builder->terminalLoopIterationRunbook(
            'ready_for_worker', 'a', 'TP', 'L', true, false, $op, $ck,
        );

        $this->assertSame('atlas.self_construction.agent_control_plane_terminal_loop_iteration_runbook.v1', $rb['schema_version']);
        $this->assertSame('ready_for_single_packet_iteration', $rb['status']);
        $this->assertCount(6, $rb['iteration_steps']);
        $this->assertSame('preflight', $rb['iteration_steps'][0]['phase']);
        $this->assertSame('worker_execution', $rb['iteration_steps'][1]['phase']);
        $this->assertSame('lease_maintenance', $rb['iteration_steps'][2]['phase']);
        $this->assertSame('evidence', $rb['iteration_steps'][3]['phase']);
        $this->assertSame('completion', $rb['iteration_steps'][4]['phase']);
        $this->assertSame('next_iteration', $rb['iteration_steps'][5]['phase']);
    }

    public function test_iteration_runbook_status_blocked_when_one_shot_packet_not_ready(): void
    {
        $op = $this->builder->terminalLoopOperatorCommands('a', 'TP', 'L', 'c', 'r', 'x', 'b', 1, 5, [], []);
        $ck = $this->builder->terminalLoopResumptionCheckpoint('blocked', 'a', 'TP', 'L', 'no_signal', false, $op, [], false);

        $rb = $this->builder->terminalLoopIterationRunbook(
            'blocked', 'a', 'TP', 'L', false, false, $op, $ck,
        );

        $this->assertSame('blocked_before_worker_iteration', $rb['status']);
        $this->assertTrue($rb['auto_replenishment_runs_at_iteration_start'], '!previewOnly => auto_replenishment ON');
    }

    public function test_iteration_runbook_status_preview_when_preview_only(): void
    {
        $op = $this->builder->terminalLoopOperatorCommands('a', 'TP', 'L', 'c', 'r', 'x', 'b', 1, 5, [], []);
        $ck = $this->builder->terminalLoopResumptionCheckpoint('blocked', 'a', 'TP', 'L', 'no_signal', false, $op, [], true);

        $rb = $this->builder->terminalLoopIterationRunbook(
            'blocked', 'a', 'TP', 'L', false, true, $op, $ck,
        );

        $this->assertSame('preview_iteration_plan_ready', $rb['status']);
    }

    public function test_iteration_runbook_contains_runnable_proof_commands_with_homebrew_php(): void
    {
        $op = $this->builder->terminalLoopOperatorCommands('muscle-1', 'TP', 'L', 'c', 'r', 'x', 'b', 1, 5, [], []);
        $ck = $this->builder->terminalLoopResumptionCheckpoint('ready_for_worker', 'muscle-1', 'TP', 'L', 'claimed', true, $op, [], false);

        $rb = $this->builder->terminalLoopIterationRunbook('ready_for_worker', 'muscle-1', 'TP', 'L', true, false, $op, $ck);

        $this->assertArrayHasKey('runnable_proof_commands', $rb);
        $proofs = $rb['runnable_proof_commands'];
        $this->assertArrayHasKey('task_health', $proofs);
        $this->assertArrayHasKey('queued_targets', $proofs);
        $this->assertArrayHasKey('malformed_sweep', $proofs);
        $this->assertStringContainsString(AgentControlPlaneTerminalLoopGuidanceBuilder::PHP_BIN, $proofs['task_health']);
        $this->assertStringContainsString(AgentControlPlaneTerminalLoopGuidanceBuilder::PHP_BIN, $proofs['queued_targets']);
        $this->assertStringContainsString(AgentControlPlaneTerminalLoopGuidanceBuilder::PHP_BIN, $proofs['malformed_sweep']);
    }

    public function test_iteration_runbook_emits_stable_hash(): void
    {
        $op = $this->builder->terminalLoopOperatorCommands('a', 'TP', 'L', 'c', 'r', 'x', 'b', 1, 5, [], []);
        $ck = $this->builder->terminalLoopResumptionCheckpoint('ready_for_worker', 'a', 'TP', 'L', 'claimed', true, $op, [], false);

        $a = $this->builder->terminalLoopIterationRunbook('ready_for_worker', 'a', 'TP', 'L', true, false, $op, $ck);
        $b = $this->builder->terminalLoopIterationRunbook('ready_for_worker', 'a', 'TP', 'L', true, false, $op, $ck);

        $this->assertSame($a['iteration_runbook_hash'], $b['iteration_runbook_hash']);
    }

    // --- terminalLoopShellRecipe ---------------------------------------

    public function test_shell_recipe_emits_canonical_keys_with_max_cycles_and_invariants(): void
    {
        $op = $this->builder->terminalLoopOperatorCommands('a', 'TP', 'L', 'c', 'r', 'x', 'b', 1, 5, [], []);
        $ck = $this->builder->terminalLoopResumptionCheckpoint('ready_for_worker', 'a', 'TP', 'L', 'claimed', true, $op, [], false);
        $rb = $this->builder->terminalLoopIterationRunbook('ready_for_worker', 'a', 'TP', 'L', true, false, $op, $ck);

        $recipe = $this->builder->terminalLoopShellRecipe('hermes-1', false, $op, $rb);

        $this->assertSame('atlas.self_construction.agent_control_plane_terminal_loop_shell_recipe.v1', $recipe['schema_version']);
        $this->assertSame('read_only_terminal_loop_shell_recipe', $recipe['mode']);
        $this->assertSame('shell_recipe_ready', $recipe['status']);
        $this->assertSame('hermes-1', $recipe['actor']);
        $this->assertSame(6, $recipe['max_cycles_recommended']);
        $this->assertCount(4, $recipe['cycle_commands']);
        $this->assertCount(4, $recipe['manual_slots']);
        $this->assertCount(9, $recipe['copy_paste_shell_loop_skeleton']);
        $this->assertCount(7, $recipe['loop_invariants']);
        $this->assertCount(7, $recipe['non_execution_guarantees']);
    }

    public function test_shell_recipe_status_preview_when_preview_only(): void
    {
        $op = $this->builder->terminalLoopOperatorCommands('a', 'TP', 'L', 'c', 'r', 'x', 'b', 1, 5, [], []);
        $recipe = $this->builder->terminalLoopShellRecipe('a', true, $op, []);

        $this->assertSame('preview_shell_recipe_ready', $recipe['status']);
        $this->assertTrue($recipe['preview_only']);
    }

    public function test_shell_recipe_emits_stable_hash(): void
    {
        $op = $this->builder->terminalLoopOperatorCommands('a', 'TP', 'L', 'c', 'r', 'x', 'b', 1, 5, [], []);
        $ck = $this->builder->terminalLoopResumptionCheckpoint('ready_for_worker', 'a', 'TP', 'L', 'claimed', true, $op, [], false);
        $rb = $this->builder->terminalLoopIterationRunbook('ready_for_worker', 'a', 'TP', 'L', true, false, $op, $ck);

        $a = $this->builder->terminalLoopShellRecipe('hermes-1', false, $op, $rb);
        $b = $this->builder->terminalLoopShellRecipe('hermes-1', false, $op, $rb);

        $this->assertSame($a['shell_recipe_hash'], $b['shell_recipe_hash']);
    }

    public function test_shell_recipe_shell_skeleton_includes_bootstrap_command(): void
    {
        $op = $this->builder->terminalLoopOperatorCommands('a', 'TP', 'L', 'c', 'r', 'x', 'php artisan bootstrap --actor=a', 1, 5, [], []);
        $recipe = $this->builder->terminalLoopShellRecipe('a', false, $op, []);

        $this->assertContains('  php artisan bootstrap --actor=a', $recipe['copy_paste_shell_loop_skeleton']);
    }
}
