<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\UnattendedRuntime;

use App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedRecoveryActionPlanner;
use App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedLivenessSnapshotPort;
use App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedRecoveryActionPlannerPort;
use App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedStallClassifier;
use App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedStallClassifierPort;
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

        self::assertSame(AtlasSelfConstructionUnattendedSupervisorCycle::SCHEMA, $verdict['schema']);
        self::assertSame(AtlasSelfConstructionUnattendedSupervisorCycle::SCHEMA, $verdict['schema_version']);
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

    // --- AC2/AC3: decision (self_heal/replenish/pause/escalate), evidence_refs, safety_blockers ---

    public function test_malformed_queue_yields_self_heal_decision_with_evidence_refs(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedSupervisorCycle)->tick(
            $this->baseFacts(['queue' => ['malformed_count' => 1]]),
        );

        self::assertSame(AtlasSelfConstructionUnattendedSupervisorCycle::DECISION_SELF_HEAL, $verdict['decision']);
        self::assertContains('queue.malformed_count', $verdict['evidence_refs']);
        self::assertSame([], $verdict['safety_blockers']);
    }

    public function test_no_claimable_yields_replenish_decision(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedSupervisorCycle)->tick(
            $this->baseFacts(['queue' => ['claimable_count' => 0]]),
        );

        self::assertSame(AtlasSelfConstructionUnattendedSupervisorCycle::DECISION_REPLENISH, $verdict['decision']);
        self::assertContains('queue.claimable_count', $verdict['evidence_refs']);
        self::assertSame([], $verdict['safety_blockers']);
    }

    public function test_merge_blocked_with_no_stall_class_yields_pause_decision(): void
    {
        // classify() detects merge_blocked, but classifyStallAction() checks none of its own
        // signals (malformed/poison/heartbeat/worker/verification/learning/claimable) — stall_class
        // stays STALL_NONE, so the fallback must be pause, not healthy-continue.
        $verdict = (new AtlasSelfConstructionUnattendedSupervisorCycle)->tick(
            $this->baseFacts(['merge' => ['blocked' => true]]),
        );

        self::assertNotSame(AtlasSelfConstructionUnattendedStallClassifier::HEALTHY, $verdict['classification']);
        self::assertSame(AtlasSelfConstructionUnattendedSupervisorCycle::DECISION_PAUSE, $verdict['decision']);
        self::assertSame([], $verdict['safety_blockers']);
    }

    public function test_poison_loop_yields_escalate_decision_and_refuses_auto_recovery(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedSupervisorCycle)->tick(
            $this->baseFacts(['queue' => ['poison_loop_detected' => true]]),
        );

        self::assertSame(AtlasSelfConstructionUnattendedSupervisorCycle::DECISION_ESCALATE, $verdict['decision']);
        self::assertNotEmpty($verdict['safety_blockers']);
        self::assertStringContainsString('operator_only_repair_required', $verdict['safety_blockers'][0]);
    }

    public function test_unsafe_stop_yields_escalate_decision_with_unsafe_stop_safety_blocker(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedSupervisorCycle)->tick(
            $this->baseFacts(['queue' => ['safety_stop' => true]]),
        );

        self::assertSame(AtlasSelfConstructionUnattendedSupervisorCycle::DECISION_ESCALATE, $verdict['decision']);
        self::assertContains('unsafe_stop_requires_operator_review', $verdict['safety_blockers']);
    }

    public function test_healthy_state_yields_null_decision(): void
    {
        $verdict = (new AtlasSelfConstructionUnattendedSupervisorCycle)->tick($this->baseFacts());

        self::assertSame(AtlasSelfConstructionUnattendedStallClassifier::HEALTHY, $verdict['classification']);
        self::assertNull($verdict['decision']);
        self::assertSame([], $verdict['safety_blockers']);
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

    public function test_string_zero_apply_option_is_treated_as_disabled(): void
    {
        $called = false;
        $verdict = (new AtlasSelfConstructionUnattendedSupervisorCycle)->tick(
            $this->baseFacts(['queue' => ['depth' => 0, 'claimable_count' => 0]]),
            [AtlasSelfConstructionUnattendedRecoveryActionPlanner::ACTION_RUN_REPLENISHER_DRY_RUN => static function () use (&$called): array {
                $called = true;

                return ['ok' => true];
            }],
            ['apply' => '0'],
        );

        self::assertTrue($verdict['dry_run']);
        self::assertFalse($called);
        self::assertSame([], $verdict['applied_actions']);
    }

    public function test_injected_snapshot_classifier_and_planner_are_used_instead_of_defaults(): void
    {
        $facts = $this->baseFacts();
        $snapshot = $this->createMock(AtlasSelfConstructionUnattendedLivenessSnapshotPort::class);
        $classifier = $this->createMock(AtlasSelfConstructionUnattendedStallClassifierPort::class);
        $planner = $this->createMock(AtlasSelfConstructionUnattendedRecoveryActionPlannerPort::class);

        $snapshot->expects(self::once())->method('compose')->with($facts)->willReturn(['snapshot_hash' => 'snapshot-test']);
        $classifier->expects(self::once())->method('classify')->with(['snapshot_hash' => 'snapshot-test'])->willReturn([
            'classification' => AtlasSelfConstructionUnattendedStallClassifier::HEALTHY,
            'classifier_hash' => 'classifier-test',
        ]);
        $classifier->expects(self::once())->method('classifyStallAction')->with(['facts' => $facts])->willReturn([
            'stall_class' => AtlasSelfConstructionUnattendedStallClassifier::STALL_NONE,
            'safe_to_auto_recover' => true,
            'evidence_needed' => [],
        ]);
        $planner->expects(self::once())->method('plan')->willReturn([
            'actions' => [],
            'blocked_actions' => [],
            'plan_hash' => 'plan-test',
        ]);

        $verdict = (new AtlasSelfConstructionUnattendedSupervisorCycle($snapshot, $classifier, $planner))->tick($facts);

        self::assertSame(AtlasSelfConstructionUnattendedSupervisorCycle::SCHEMA, $verdict['schema']);
        self::assertSame('snapshot-test', $verdict['snapshot_hash']);
        self::assertSame('classifier-test', $verdict['classifier_hash']);
        self::assertSame('plan-test', $verdict['recovery_plan_hash']);
    }

    public function test_non_atlas_native_planned_action_is_refused_and_reported(): void
    {
        $snapshot = $this->createStub(AtlasSelfConstructionUnattendedLivenessSnapshotPort::class);
        $classifier = $this->createStub(AtlasSelfConstructionUnattendedStallClassifierPort::class);
        $planner = $this->createStub(AtlasSelfConstructionUnattendedRecoveryActionPlannerPort::class);
        $snapshot->method('compose')->willReturn(['snapshot_hash' => 's']);
        $classifier->method('classify')->willReturn(['classification' => AtlasSelfConstructionUnattendedStallClassifier::HEALTHY, 'classifier_hash' => 'c']);
        $classifier->method('classifyStallAction')->willReturn(['stall_class' => AtlasSelfConstructionUnattendedStallClassifier::STALL_NONE, 'safe_to_auto_recover' => true, 'evidence_needed' => []]);
        $planner->method('plan')->willReturn([
            'actions' => [['action' => 'external_action']],
            'blocked_actions' => [],
            'plan_hash' => 'p',
        ]);

        $verdict = (new AtlasSelfConstructionUnattendedSupervisorCycle($snapshot, $classifier, $planner))->tick(
            $this->baseFacts(),
            ['external_action' => static fn (): array => ['should_not_run' => true]],
            ['apply' => true],
        );

        self::assertSame('non_atlas_native_action_refused', $verdict['blocked_actions'][0]['reason']);
        self::assertSame([], $verdict['applied_actions']);
    }

    public function test_callback_failure_is_recorded_and_non_array_result_is_normalized(): void
    {
        $snapshot = $this->createStub(AtlasSelfConstructionUnattendedLivenessSnapshotPort::class);
        $classifier = $this->createStub(AtlasSelfConstructionUnattendedStallClassifierPort::class);
        $planner = $this->createStub(AtlasSelfConstructionUnattendedRecoveryActionPlannerPort::class);
        $snapshot->method('compose')->willReturn(['snapshot_hash' => 's']);
        $classifier->method('classify')->willReturn(['classification' => AtlasSelfConstructionUnattendedStallClassifier::HEALTHY, 'classifier_hash' => 'c']);
        $classifier->method('classifyStallAction')->willReturn(['stall_class' => AtlasSelfConstructionUnattendedStallClassifier::STALL_NONE, 'safe_to_auto_recover' => true, 'evidence_needed' => []]);
        $planner->method('plan')->willReturn([
            'actions' => [['action' => 'a', 'atlas_native' => true], ['action' => 'b', 'atlas_native' => true]],
            'blocked_actions' => [],
            'plan_hash' => 'p',
        ]);

        $verdict = (new AtlasSelfConstructionUnattendedSupervisorCycle($snapshot, $classifier, $planner))->tick(
            $this->baseFacts(),
            [
                'a' => static fn (): string => 'scalar-result',
                'b' => static function (): array {
                    throw new \RuntimeException('callback-failed');
                },
            ],
            ['apply' => true],
        );

        self::assertTrue($verdict['applied_actions'][0]['applied']);
        self::assertNull($verdict['receipts'][0]['result']);
        self::assertFalse($verdict['applied_actions'][1]['applied']);
        self::assertSame('callback-failed', $verdict['applied_actions'][1]['error']);
    }
}
