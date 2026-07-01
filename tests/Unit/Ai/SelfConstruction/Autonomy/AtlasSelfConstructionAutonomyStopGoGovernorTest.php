<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Autonomy;

use App\Services\Ai\SelfConstruction\Autonomy\AtlasSelfConstructionAutonomyStopGoGovernor;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionAutonomyStopGoGovernorTest extends TestCase
{
    private AtlasSelfConstructionAutonomyStopGoGovernor $governor;

    protected function setUp(): void
    {
        $this->governor = new AtlasSelfConstructionAutonomyStopGoGovernor;
    }

    private function healthy(array $overrides = []): array
    {
        return array_merge([
            'queue_health'              => 'healthy',
            'value_trend'               => 'high',
            'sprawl_pressure'           => 'low',
            'malformed_risk'            => false,
            'give_back_repeated'        => false,
            'dry_queue'                 => false,
            'worker_capacity_available' => false,
        ], $overrides);
    }

    // ── Schema / required keys ────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->governor->decide($this->healthy());

        foreach (['schema', 'decision', 'rationale', 'next_review_signal', 'provider_free'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::SCHEMA, $result['schema']);
        $this->assertTrue($result['provider_free']);
    }

    // ── AC2: healthy + high value → create or call muscles ───────────────────

    public function test_healthy_high_value_returns_create_high_value_tasks(): void
    {
        $result = $this->governor->decide($this->healthy([
            'worker_capacity_available' => false,
        ]));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_CREATE_HIGH_VALUE, $result['decision']);
    }

    public function test_healthy_high_value_with_capacity_calls_more_muscles(): void
    {
        $result = $this->governor->decide($this->healthy([
            'worker_capacity_available' => true,
        ]));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_CALL_MUSCLES, $result['decision']);
        $this->assertStringContainsString('worker_capacity_available:true', implode(' ', $result['rationale']));
    }

    // ── AC2: low value → consolidate ─────────────────────────────────────────

    public function test_low_value_trend_returns_consolidate(): void
    {
        $result = $this->governor->decide($this->healthy(['value_trend' => 'low']));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_CONSOLIDATE, $result['decision']);
        $this->assertStringContainsString('value_trend:low', implode(' ', $result['rationale']));
    }

    // ── AC2: high sprawl → consolidate ───────────────────────────────────────

    public function test_high_sprawl_returns_consolidate(): void
    {
        $result = $this->governor->decide($this->healthy(['sprawl_pressure' => 'high']));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_CONSOLIDATE, $result['decision']);
        $this->assertStringContainsString('sprawl_pressure:high', implode(' ', $result['rationale']));
    }

    // ── AC3: malformed risk → self_heal ──────────────────────────────────────

    public function test_malformed_risk_returns_self_heal(): void
    {
        $result = $this->governor->decide($this->healthy(['malformed_risk' => true]));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_SELF_HEAL, $result['decision']);
        $this->assertStringContainsString('malformed_risk:true', implode(' ', $result['rationale']));
    }

    // ── AC3: repeated give_back → self_heal ──────────────────────────────────

    public function test_give_back_repeated_returns_self_heal(): void
    {
        $result = $this->governor->decide($this->healthy(['give_back_repeated' => true]));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_SELF_HEAL, $result['decision']);
        $this->assertStringContainsString('give_back_repeated:true', implode(' ', $result['rationale']));
    }

    // ── AC3: dry queue → replenish ────────────────────────────────────────────

    public function test_dry_queue_flag_returns_replenish(): void
    {
        $result = $this->governor->decide($this->healthy(['dry_queue' => true]));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_REPLENISH, $result['decision']);
    }

    public function test_dry_queue_health_returns_replenish(): void
    {
        $result = $this->governor->decide($this->healthy(['queue_health' => 'dry']));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_REPLENISH, $result['decision']);
    }

    // ── AC3: self_heal takes priority over everything else ───────────────────

    public function test_self_heal_beats_dry_queue(): void
    {
        $result = $this->governor->decide($this->healthy([
            'malformed_risk' => true,
            'dry_queue'      => true,
        ]));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_SELF_HEAL, $result['decision']);
    }

    public function test_self_heal_beats_consolidate(): void
    {
        $result = $this->governor->decide($this->healthy([
            'give_back_repeated' => true,
            'value_trend'        => 'low',
        ]));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_SELF_HEAL, $result['decision']);
    }

    // ── AC3: replenish beats consolidate ─────────────────────────────────────

    public function test_replenish_beats_consolidate(): void
    {
        $result = $this->governor->decide($this->healthy([
            'dry_queue'       => true,
            'value_trend'     => 'low',
            'sprawl_pressure' => 'high',
        ]));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_REPLENISH, $result['decision']);
    }

    // ── Pause origination as safe default ────────────────────────────────────

    public function test_medium_value_no_signals_returns_pause(): void
    {
        $result = $this->governor->decide($this->healthy([
            'value_trend'   => 'medium',
            'queue_health'  => 'healthy',
        ]));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_PAUSE, $result['decision']);
    }

    // ── next_review_signal is always non-empty ────────────────────────────────

    public function test_next_review_signal_is_always_non_empty(): void
    {
        $cases = [
            $this->healthy(['malformed_risk' => true]),
            $this->healthy(['dry_queue' => true]),
            $this->healthy(['value_trend' => 'low']),
            $this->healthy(['worker_capacity_available' => true]),
            $this->healthy(),
        ];

        foreach ($cases as $input) {
            $result = $this->governor->decide($input);
            $this->assertNotEmpty($result['next_review_signal']);
        }
    }

    // ── Rationale is non-empty for non-default decisions ─────────────────────

    public function test_rationale_is_non_empty_for_action_decisions(): void
    {
        $result = $this->governor->decide($this->healthy(['malformed_risk' => true]));

        $this->assertNotEmpty($result['rationale']);
    }

    // ── worker-feed floor veto (AC) ───────────────────────────────────────────

    public function test_low_worker_floor_vetoes_call_more_muscles_into_go_repair_queue(): void
    {
        $result = $this->governor->decide($this->healthy([
            'worker_capacity_available' => true,
            'claimable_per_active_worker' => 1.5,
        ]));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_GO_REPAIR_QUEUE, $result['decision']);
        $this->assertSame(['worker_feed_below_floor'], $result['rationale']);
    }

    public function test_low_worker_floor_vetoes_create_high_value_tasks_into_go_repair_queue(): void
    {
        $result = $this->governor->decide($this->healthy([
            'claimable_per_active_worker' => 1.5,
        ]));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_GO_REPAIR_QUEUE, $result['decision']);
    }

    public function test_healthy_worker_floor_preserves_existing_go_autonomous_behavior(): void
    {
        $result = $this->governor->decide($this->healthy([
            'claimable_per_active_worker' => 10.0,
        ]));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_CREATE_HIGH_VALUE, $result['decision']);
    }

    public function test_low_worker_floor_does_not_override_self_heal_decision(): void
    {
        $result = $this->governor->decide($this->healthy([
            'malformed_risk' => true,
            'claimable_per_active_worker' => 1.0,
        ]));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_SELF_HEAL, $result['decision']);
    }

    public function test_no_worker_floor_context_preserves_existing_behavior(): void
    {
        $result = $this->governor->decide($this->healthy());

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_CREATE_HIGH_VALUE, $result['decision']);
    }

    // ── AC: stop_go_decision — go/slow_down/pause/self_heal/consolidate ─────────

    public function test_healthy_input_returns_stop_go_go(): void
    {
        $result = $this->governor->decide($this->healthy());

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::STOP_GO_GO, $result['stop_go_decision']);
        $this->assertSame(['all_signals_nominal'], $result['stop_go_rationale']);
    }

    public function test_low_task_quality_returns_stop_go_pause(): void
    {
        $result = $this->governor->decide($this->healthy(['task_quality' => 'low']));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::STOP_GO_PAUSE, $result['stop_go_decision']);
        $this->assertContains('quality:low', $result['stop_go_rationale']);
    }

    public function test_high_malformed_pressure_returns_stop_go_self_heal(): void
    {
        $result = $this->governor->decide($this->healthy(['malformed_pressure' => 'high']));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::STOP_GO_SELF_HEAL, $result['stop_go_decision']);
        $this->assertContains('malformed:high', $result['stop_go_rationale']);
    }

    public function test_high_give_back_drag_returns_stop_go_consolidate(): void
    {
        $result = $this->governor->decide($this->healthy(['give_back_drag' => 'high']));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::STOP_GO_CONSOLIDATE, $result['stop_go_decision']);
        $this->assertContains('give_back:high', $result['stop_go_rationale']);
    }

    public function test_high_simplification_debt_returns_stop_go_consolidate(): void
    {
        $result = $this->governor->decide($this->healthy(['simplification_debt' => 'high']));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::STOP_GO_CONSOLIDATE, $result['stop_go_decision']);
        $this->assertContains('debt:high', $result['stop_go_rationale']);
    }

    public function test_stale_context_freshness_returns_stop_go_pause(): void
    {
        $result = $this->governor->decide($this->healthy(['context_freshness' => 'stale']));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::STOP_GO_PAUSE, $result['stop_go_decision']);
        $this->assertContains('freshness:stale', $result['stop_go_rationale']);
    }

    public function test_high_worker_drain_signal_returns_stop_go_slow_down(): void
    {
        $result = $this->governor->decide($this->healthy(['worker_drain_signal' => 'high']));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::STOP_GO_SLOW_DOWN, $result['stop_go_decision']);
        $this->assertContains('drain:high', $result['stop_go_rationale']);
    }

    public function test_existing_malformed_risk_flag_also_drives_stop_go_self_heal(): void
    {
        $result = $this->governor->decide($this->healthy(['malformed_risk' => true]));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::STOP_GO_SELF_HEAL, $result['stop_go_decision']);
        $this->assertContains('malformed:true', $result['stop_go_rationale']);
    }

    public function test_stop_go_self_heal_takes_priority_over_all_other_stop_go_signals(): void
    {
        $result = $this->governor->decide($this->healthy([
            'malformed_pressure' => 'high',
            'worker_drain_signal' => 'high',
            'give_back_drag' => 'high',
            'task_quality' => 'low',
        ]));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::STOP_GO_SELF_HEAL, $result['stop_go_decision']);
    }

    public function test_stop_go_slow_down_takes_priority_over_consolidate_and_pause(): void
    {
        $result = $this->governor->decide($this->healthy([
            'worker_drain_signal' => 'high',
            'give_back_drag' => 'high',
            'task_quality' => 'low',
        ]));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::STOP_GO_SLOW_DOWN, $result['stop_go_decision']);
    }

    public function test_stop_go_signals_absent_default_to_go_regardless_of_legacy_decision(): void
    {
        // Regression guard: the new stop_go layer must never regress just because the LEGACY
        // decision field ended up somewhere non-trivial (e.g. consolidate_or_burn_debt).
        $result = $this->governor->decide($this->healthy(['value_trend' => 'low']));

        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::DECISION_CONSOLIDATE, $result['decision']);
        $this->assertSame(AtlasSelfConstructionAutonomyStopGoGovernor::STOP_GO_GO, $result['stop_go_decision']);
    }
}
