<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierTelemetryAggregator;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAmplifierTelemetryAggregatorTest extends TestCase
{
    private AtlasExternalBrainAmplifierTelemetryAggregator $agg;

    protected function setUp(): void
    {
        $this->agg = new AtlasExternalBrainAmplifierTelemetryAggregator;
    }

    private function agg(array $input = []): array
    {
        return $this->agg->aggregate($input);
    }

    private function healthy(): array
    {
        return [
            'shadow_pass_rate'        => 0.95,
            'canary_pass_rate'        => 0.90,
            'slo_score'               => 0.90,
            'slo_met'                 => true,
            'scaffold_compliance_rate' => 0.95,
            'replay_pass_rate'        => 0.90,
            'proxy_leak_rate'         => 0.00,
            'regression_rate'         => 0.00,
            'heldout_pass_rate'       => 0.90,
            'avg_cost'                => 0.02,
        ];
    }

    // ── AC2: hard failures → rollback_candidate ───────────────────────────────

    public function test_low_shadow_pass_rate_returns_rollback(): void
    {
        $r = $this->agg(['shadow_pass_rate' => 0.30]);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE, $r['status']);
    }

    public function test_low_canary_pass_rate_returns_rollback(): void
    {
        $r = $this->agg(['canary_pass_rate' => 0.30]);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE, $r['status']);
    }

    public function test_low_slo_score_returns_rollback(): void
    {
        $r = $this->agg(['slo_score' => 0.30, 'slo_met' => false]);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE, $r['status']);
    }

    public function test_low_scaffold_compliance_returns_rollback(): void
    {
        $r = $this->agg(['scaffold_compliance_rate' => 0.40]);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE, $r['status']);
    }

    public function test_low_replay_pass_rate_returns_rollback(): void
    {
        $r = $this->agg(['replay_pass_rate' => 0.40]);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE, $r['status']);
    }

    public function test_high_proxy_leak_rate_returns_rollback(): void
    {
        $r = $this->agg(['proxy_leak_rate' => 0.20]);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE, $r['status']);
        $blockKeys = implode(' ', $r['blocking_reasons']);
        $this->assertStringContainsString('proxy_leak_rate', $blockKeys);
    }

    public function test_high_regression_rate_returns_rollback(): void
    {
        $r = $this->agg(['regression_rate' => 0.15]);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_ROLLBACK_CANDIDATE, $r['status']);
        $blockKeys = implode(' ', $r['blocking_reasons']);
        $this->assertStringContainsString('regression_rate', $blockKeys);
    }

    public function test_rollback_status_sets_blocking_reasons(): void
    {
        $r = $this->agg(['shadow_pass_rate' => 0.30]);

        $this->assertNotEmpty($r['blocking_reasons']);
    }

    // ── AC3: marginal signals → watch + weak_signals + next_action ───────────

    public function test_marginal_shadow_returns_watch(): void
    {
        // 0.60 < warning 0.70 but >= failure 0.50
        $r = $this->agg(['shadow_pass_rate' => 0.60]);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_WATCH, $r['status']);
    }

    public function test_marginal_canary_returns_watch(): void
    {
        // 0.50 >= failure 0.40 but < warning 0.65
        $r = $this->agg(['canary_pass_rate' => 0.50]);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_WATCH, $r['status']);
    }

    public function test_marginal_proxy_leak_returns_watch(): void
    {
        // 0.08 >= warning 0.05 but < failure 0.15
        $r = $this->agg(['proxy_leak_rate' => 0.08]);

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_WATCH, $r['status']);
        $this->assertNotEmpty($r['weak_signals']);
    }

    public function test_watch_has_next_operator_free_action(): void
    {
        $r = $this->agg(['shadow_pass_rate' => 0.60]);

        $this->assertArrayHasKey('next_operator_free_action', $r);
        $this->assertStringContainsString('monitor', $r['next_operator_free_action']);
    }

    // ── AC4: healthy → healthy + signal_rollup + cost + heldout ─────────────

    public function test_healthy_input_returns_healthy(): void
    {
        $r = $this->agg($this->healthy());

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::STATUS_HEALTHY, $r['status']);
    }

    public function test_healthy_includes_signal_rollup(): void
    {
        $r = $this->agg($this->healthy());

        $this->assertArrayHasKey('signal_rollup', $r);
        $this->assertNotEmpty($r['signal_rollup']);
    }

    public function test_healthy_includes_cost_metric(): void
    {
        $r = $this->agg($this->healthy());

        $this->assertArrayHasKey('avg_cost', $r);
    }

    public function test_healthy_includes_heldout_pass_rate(): void
    {
        $r = $this->agg($this->healthy());

        $this->assertArrayHasKey('heldout_pass_rate', $r);
        $this->assertSame(0.90, $r['heldout_pass_rate']);
    }

    public function test_runs_based_input_computes_heldout_and_cost(): void
    {
        $r = $this->agg([
            'runs' => [
                ['passed' => true,  'heldout_passed' => true,  'is_proxy' => false, 'cost' => 0.01, 'regressed' => false],
                ['passed' => true,  'heldout_passed' => true,  'is_proxy' => false, 'cost' => 0.02, 'regressed' => false],
                ['passed' => false, 'heldout_passed' => false, 'is_proxy' => false, 'cost' => 0.01, 'regressed' => false],
            ],
        ]);

        $this->assertSame(3, $r['sample_count']);
        $this->assertGreaterThan(0.0, $r['avg_cost']);
    }

    // ── deterministic ─────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $input = $this->healthy();

        $this->assertSame(json_encode($this->agg($input)), json_encode($this->agg($input)));
    }

    public function test_schema_is_set(): void
    {
        $r = $this->agg($this->healthy());

        $this->assertSame(AtlasExternalBrainAmplifierTelemetryAggregator::SCHEMA, $r['schema']);
    }
}
