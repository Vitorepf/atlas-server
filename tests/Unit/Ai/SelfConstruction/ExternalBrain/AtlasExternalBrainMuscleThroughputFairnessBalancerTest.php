<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMuscleThroughputFairnessBalancer;
use Tests\TestCase;

final class AtlasExternalBrainMuscleThroughputFairnessBalancerTest extends TestCase
{
    private function svc(): AtlasExternalBrainMuscleThroughputFairnessBalancer
    {
        return new AtlasExternalBrainMuscleThroughputFairnessBalancer;
    }

    // ── schema / output shape ─────────────────────────────────────────────────

    public function test_schema_and_output_keys_present(): void
    {
        $r = $this->svc()->balance(['muscles' => []]);

        $this->assertSame(AtlasExternalBrainMuscleThroughputFairnessBalancer::SCHEMA, $r['schema']);
        foreach (['recommended_distribution', 'fairness_score', 'overload_warnings', 'next_task_family_preferences', 'fairness_adjustments', 'starvation_warnings'] as $key) {
            $this->assertArrayHasKey($key, $r, "missing key: {$key}");
        }
    }

    public function test_empty_muscles_gives_empty_distribution_and_no_adjustments(): void
    {
        $r = $this->svc()->balance(['muscles' => []]);

        $this->assertSame([], $r['recommended_distribution']);
        $this->assertSame([], $r['fairness_adjustments']);
        $this->assertSame([], $r['starvation_warnings']);
    }

    // ── AC2/AC4: fast low-quality worker does not dominate raw throughput ────

    public function test_fast_low_quality_worker_gets_low_share_despite_high_throughput(): void
    {
        $r = $this->svc()->balance(['muscles' => [
            ['muscle_id' => 'fast-risky', 'throughput_per_hour' => 50.0, 'reliability_score' => 0.3, 'give_back_rate' => 0.5],
            ['muscle_id' => 'steady', 'throughput_per_hour' => 10.0, 'reliability_score' => 0.95, 'give_back_rate' => 0.02],
        ]]);

        $this->assertLessThan($r['recommended_distribution']['steady'], $r['recommended_distribution']['fast-risky']);
    }

    // ── AC2/AC4: slow specialist worker is protected from starvation ─────────

    public function test_slow_specialist_worker_gets_reserved_capacity_adjustment(): void
    {
        $r = $this->svc()->balance([
            'muscles' => [
                ['muscle_id' => 'generalist-fast', 'throughput_per_hour' => 50.0, 'reliability_score' => 0.9, 'give_back_rate' => 0.05],
                ['muscle_id' => 'legacy-specialist', 'throughput_per_hour' => 1.0, 'reliability_score' => 0.9, 'give_back_rate' => 0.02, 'specialist_task_families' => ['legacy_migration']],
            ],
            'task_families' => [
                ['family_id' => 'legacy_migration', 'queue_depth' => 5, 'queue_value' => 0.9],
            ],
        ]);

        $this->assertLessThan(0.15, $r['recommended_distribution']['legacy-specialist']);
        $adjustment = null;
        foreach ($r['fairness_adjustments'] as $a) {
            if ($a['muscle_id'] === 'legacy-specialist' && $a['adjustment'] === 'specialist_reserved_capacity') {
                $adjustment = $a;
            }
        }
        $this->assertNotNull($adjustment, 'expected a specialist_reserved_capacity adjustment for legacy-specialist');
        $this->assertSame('legacy_migration', $adjustment['family_id']);
    }

    // ── AC2/AC4: starving task family with no capable muscle ─────────────────

    public function test_starving_task_family_with_no_specialist_emits_warning(): void
    {
        $r = $this->svc()->balance([
            'muscles' => [
                ['muscle_id' => 'generalist', 'throughput_per_hour' => 10.0, 'reliability_score' => 0.8, 'give_back_rate' => 0.05],
            ],
            'task_families' => [
                ['family_id' => 'rust_ffi', 'queue_depth' => 3, 'queue_value' => 0.8],
            ],
        ]);

        $this->assertContains('family_starving:rust_ffi', $r['starvation_warnings']);
        $this->assertSame([], $r['fairness_adjustments']);
    }

