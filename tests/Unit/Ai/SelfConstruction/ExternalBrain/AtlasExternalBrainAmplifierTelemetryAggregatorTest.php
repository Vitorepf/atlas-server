<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierTelemetryAggregator;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAmplifierTelemetryAggregatorTest extends TestCase
{
    private AtlasExternalBrainAmplifierTelemetryAggregator $aggregator;

    protected function setUp(): void
    {
        $this->aggregator = new AtlasExternalBrainAmplifierTelemetryAggregator;
    }

    private function allHealthy(): array
    {
        return [
            'shadow_pass_rate'         => 0.95,
            'canary_pass_rate'         => 0.90,
            'slo_score'                => 0.85,
            'slo_met'                  => true,
            'scaffold_compliance_rate' => 0.88,
            'replay_pass_rate'         => 0.92,
        ];
    }

    // ── Schema / AC4 required keys ────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->aggregator->aggregate($this->allHealthy());

        foreach (['schema', 'status', 'signal_rollup', 'blocking_reasons', 'weak_signals', 'next_operator_free_action'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::SCHEMA, $result['schema']);
    }

    // ── AC2: status — healthy ─────────────────────────────────────────────────

    public function test_all_healthy_signals_yield_healthy_status(): void
    {
        $result = $this->aggregator->aggregate($this->allHealthy());

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_HEALTHY, $result['status']);
        $this->assertSame([], $result['blocking_reasons']);
        $this->assertSame([], $result['weak_signals']);
    }

    // ── AC2: status — watch ───────────────────────────────────────────────────

    public function test_marginal_shadow_yields_watch_status(): void
    {
        $input         = $this->allHealthy();
        $input['shadow_pass_rate'] = 0.60; // below warning (0.70), above failure floor (0.50)

        $result = $this->aggregator->aggregate($input);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_WATCH, $result['status']);
        $this->assertNotEmpty($result['weak_signals']);
        $this->assertSame([], $result['blocking_reasons']);
    }

    public function test_marginal_canary_yields_watch_status(): void
    {
        $input                    = $this->allHealthy();
        $input['canary_pass_rate'] = 0.55; // below warning (0.65), above failure floor (0.40)

        $result = $this->aggregator->aggregate($input);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_WATCH, $result['status']);
    }

    // ── AC2: status — rollback_candidate ─────────────────────────────────────

    public function test_failed_shadow_yields_rollback_candidate(): void
    {
        $input                    = $this->allHealthy();
        $input['shadow_pass_rate'] = 0.30; // below failure floor (0.50)

        $result = $this->aggregator->aggregate($input);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE, $result['status']);
        $this->assertNotEmpty($result['blocking_reasons']);
    }

    public function test_failed_canary_yields_rollback_candidate(): void
    {
        $input                    = $this->allHealthy();
        $input['canary_pass_rate'] = 0.25; // below failure floor (0.40)

        $result = $this->aggregator->aggregate($input);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE, $result['status']);
    }

    public function test_slo_not_met_with_low_score_yields_rollback_candidate(): void
    {
        $input              = $this->allHealthy();
        $input['slo_met']   = false;
        $input['slo_score'] = 0.35; // below SLO failure floor (0.50)

        $result = $this->aggregator->aggregate($input);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE, $result['status']);
    }

    public function test_low_scaffold_compliance_yields_rollback_candidate(): void
    {
        $input                           = $this->allHealthy();
        $input['scaffold_compliance_rate'] = 0.45; // below failure floor (0.50)

        $result = $this->aggregator->aggregate($input);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE, $result['status']);
    }

    public function test_low_replay_pass_rate_yields_rollback_candidate(): void
    {
        $input                    = $this->allHealthy();
        $input['replay_pass_rate'] = 0.40; // below failure floor (0.50)

        $result = $this->aggregator->aggregate($input);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE, $result['status']);
    }

    // ── AC3: strongest blocking reason preserved ──────────────────────────────

    public function test_multiple_blocking_signals_all_appear_in_blocking_reasons(): void
    {
        $result = $this->aggregator->aggregate([
            'shadow_pass_rate' => 0.20,
            'canary_pass_rate' => 0.10,
            'slo_score'        => 1.0,
            'slo_met'          => true,
            'scaffold_compliance_rate' => 0.90,
            'replay_pass_rate' => 0.90,
        ]);

        $this->assertCount(2, $result['blocking_reasons']);
        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE, $result['status']);
    }

    public function test_blocking_reason_beats_weak_signal_for_status(): void
    {
        $result = $this->aggregator->aggregate([
            'shadow_pass_rate' => 0.20,  // blocking
            'canary_pass_rate' => 0.55,  // watch
            'slo_score'        => 1.0,
            'slo_met'          => true,
            'scaffold_compliance_rate' => 0.90,
            'replay_pass_rate' => 0.90,
        ]);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE, $result['status']);
        $this->assertNotEmpty($result['blocking_reasons']);
        $this->assertNotEmpty($result['weak_signals']);
    }

    // ── AC4: signal_rollup contains all signals ───────────────────────────────

    public function test_signal_rollup_contains_all_expected_signals(): void
    {
        $result = $this->aggregator->aggregate($this->allHealthy());

        foreach (['shadow', 'canary', 'slo', 'scaffold', 'replay'] as $signal) {
            $this->assertArrayHasKey($signal, $result['signal_rollup']);
        }
    }

    public function test_blocking_signal_is_marked_blocking_in_rollup(): void
    {
        $input                    = $this->allHealthy();
        $input['shadow_pass_rate'] = 0.20;

        $result = $this->aggregator->aggregate($input);

        $this->assertSame('blocking', $result['signal_rollup']['shadow']);
    }

    // ── AC4: next_operator_free_action ────────────────────────────────────────

    public function test_healthy_status_recommends_promotion(): void
    {
        $result = $this->aggregator->aggregate($this->allHealthy());

        $this->assertStringContainsString('promote', $result['next_operator_free_action']);
    }

    public function test_rollback_candidate_recommends_rollback(): void
    {
        $input                    = $this->allHealthy();
        $input['canary_pass_rate'] = 0.10;

        $result = $this->aggregator->aggregate($input);

        $this->assertStringContainsString('rollback', $result['next_operator_free_action']);
    }

    public function test_watch_status_recommends_monitoring(): void
    {
        $input                    = $this->allHealthy();
        $input['replay_pass_rate'] = 0.60; // watch

        $result = $this->aggregator->aggregate($input);

        $this->assertStringContainsString('monitor', $result['next_operator_free_action']);
    }

    // ── AC1: per-run telemetry fields present ─────────────────────────────────

    public function test_ac1_fields_present_with_no_runs(): void
    {
        $result = $this->aggregator->aggregate($this->allHealthy());

        foreach (['pass_rate', 'heldout_pass_rate', 'proxy_leak_rate', 'avg_cost', 'regression_rate', 'sample_count', 'confidence', 'recommended_status'] as $k) {
            $this->assertArrayHasKey($k, $result, "Missing AC1 key: {$k}");
        }
        $this->assertSame(0, $result['sample_count']);
        $this->assertSame('low', $result['confidence']);
        $this->assertSame($result['status'], $result['recommended_status']);
    }

    public function test_ac1_pass_rate_computed_from_runs(): void
    {
        $input          = $this->allHealthy();
        $input['runs']  = [
            ['passed' => true],
            ['passed' => true],
            ['passed' => false],
            ['passed' => true],
        ];

        $result = $this->aggregator->aggregate($input);

        $this->assertSame(4, $result['sample_count']);
        $this->assertSame(0.75, $result['pass_rate']);
    }

    public function test_ac1_heldout_pass_rate_computed_from_runs(): void
    {
        $input         = $this->allHealthy();
        $input['runs'] = [
            ['passed' => true,  'heldout_passed' => true],
            ['passed' => true,  'heldout_passed' => false],
            ['passed' => false],  // no heldout key
        ];

        $result = $this->aggregator->aggregate($input);

        // 1 out of 2 heldout runs passed
        $this->assertSame(0.5, $result['heldout_pass_rate']);
    }

    public function test_ac1_confidence_medium_at_5_runs(): void
    {
        $input         = $this->allHealthy();
        $input['runs'] = array_fill(0, 5, ['passed' => true]);

        $result = $this->aggregator->aggregate($input);

        $this->assertSame('medium', $result['confidence']);
    }

    public function test_ac1_confidence_high_at_20_runs(): void
    {
        $input         = $this->allHealthy();
        $input['runs'] = array_fill(0, 20, ['passed' => true]);

        $result = $this->aggregator->aggregate($input);

        $this->assertSame('high', $result['confidence']);
    }

    public function test_ac1_avg_cost_computed_from_runs(): void
    {
        $input         = $this->allHealthy();
        $input['runs'] = [
            ['passed' => true, 'cost' => 0.10],
            ['passed' => true, 'cost' => 0.20],
        ];

        $result = $this->aggregator->aggregate($input);

        $this->assertSame(0.15, $result['avg_cost']);
    }

    // ── AC2: proxy_leak_rate forces rollback ─────────────────────────────────

    public function test_ac2_proxy_leak_rate_at_threshold_forces_rollback(): void
    {
        $input         = $this->allHealthy();
        // 15 proxy runs out of 100 = 0.15 (≥ PROXY_LEAK_FAILURE_FLOOR)
        $input['runs'] = array_merge(
            array_fill(0, 85, ['passed' => true, 'is_proxy' => false]),
            array_fill(0, 15, ['passed' => true, 'is_proxy' => true]),
        );

        $result = $this->aggregator->aggregate($input);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE, $result['status']);
        $this->assertSame('blocking', $result['signal_rollup']['proxy_leak']);
        $this->assertNotEmpty(array_filter($result['blocking_reasons'], fn ($r) => str_contains($r, 'proxy_leak')));
    }

    public function test_ac2_proxy_leak_below_threshold_no_rollback(): void
    {
        $input         = $this->allHealthy();
        // 4 proxy out of 100 = 0.04 (< 0.05 warning floor)
        $input['runs'] = array_merge(
            array_fill(0, 96, ['passed' => true, 'is_proxy' => false]),
            array_fill(0, 4,  ['passed' => true, 'is_proxy' => true]),
        );

        $result = $this->aggregator->aggregate($input);

        $this->assertSame('healthy', $result['signal_rollup']['proxy_leak']);
    }

    public function test_ac2_regression_rate_at_threshold_forces_rollback(): void
    {
        $input         = $this->allHealthy();
        // 10 regressed out of 100 = 0.10 (≥ REGRESSION_FAILURE_FLOOR)
        $input['runs'] = array_merge(
            array_fill(0, 90, ['passed' => true, 'regressed' => false]),
            array_fill(0, 10, ['passed' => true, 'regressed' => true]),
        );

        $result = $this->aggregator->aggregate($input);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE, $result['status']);
        $this->assertSame('blocking', $result['signal_rollup']['regression']);
        $this->assertNotEmpty(array_filter($result['blocking_reasons'], fn ($r) => str_contains($r, 'regression')));
    }

    public function test_ac2_flat_proxy_leak_rate_input_also_blocks(): void
    {
        $input                    = $this->allHealthy();
        $input['proxy_leak_rate'] = 0.20; // above failure floor, no runs list

        $result = $this->aggregator->aggregate($input);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE, $result['status']);
    }

    public function test_ac2_flat_regression_rate_input_also_blocks(): void
    {
        $input                    = $this->allHealthy();
        $input['regression_rate'] = 0.15; // above failure floor, no runs list

        $result = $this->aggregator->aggregate($input);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE, $result['status']);
    }

    // ── AC2/AC4: signal_rollup includes heldout, cost, muscle_outcome ─────────

    public function test_signal_rollup_includes_heldout_cost_muscle_outcome_keys(): void
    {
        $result = $this->aggregator->aggregate($this->allHealthy());

        foreach (['heldout', 'cost', 'muscle_outcome'] as $signal) {
            $this->assertArrayHasKey($signal, $result['signal_rollup']);
        }
        $this->assertSame('healthy', $result['signal_rollup']['heldout']);
        $this->assertSame('healthy', $result['signal_rollup']['cost']);
        $this->assertSame('healthy', $result['signal_rollup']['muscle_outcome']);
    }

    public function test_low_heldout_pass_rate_forces_rollback(): void
    {
        $input                       = $this->allHealthy();
        $input['heldout_pass_rate']  = 0.30; // below failure floor (0.50)

        $result = $this->aggregator->aggregate($input);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE, $result['status']);
        $this->assertSame('blocking', $result['signal_rollup']['heldout']);
        $this->assertNotEmpty(array_filter($result['blocking_reasons'], fn ($r) => str_contains($r, 'heldout')));
    }

    public function test_high_avg_cost_forces_rollback(): void
    {
        $input         = $this->allHealthy();
        $input['runs'] = [
            ['passed' => true, 'cost' => 12.0],
            ['passed' => true, 'cost' => 11.0],
        ]; // avg_cost=11.5 >= DEFAULT_COST_FAILURE_CEILING (10.0)

        $result = $this->aggregator->aggregate($input);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE, $result['status']);
        $this->assertSame('blocking', $result['signal_rollup']['cost']);
        $this->assertNotEmpty(array_filter($result['blocking_reasons'], fn ($r) => str_contains($r, 'avg_cost')));
    }

    public function test_high_muscle_outcome_bad_rate_forces_rollback_from_runs(): void
    {
        $input         = $this->allHealthy();
        // 25 out of 100 runs are poison/give_back = 0.25 (>= MUSCLE_OUTCOME_FAILURE_FLOOR 0.20)
        $input['runs'] = array_merge(
            array_fill(0, 75, ['passed' => true, 'outcome' => 'success']),
            array_fill(0, 15, ['passed' => false, 'outcome' => 'poison']),
            array_fill(0, 10, ['passed' => false, 'outcome' => 'give_back']),
        );

        $result = $this->aggregator->aggregate($input);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE, $result['status']);
        $this->assertSame('blocking', $result['signal_rollup']['muscle_outcome']);
        $this->assertNotEmpty(array_filter($result['blocking_reasons'], fn ($r) => str_contains($r, 'muscle_outcome')));
    }

    public function test_high_muscle_outcome_bad_rate_forces_rollback_from_flat_input(): void
    {
        $input                  = $this->allHealthy();
        $input['poison_rate']    = 0.15;
        $input['give_back_rate'] = 0.10; // sum=0.25 >= failure floor

        $result = $this->aggregator->aggregate($input);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE, $result['status']);
        $this->assertSame('blocking', $result['signal_rollup']['muscle_outcome']);
    }
}
