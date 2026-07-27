<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\UnattendedRuntime;

use App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedRecoveryActionPlanner;
use App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedStallClassifier;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasSelfConstructionUnattendedRecoveryActionPlannerTest extends TestCase
{
    private function planner(): AtlasSelfConstructionUnattendedRecoveryActionPlanner
    {
        return new AtlasSelfConstructionUnattendedRecoveryActionPlanner;
    }

    private function classification(string $label): array
    {
        return ['classification' => $label, 'recovery_needed' => true];
    }

    // ── unsafe_stop emergency override ───────────────────────────────────────────

    public function test_unsafe_stop_requires_emergency_override_and_emits_safety_stop(): void
    {
        $plan = $this->planner()->plan($this->classification(AtlasSelfConstructionUnattendedStallClassifier::UNSAFE_STOP));

        self::assertTrue($plan['requires_emergency_override']);
        self::assertSame(
            AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_SAFETY_STOP,
            $plan['actions'][0]['action'],
        );
        self::assertSame(
            AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_APPLY_SAFE_REPLENISHER_PLAN,
            $plan['blocked_actions'][0]['action'],
        );
    }

    public function test_requires_emergency_override_is_false_for_every_non_unsafe_stop_classification(): void
    {
        $planner = $this->planner();

        foreach ([
            AtlasSelfConstructionUnattendedStallClassifier::QUEUE_DRY,
            AtlasSelfConstructionUnattendedStallClassifier::WAITING_ON_DEPENDENCIES,
            AtlasSelfConstructionUnattendedStallClassifier::HEARTBEAT_STALE,
            AtlasSelfConstructionUnattendedStallClassifier::WORKER_UNAVAILABLE,
            AtlasSelfConstructionUnattendedStallClassifier::VERIFICATION_BLOCKED,
            AtlasSelfConstructionUnattendedStallClassifier::MERGE_BLOCKED,
            AtlasSelfConstructionUnattendedStallClassifier::REPLENISHER_BLOCKED,
            AtlasSelfConstructionUnattendedStallClassifier::HEALTHY,
        ] as $label) {
            $plan = $planner->plan(['classification' => $label, 'recovery_needed' => $label !== AtlasSelfConstructionUnattendedStallClassifier::HEALTHY]);

            self::assertFalse($plan['requires_emergency_override'], "unexpected emergency override for {$label}");
        }
    }

    // ── queue_dry replenisher plan ────────────────────────────────────────────────

    public function test_queue_dry_emits_dry_run_then_apply_safe_replenisher_plan(): void
    {
        $plan = $this->planner()->plan($this->classification(AtlasSelfConstructionUnattendedStallClassifier::QUEUE_DRY));

        $actions = array_column($plan['actions'], 'action');
        self::assertSame([
            AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RUN_REPLENISHER_DRY_RUN,
            AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_APPLY_SAFE_REPLENISHER_PLAN,
        ], $actions);
    }

    // ── waiting_on_dependencies wait action ──────────────────────────────────────

    public function test_waiting_on_dependencies_emits_wait_for_dependencies(): void
    {
        $plan = $this->planner()->plan($this->classification(AtlasSelfConstructionUnattendedStallClassifier::WAITING_ON_DEPENDENCIES));

        self::assertSame(
            [AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_WAIT_FOR_DEPENDENCIES],
            array_column($plan['actions'], 'action'),
        );
    }

    // ── heartbeat_stale lease recovery ───────────────────────────────────────────

    public function test_heartbeat_stale_emits_recover_expired_leases(): void
    {
        $plan = $this->planner()->plan($this->classification(AtlasSelfConstructionUnattendedStallClassifier::HEARTBEAT_STALE));

        self::assertSame(
            [AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RECOVER_EXPIRED_LEASES],
            array_column($plan['actions'], 'action'),
        );
    }

    // ── brain_quota must_run_now / done temp spec cleanup ────────────────────────

    public function test_brain_quota_must_run_now_emits_run_brain_must_run_now_action(): void
    {
        $plan = $this->planner()->plan(
            ['classification' => AtlasSelfConstructionUnattendedStallClassifier::HEALTHY, 'recovery_needed' => false],
            ['facts' => ['brain_quota' => ['must_run_now' => true]]],
        );

        self::assertContains(
            AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RUN_BRAIN_MUST_RUN_NOW,
            array_column($plan['actions'], 'action'),
        );
    }

    public function test_brain_quota_done_temp_spec_emits_discard_action(): void
    {
        $plan = $this->planner()->plan(
            ['classification' => AtlasSelfConstructionUnattendedStallClassifier::HEALTHY, 'recovery_needed' => false],
            ['facts' => ['brain_quota' => ['temp_spec_path' => '/tmp/spec.json', 'status' => 'done']]],
        );

        self::assertContains(
            AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_DISCARD_DONE_TEMP_SPEC,
            array_column($plan['actions'], 'action'),
        );
    }

    public function test_brain_quota_actions_are_orthogonal_and_stack_with_classification_actions(): void
    {
        $plan = $this->planner()->plan(
            $this->classification(AtlasSelfConstructionUnattendedStallClassifier::QUEUE_DRY),
            ['facts' => ['brain_quota' => ['must_run_now' => true]]],
        );

        $actions = array_column($plan['actions'], 'action');
        self::assertContains(AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RUN_REPLENISHER_DRY_RUN, $actions);
        self::assertContains(AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RUN_BRAIN_MUST_RUN_NOW, $actions);
    }

    // ── plan_hash determinism ────────────────────────────────────────────────────

    public function test_plan_hash_is_deterministic_for_the_same_inputs(): void
    {
        $planner = $this->planner();
        $classification = $this->classification(AtlasSelfConstructionUnattendedStallClassifier::QUEUE_DRY);

        $a = $planner->plan($classification);
        $b = $planner->plan($classification);

        self::assertSame($a['plan_hash'], $b['plan_hash']);
    }

    public function test_plan_hash_differs_for_different_classifications(): void
    {
        $planner = $this->planner();

        $a = $planner->plan($this->classification(AtlasSelfConstructionUnattendedStallClassifier::QUEUE_DRY));
        $b = $planner->plan($this->classification(AtlasSelfConstructionUnattendedStallClassifier::HEARTBEAT_STALE));

        self::assertNotSame($a['plan_hash'], $b['plan_hash']);
    }

    // ── every emitted action is atlas_native=true and non-executing envelope-only ──

    public function test_every_emitted_action_across_all_classifications_is_atlas_native(): void
    {
        $planner = $this->planner();

        foreach ([
            AtlasSelfConstructionUnattendedStallClassifier::UNSAFE_STOP,
            AtlasSelfConstructionUnattendedStallClassifier::QUEUE_DRY,
            AtlasSelfConstructionUnattendedStallClassifier::REPLENISHER_BLOCKED,
            AtlasSelfConstructionUnattendedStallClassifier::WAITING_ON_DEPENDENCIES,
            AtlasSelfConstructionUnattendedStallClassifier::HEARTBEAT_STALE,
            AtlasSelfConstructionUnattendedStallClassifier::WORKER_UNAVAILABLE,
            AtlasSelfConstructionUnattendedStallClassifier::VERIFICATION_BLOCKED,
            AtlasSelfConstructionUnattendedStallClassifier::MERGE_BLOCKED,
        ] as $label) {
            $plan = $planner->plan($this->classification($label));
            foreach ($plan['actions'] as $action) {
                self::assertTrue($action['atlas_native'], "action for {$label} must be atlas_native");
            }
        }
    }

    public function test_planner_returns_only_an_envelope_and_never_an_executed_result(): void
    {
        $plan = $this->planner()->plan($this->classification(AtlasSelfConstructionUnattendedStallClassifier::QUEUE_DRY));

        self::assertArrayNotHasKey('executed', $plan);
        self::assertArrayNotHasKey('execution_result', $plan);
        self::assertSame(AtlasSelfConstructionUnattendedRecoveryActionPlanner::SCHEMA, $plan['schema']);
    }

    // ── forbidden non-Atlas action guard ────────────────────────────────────────

    public function test_planner_source_never_references_forbidden_non_atlas_native_verbs_outside_the_guard_list(): void
    {
        $src = (string) file_get_contents(base_path(
            'app/Services/Ai/SelfConstruction/UnattendedRuntime/AtlasSelfConstructionUnattendedRecoveryActionPlanner.php'
        ));
        foreach (['exec(', 'shell_exec', 'proc_open', 'Http::', 'curl_exec'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src);
        }
    }

    public function test_healthy_classification_with_no_brain_quota_signals_yields_no_actions(): void
    {
        $plan = $this->planner()->plan(['classification' => AtlasSelfConstructionUnattendedStallClassifier::HEALTHY, 'recovery_needed' => false]);

        self::assertSame([], $plan['actions']);
        self::assertSame([], $plan['blocked_actions']);
        self::assertFalse($plan['requires_emergency_override']);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC2: lease_leak — diagnose_lease_mismatch + monitor_claimable_drain
    // ═══════════════════════════════════════════════════════════════════════

    public function test_lease_leak_emits_diagnose_and_monitor(): void
    {
        $plan = $this->planner()->plan($this->classification(AtlasSelfConstructionUnattendedStallClassifier::LEASE_LEAK));

        $actions = array_column($plan['actions'], 'action');
        $this->assertContains(
            AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_DIAGNOSE_LEASE_MISMATCH,
            $actions,
            'lease_leak must emit diagnose_lease_mismatch',
        );
        $this->assertContains(
            AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_MONITOR_CLAIMABLE_DRAIN,
            $actions,
            'lease_leak must emit monitor_claimable_drain',
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC3: lease_leak does NOT emit stop_all_workers
    // ═══════════════════════════════════════════════════════════════════════

    public function test_lease_leak_does_not_emit_safety_stop(): void
    {
        $plan = $this->planner()->plan($this->classification(AtlasSelfConstructionUnattendedStallClassifier::LEASE_LEAK));

        $actions = array_column($plan['actions'], 'action');
        $this->assertNotContains(
            AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_SAFETY_STOP,
            $actions,
            'lease_leak must not emit safety_stop for a nonblocking mismatch',
        );
        $this->assertFalse($plan['requires_emergency_override'],
            'lease_leak must not require emergency override');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // AC4: output keys — action_priority, safe_to_continue_workers, recheck_command
    // ═══════════════════════════════════════════════════════════════════════

    public function test_output_includes_new_keys(): void
    {
        $plan = $this->planner()->plan($this->classification(AtlasSelfConstructionUnattendedStallClassifier::HEARTBEAT_STALE));

        $this->assertArrayHasKey('action_priority', $plan);
        $this->assertArrayHasKey('safe_to_continue_workers', $plan);
        $this->assertArrayHasKey('recheck_command', $plan);
    }

    public function test_lease_leak_has_medium_priority_and_safe_to_continue_workers(): void
    {
        $plan = $this->planner()->plan($this->classification(AtlasSelfConstructionUnattendedStallClassifier::LEASE_LEAK));

        $this->assertSame('medium', $plan['action_priority'],
            'lease_leak must have medium action_priority');
        $this->assertTrue($plan['safe_to_continue_workers'],
            'lease_leak must allow workers to continue draining safe tasks');
    }

    public function test_unsafe_stop_has_critical_priority_and_not_safe_to_continue(): void
    {
        $plan = $this->planner()->plan($this->classification(AtlasSelfConstructionUnattendedStallClassifier::UNSAFE_STOP));

        $this->assertSame('critical', $plan['action_priority'],
            'unsafe_stop must have critical action_priority');
        $this->assertFalse($plan['safe_to_continue_workers'],
            'unsafe_stop must NOT allow workers to continue');
    }

    public function test_lease_leak_has_recheck_command(): void
    {
        $plan = $this->planner()->plan($this->classification(AtlasSelfConstructionUnattendedStallClassifier::LEASE_LEAK));

        $this->assertNotNull($plan['recheck_command'],
            'lease_leak must include recheck_command');

        // Was: assertStringContainsString('lease-parity', ...). That pinned an
        // invented flag on atlas:unattended:health — a command that has never been
        // registered — so the assertion passed while the operator could not run
        // what it emitted. Assert the property that actually matters instead: the
        // recheck names a REAL artisan command. This is strictly stronger, and it
        // fails against the previous implementation.
        $this->assertRecheckCommandIsRunnable($plan['recheck_command']);
    }

    public function test_every_recheck_command_names_a_registered_artisan_command(): void
    {
        foreach ([
            AtlasSelfConstructionUnattendedStallClassifier::LEASE_LEAK,
            AtlasSelfConstructionUnattendedStallClassifier::QUEUE_DRY,
            AtlasSelfConstructionUnattendedStallClassifier::REPLENISHER_BLOCKED,
        ] as $classification) {
            $recheck = $this->planner()->plan($this->classification($classification))['recheck_command'] ?? null;
            if ($recheck === null) {
                continue;
            }
            $this->assertRecheckCommandIsRunnable($recheck, $classification);
        }
    }

    private function assertRecheckCommandIsRunnable(string $recheck, string $context = ''): void
    {
        $this->assertSame(1, preg_match('/artisan\s+(\S+)/', $recheck, $m),
            'recheck_command must invoke artisan: '.$recheck);
        $this->assertArrayHasKey($m[1], Artisan::all(),
            'recheck_command names an artisan command that is not registered: '.$m[1].' '.$context);
    }
}
