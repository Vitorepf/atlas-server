<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMetricsOptimizationSignalRouter;
use Tests\TestCase;

final class AtlasExternalBrainMetricsOptimizationSignalRouterTest extends TestCase
{
    private function router(): AtlasExternalBrainMetricsOptimizationSignalRouter
    {
        return new AtlasExternalBrainMetricsOptimizationSignalRouter();
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->router()->route([]);

        $this->assertSame(AtlasExternalBrainMetricsOptimizationSignalRouter::SCHEMA, $result['schema']);
    }

    public function test_output_has_required_keys(): void
    {
        $result = $this->router()->route([]);

        foreach (['schema', 'ordered_signals', 'skipped_vanity_metrics', 'recommended_next_batch_constraints'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    public function test_empty_metrics_yields_no_signals(): void
    {
        $result = $this->router()->route(['metrics' => []]);

        $this->assertSame([], $result['ordered_signals']);
        $this->assertSame([], $result['recommended_next_batch_constraints']);
    }

    // ── give_back_pressure ────────────────────────────────────────────────────

    public function test_reduce_give_back_signal_fires_at_threshold(): void
    {
        $result = $this->router()->route(['metrics' => ['give_back_rate' => 0.30]]);

        $ids = array_column($result['ordered_signals'], 'signal_id');
        $this->assertContains(AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_REDUCE_GIVE_BACK, $ids);
    }

    public function test_reduce_give_back_does_not_fire_below_threshold(): void
    {
        $result = $this->router()->route(['metrics' => ['give_back_rate' => 0.29]]);

        $ids = array_column($result['ordered_signals'], 'signal_id');
        $this->assertNotContains(AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_REDUCE_GIVE_BACK, $ids);
    }

    public function test_reduce_give_back_batch_constraint(): void
    {
        $result = $this->router()->route(['metrics' => ['give_back_rate' => 0.50]]);

        $entry = $this->findSignal($result, AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_REDUCE_GIVE_BACK);
        $this->assertSame('max_batch_size=5', $entry['batch_constraint']);
    }

    // ── queue_saturation ──────────────────────────────────────────────────────

    public function test_drain_queue_signal_fires_at_threshold(): void
    {
        $result = $this->router()->route(['metrics' => ['queue_saturation' => 0.80]]);

        $ids = array_column($result['ordered_signals'], 'signal_id');
        $this->assertContains(AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_DRAIN_QUEUE, $ids);
    }

    public function test_drain_queue_batch_constraint(): void
    {
        $result = $this->router()->route(['metrics' => ['queue_saturation' => 0.95]]);

        $entry = $this->findSignal($result, AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_DRAIN_QUEUE);
        $this->assertSame('pause_new_origination', $entry['batch_constraint']);
    }

    // ── evidence_freshness ────────────────────────────────────────────────────

    public function test_refresh_evidence_signal_fires_below_threshold(): void
    {
        $result = $this->router()->route(['metrics' => ['evidence_freshness' => 0.30]]);

        $ids = array_column($result['ordered_signals'], 'signal_id');
        $this->assertContains(AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_REFRESH_EVIDENCE, $ids);
    }

    public function test_refresh_evidence_does_not_fire_at_threshold(): void
    {
        $result = $this->router()->route(['metrics' => ['evidence_freshness' => 0.50]]);

        $ids = array_column($result['ordered_signals'], 'signal_id');
        $this->assertNotContains(AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_REFRESH_EVIDENCE, $ids);
    }

    public function test_refresh_evidence_batch_constraint(): void
    {
        $result = $this->router()->route(['metrics' => ['evidence_freshness' => 0.10]]);

        $entry = $this->findSignal($result, AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_REFRESH_EVIDENCE);
        $this->assertSame('evidence_first=true', $entry['batch_constraint']);
    }

    // ── hint_entropy ──────────────────────────────────────────────────────────

    public function test_diversify_hints_signal_fires_below_threshold(): void
    {
        $result = $this->router()->route(['metrics' => ['hint_entropy' => 0.20]]);

        $ids = array_column($result['ordered_signals'], 'signal_id');
        $this->assertContains(AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_DIVERSIFY_HINTS, $ids);
    }

    public function test_diversify_hints_batch_constraint(): void
    {
        $result = $this->router()->route(['metrics' => ['hint_entropy' => 0.10]]);

        $entry = $this->findSignal($result, AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_DIVERSIFY_HINTS);
        $this->assertSame('require_hint_diversity=true', $entry['batch_constraint']);
    }

    // ── compounding_score ─────────────────────────────────────────────────────

    public function test_boost_compounding_signal_fires_below_threshold(): void
    {
        $result = $this->router()->route(['metrics' => ['compounding_score' => 0.20]]);

        $ids = array_column($result['ordered_signals'], 'signal_id');
        $this->assertContains(AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_BOOST_COMPOUNDING, $ids);
    }

    public function test_boost_compounding_batch_constraint(): void
    {
        $result = $this->router()->route(['metrics' => ['compounding_score' => 0.10]]);

        $entry = $this->findSignal($result, AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_BOOST_COMPOUNDING);
        $this->assertSame('min_compounding_value=0.5', $entry['batch_constraint']);
    }

    // ── priority ordering ─────────────────────────────────────────────────────

    public function test_signals_are_ordered_by_priority(): void
    {
        $result = $this->router()->route([
            'metrics' => [
                'compounding_score'  => 0.10,  // priority 5
                'evidence_freshness' => 0.10,  // priority 3
                'give_back_rate'     => 0.50,  // priority 1
                'queue_saturation'   => 0.90,  // priority 2
                'hint_entropy'       => 0.10,  // priority 4
            ],
        ]);

        $priorities = array_column($result['ordered_signals'], 'priority');
        $sorted     = $priorities;
        sort($sorted);
        $this->assertSame($sorted, $priorities);
    }

    public function test_give_back_has_priority_1(): void
    {
        $result = $this->router()->route(['metrics' => ['give_back_rate' => 0.50]]);

        $entry = $this->findSignal($result, AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_REDUCE_GIVE_BACK);
        $this->assertSame(1, $entry['priority']);
    }

    // ── vanity metrics ────────────────────────────────────────────────────────

    public function test_unknown_metric_keys_are_classified_as_vanity(): void
    {
        $result = $this->router()->route([
            'metrics' => [
                'total_tasks_ever_logged' => 9000,
                'dashboard_view_count'    => 42,
                'give_back_rate'          => 0.50,
            ],
        ]);

        $this->assertContains('total_tasks_ever_logged', $result['skipped_vanity_metrics']);
        $this->assertContains('dashboard_view_count', $result['skipped_vanity_metrics']);
        $this->assertNotContains('give_back_rate', $result['skipped_vanity_metrics']);
    }

    public function test_no_vanity_when_only_actionable_metrics_provided(): void
    {
        $result = $this->router()->route([
            'metrics' => ['give_back_rate' => 0.10, 'queue_saturation' => 0.50],
        ]);

        $this->assertSame([], $result['skipped_vanity_metrics']);
    }

    // ── recommended_next_batch_constraints ───────────────────────────────────

    public function test_constraints_include_all_triggered_signals(): void
    {
        $result = $this->router()->route([
            'metrics' => [
                'give_back_rate'  => 0.50,
                'queue_saturation' => 0.90,
            ],
        ]);

        $this->assertContains('max_batch_size=5', $result['recommended_next_batch_constraints']);
        $this->assertContains('pause_new_origination', $result['recommended_next_batch_constraints']);
    }

    // ── custom thresholds ─────────────────────────────────────────────────────

    public function test_custom_threshold_overrides_default(): void
    {
        // Custom give_back threshold of 0.70 → rate of 0.50 should NOT trigger.
        $result = $this->router()->route([
            'metrics'    => ['give_back_rate' => 0.50],
            'thresholds' => ['give_back_rate' => 0.70],
        ]);

        $ids = array_column($result['ordered_signals'], 'signal_id');
        $this->assertNotContains(AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_REDUCE_GIVE_BACK, $ids);
    }

    // ── rationale non-empty ───────────────────────────────────────────────────

    public function test_each_signal_has_non_empty_rationale(): void
    {
        $result = $this->router()->route([
            'metrics' => ['give_back_rate' => 0.50, 'compounding_score' => 0.10],
        ]);

        foreach ($result['ordered_signals'] as $signal) {
            $this->assertNotEmpty($signal['rationale']);
        }
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = [
            'metrics' => [
                'give_back_rate'     => 0.40,
                'evidence_freshness' => 0.30,
                'vanity_counter'     => 999,
            ],
        ];

        $this->assertSame($this->router()->route($input), $this->router()->route($input));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function findSignal(array $result, string $signalId): array
    {
        foreach ($result['ordered_signals'] as $s) {
            if ($s['signal_id'] === $signalId) {
                return $s;
            }
        }
        $this->fail("Signal '{$signalId}' not found in ordered_signals");
    }
}
