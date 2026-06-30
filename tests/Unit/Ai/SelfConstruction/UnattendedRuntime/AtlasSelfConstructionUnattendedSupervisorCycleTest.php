<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\UnattendedRuntime;

use App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedRecoveryActionPlanner;
use App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedStallClassifier;
use App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedSupervisorCycle;
use Tests\TestCase;

class AtlasSelfConstructionUnattendedSupervisorCycleTest extends TestCase
{
    private function baseFacts(array $override = []): array
    {
        return array_replace_recursive([
            'queue' => ['depth' => 5, 'claimable_count' => 3, 'malformed_count' => 0, 'safety_stop' => false],
            'heartbeat' => ['last_seen_age_seconds' => 5, 'stale_threshold_seconds' => 120],
            'active_leases' => [],
            'continuous_runtime_cycle' => ['last_cycle_id' => 'c', 'last_stop_reason' => '', 'last_stopped' => false],
            'native_worker' => ['ready' => true, 'concurrency_floor_ok' => true],
            'replenisher' => ['last_run_status' => 'ok', 'last_run_age_seconds' => 30],
            'verification' => ['last_verdict' => 'verified', 'failed_run_count' => 0],
            'merge' => ['last_decision' => 'request_merge', 'blocked' => false],
        ], $override);
    }

    public function test_dry_run_composes_envelope_and_applies_no_callbacks(): void
    {
        $called = 0;
        $verdict = (new AtlasSelfConstructionUnattendedSupervisorCycle)->tick(
            $this->baseFacts(['queue' => ['depth' => 0, 'claimable_count' => 0]]),
            ['run_replenisher_dry_run' => function () use (&$called) { $called++; return ['ok' => true]; }],
        );

        self::assertTrue($verdict['dry_run']);
        self::assertSame(AtlasSelfConstructionUnattendedStallClassifier::QUEUE_DRY, $verdict['classification']);
        self::assertNotEmpty($verdict['planned_actions']);
        self::assertSame([], $verdict['applied_actions']);
        self::assertSame(0, $called);
    }

    public function test_apply_executes_only_planned_actions_with_callbacks(): void
    {
        $received = [];
        $verdict = (new AtlasSelfConstructionUnattendedSupervisorCycle)->tick(
            $this->baseFacts(['queue' => ['depth' => 0, 'claimable_count' => 0]]),
            [
                AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RUN_REPLENISHER_DRY_RUN => function (array $action) use (&$received) {
                    $received[] = $action['action'];

                    return ['ok' => true];
                },
                // ACTION_APPLY_SAFE_REPLENISHER_PLAN intentionally missing → blocked
            ],
            ['apply' => true],
        );

        self::assertFalse($verdict['dry_run']);
        self::assertContains(AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RUN_REPLENISHER_DRY_RUN, $received);
        $appliedLabels = array_column($verdict['applied_actions'], 'action');
        self::assertContains(AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RUN_REPLENISHER_DRY_RUN, $appliedLabels);
        $blockedLabels = array_column($verdict['blocked_actions'], 'action');
        self::assertContains(AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_APPLY_SAFE_REPLENISHER_PLAN, $blockedLabels);
    }

    public function test_unsafe_stop_blocks_all_non_safety_callbacks(): void
    {
        $received = [];
        $verdict = (new AtlasSelfConstructionUnattendedSupervisorCycle)->tick(
            $this->baseFacts(['queue' => ['safety_stop' => true]]),
            [
                AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RUN_REPLENISHER_DRY_RUN => function () use (&$received) {
                    $received[] = 'replenisher';

                    return ['ok' => true];
                },
                AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_SAFETY_STOP => function () use (&$received) {
                    $received[] = 'safety_stop';

                    return ['ok' => true];
                },
            ],
            ['apply' => true],
        );

        self::assertSame(AtlasSelfConstructionUnattendedStallClassifier::UNSAFE_STOP, $verdict['classification']);
        self::assertSame(['safety_stop'], $received);
    }

    public function test_healthy_state_emits_no_actions_under_apply(): void
    {
        $called = 0;
        $verdict = (new AtlasSelfConstructionUnattendedSupervisorCycle)->tick(
            $this->baseFacts(),
            [
                AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RUN_REPLENISHER_DRY_RUN => function () use (&$called) {
                    $called++;
                },
            ],
            ['apply' => true],
        );

        self::assertSame(AtlasSelfConstructionUnattendedStallClassifier::HEALTHY, $verdict['classification']);
        self::assertSame([], $verdict['planned_actions']);
        self::assertSame([], $verdict['applied_actions']);
        self::assertSame(0, $called);
    }

    public function test_brain_quota_must_run_now_with_callback_applies_and_records_planned_facts(): void
    {
        $invoked = false;
        $verdict = (new AtlasSelfConstructionUnattendedSupervisorCycle)->tick(
            $this->baseFacts(['brain_quota' => ['must_run_now' => true, 'status' => 'stalled', 'temp_spec_path' => '']]),
            [
                AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RUN_BRAIN_MUST_RUN_NOW => function (array $action) use (&$invoked) {
                    $invoked = true;

                    return ['brain_started' => true];
                },
            ],
            ['apply' => true],
        );

        self::assertTrue($invoked, 'brain callback must be invoked on must_run_now=true');
        self::assertContains(
            AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RUN_BRAIN_MUST_RUN_NOW,
            array_column($verdict['applied_actions'], 'action'),
        );
        $receipt = array_values(array_filter(
            $verdict['receipts'],
            fn ($r) => $r['action'] === AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RUN_BRAIN_MUST_RUN_NOW,
        ));
        self::assertNotEmpty($receipt, 'receipt must be recorded for applied brain action');
        self::assertArrayHasKey('planned_action', $receipt[0], 'receipt must preserve planned_action facts');
        self::assertSame('act_run_brain_must_run_now', $receipt[0]['planned_action']['action_id']);
        self::assertTrue($receipt[0]['planned_action']['atlas_native']);
    }