    // ── AC2/AC4: healthy balance — no adjustments or warnings ─────────────────

    public function test_healthy_balance_has_no_adjustments_or_warnings(): void
    {
        $r = $this->svc()->balance([
            'muscles' => [
                ['muscle_id' => 'a', 'throughput_per_hour' => 10.0, 'reliability_score' => 0.8, 'give_back_rate' => 0.05],
                ['muscle_id' => 'b', 'throughput_per_hour' => 12.0, 'reliability_score' => 0.85, 'give_back_rate' => 0.03],
            ],
        ]);

        $this->assertSame([], $r['fairness_adjustments']);
        $this->assertSame([], $r['starvation_warnings']);
        $this->assertGreaterThan(0.0, $r['fairness_score']);
    }

    // ── AC2/AC4: low-value throttling ─────────────────────────────────────────

    public function test_low_value_primary_family_gets_throttled_adjustment(): void
    {
        $r = $this->svc()->balance([
            'muscles' => [
                ['muscle_id' => 'hog', 'throughput_per_hour' => 50.0, 'reliability_score' => 0.9, 'give_back_rate' => 0.02, 'primary_task_family' => 'formatting_cleanup'],
                ['muscle_id' => 'other', 'throughput_per_hour' => 5.0, 'reliability_score' => 0.9, 'give_back_rate' => 0.02],
            ],
            'task_families' => [
                ['family_id' => 'formatting_cleanup', 'queue_depth' => 2, 'queue_value' => 0.1],
            ],
        ]);

        $this->assertGreaterThan(0.2, $r['recommended_distribution']['hog'], 'raw share must exceed the throttle ceiling to trigger the adjustment');
        $adjustment = null;
        foreach ($r['fairness_adjustments'] as $a) {
            if ($a['muscle_id'] === 'hog' && $a['adjustment'] === 'throttled_low_value_family') {
                $adjustment = $a;
            }
        }
        $this->assertNotNull($adjustment, 'expected a throttled_low_value_family adjustment for hog');
        $this->assertSame('formatting_cleanup', $adjustment['family_id']);
    }

    public function test_high_value_primary_family_is_never_throttled(): void
    {
        $r = $this->svc()->balance([
            'muscles' => [
                ['muscle_id' => 'hog', 'throughput_per_hour' => 50.0, 'reliability_score' => 0.9, 'give_back_rate' => 0.02, 'primary_task_family' => 'critical_path'],
                ['muscle_id' => 'other', 'throughput_per_hour' => 5.0, 'reliability_score' => 0.9, 'give_back_rate' => 0.02],
            ],
            'task_families' => [
                ['family_id' => 'critical_path', 'queue_depth' => 2, 'queue_value' => 0.95],
            ],
        ]);

        $adjustmentCodes = array_column($r['fairness_adjustments'], 'adjustment');
        $this->assertNotContains('throttled_low_value_family', $adjustmentCodes);
    }

    // ── low_risk_only for unproven/high-give-back muscles ──

    public function test_unproven_muscle_is_marked_low_risk_only(): void
    {
        $r = $this->svc()->balance([
            'muscles' => [
                ['muscle_id' => 'unproven', 'throughput_per_hour' => 10.0, 'reliability_score' => 0.5, 'give_back_rate' => 0.4],
            ],
        ]);
        $this->assertSame('low_risk_only', $r['next_task_family_preferences']['unproven']);
    }

    public function test_proven_muscle_is_marked_high_risk_eligible(): void
    {
        $r = $this->svc()->balance([
            'muscles' => [
                ['muscle_id' => 'proven', 'throughput_per_hour' => 10.0, 'reliability_score' => 0.8, 'give_back_rate' => 0.1],
            ],
        ]);
        $this->assertSame('high_risk_eligible', $r['next_task_family_preferences']['proven']);
    }
}
