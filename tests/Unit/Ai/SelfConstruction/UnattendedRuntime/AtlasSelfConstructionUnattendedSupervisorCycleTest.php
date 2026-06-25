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

    public function test_cycle_source_does_not_run_git_or_subprocesses(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/UnattendedRuntime/AtlasSelfConstructionUnattendedSupervisorCycle.php'));
        foreach (['shell_exec', 'exec(', 'system(', 'proc_open', '`git ', 'Http::', 'curl_'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src, "supervisor cycle must not contain {$forbidden}");
        }
    }
}