    public function test_brain_quota_must_run_now_without_callback_records_callback_missing_with_planned_facts(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedSupervisorCycle)->tick(
            $this->baseFacts(['brain_quota' => ['must_run_now' => true, 'status' => 'stalled', 'temp_spec_path' => '']]),
            [],
            ['apply' => true],
        );

        $brainBlocked = array_values(array_filter(
            $verdict['blocked_actions'],
            fn ($b) => $b['action'] === AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RUN_BRAIN_MUST_RUN_NOW,
        ));
        self::assertNotEmpty($brainBlocked, 'missing brain callback must appear in blocked_actions');
        self::assertSame('callback_missing', $brainBlocked[0]['reason']);
        self::assertArrayHasKey('planned_action', $brainBlocked[0], 'planned recovery facts must not be lost');
        self::assertTrue($brainBlocked[0]['planned_action']['atlas_native']);
    }

    public function test_cycle_source_does_not_run_git_or_subprocesses(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/UnattendedRuntime/AtlasSelfConstructionUnattendedSupervisorCycle.php'));
        foreach (['shell_exec', 'exec(', 'system(', 'proc_open', '`git ', 'Http::', 'curl_'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src, "supervisor cycle must not contain {$forbidden}");
        }
    }

    // --- recovery_receipt_strength tests ---

    public function test_output_has_recovery_receipt_strength_key(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedSupervisorCycle)->tick($this->baseFacts());

        self::assertArrayHasKey('recovery_receipt_strength', $verdict);
        $rrs = $verdict['recovery_receipt_strength'];
        foreach (['planned_atlas_native_actions', 'missing_callbacks', 'unsafe_stop_blockers', 'applied_receipts_count', 'safe_to_continue'] as $key) {
            self::assertArrayHasKey($key, $rrs);
        }
    }

    public function test_healthy_state_emits_no_planned_atlas_native_and_safe_to_continue(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedSupervisorCycle)->tick($this->baseFacts());

        $rrs = $verdict['recovery_receipt_strength'];
        self::assertSame([], $rrs['planned_atlas_native_actions']);
        self::assertSame([], $rrs['missing_callbacks']);
        self::assertTrue($rrs['safe_to_continue']);
        self::assertSame(0, $rrs['applied_receipts_count']);
    }

    public function test_queue_dry_missing_callback_yields_not_safe_to_continue(): void
    {
        // Queue dry → planner proposes actions, but no callbacks → missing_callbacks non-empty → not safe
        $verdict = (new AtlasSelfConstructionUnattendedSupervisorCycle)->tick(
            $this->baseFacts(['queue' => ['depth' => 0, 'claimable_count' => 0]]),
            [],
            ['apply' => true],
        );

        $rrs = $verdict['recovery_receipt_strength'];
        self::assertNotEmpty($rrs['planned_atlas_native_actions']);
        self::assertNotEmpty($rrs['missing_callbacks']);
        self::assertFalse($rrs['safe_to_continue']);
        self::assertSame(0, $rrs['applied_receipts_count']);
    }

    public function test_unsafe_stop_yields_safe_to_continue_false(): void
    {
        // unsafe_stop planner only plans safety_stop itself, so unsafe_stop_blockers stays empty;
        // safe_to_continue must still be false because isUnsafeStop=true drives it.
        $verdict = (new AtlasSelfConstructionUnattendedSupervisorCycle)->tick(
            $this->baseFacts(['queue' => ['safety_stop' => true]]),
            [AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_SAFETY_STOP => static fn () => ['ok' => true]],
            ['apply' => true],
        );

        $rrs = $verdict['recovery_receipt_strength'];
        self::assertFalse($rrs['safe_to_continue']);
        self::assertSame(AtlasSelfConstructionUnattendedStallClassifier::UNSAFE_STOP, $verdict['classification']);
    }

    public function test_brain_quota_with_callback_yields_applied_receipt_and_safe(): void
    {
        // brain_quota.must_run_now=true is the trigger; providing the callback → it runs → receipt recorded.
        $verdict = (new AtlasSelfConstructionUnattendedSupervisorCycle)->tick(
            $this->baseFacts(['brain_quota' => ['must_run_now' => true, 'status' => 'stalled', 'temp_spec_path' => '']]),
            [AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RUN_BRAIN_MUST_RUN_NOW => static fn () => ['ok' => true]],
            ['apply' => true],
        );

        $rrs = $verdict['recovery_receipt_strength'];
        self::assertGreaterThanOrEqual(1, $rrs['applied_receipts_count']);
        self::assertSame([], $rrs['missing_callbacks']);
        self::assertTrue($rrs['safe_to_continue']);
    }
}
