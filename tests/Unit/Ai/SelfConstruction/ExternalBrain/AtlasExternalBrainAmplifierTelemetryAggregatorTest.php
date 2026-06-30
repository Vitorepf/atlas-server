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
}
