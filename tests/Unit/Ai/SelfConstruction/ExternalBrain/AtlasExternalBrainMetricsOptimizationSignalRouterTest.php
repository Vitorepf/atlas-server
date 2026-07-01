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

    // ── AC3/AC4: each signal emits signal_type, action, confidence, anti_goodhart_reason ──

    public function test_each_signal_emits_signal_type_action_confidence_and_anti_goodhart_reason(): void
    {
        $result = $this->router()->route(['metrics' => ['give_back_rate' => 0.90]]);

        $signal = $this->findSignal($result, AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_REDUCE_GIVE_BACK);
        $this->assertSame(AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_TYPE_OUTCOME_QUALITY, $signal['signal_type']);
        $this->assertSame(AtlasExternalBrainMetricsOptimizationSignalRouter::ACTION_REPAIR_QUEUE, $signal['action']);
        $this->assertSame('high', $signal['confidence']);
        $this->assertNotEmpty($signal['anti_goodhart_reason']);
    }

    // ── AC1/AC2: contextual volume metrics — vanity without companion context ──

    public function test_task_count_alone_is_vanity_without_quality_context(): void
    {
        $result = $this->router()->route(['metrics' => ['task_count' => 50]]);

        $this->assertSame([], $result['ordered_signals']);
        $this->assertContains('task_count', $result['skipped_vanity_metrics']);
    }

    public function test_task_count_with_low_quality_context_triggers_stop_volume_growth(): void
    {
        $result = $this->router()->route([
            'metrics' => ['task_count' => 50],
            'context' => ['task_quality_rate' => 0.20],
        ]);

        $signal = $this->findSignal($result, AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_STOP_VOLUME_GROWTH);
        $this->assertSame(AtlasExternalBrainMetricsOptimizationSignalRouter::ACTION_STOP_VOLUME_GROWTH, $signal['action']);
        $this->assertNotContains('task_count', $result['skipped_vanity_metrics']);
    }

    public function test_task_count_with_healthy_quality_context_is_vanity(): void
    {
        $result = $this->router()->route([
            'metrics' => ['task_count' => 50],
            'context' => ['task_quality_rate' => 0.90],
        ]);

        $this->assertSame([], $result['ordered_signals']);
        $this->assertContains('task_count', $result['skipped_vanity_metrics']);
    }

    public function test_green_commits_alone_is_vanity_without_give_back_context(): void
    {
        $result = $this->router()->route(['metrics' => ['green_commits' => 30]]);

        $this->assertSame([], $result['ordered_signals']);
        $this->assertContains('green_commits', $result['skipped_vanity_metrics']);
    }

    public function test_green_commits_with_high_give_back_rate_triggers_repair_queue(): void
    {
        $result = $this->router()->route(['metrics' => [
            'green_commits'   => 30,
            'give_back_rate'  => 0.50,
        ]]);

        $signal = $this->findSignal($result, AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_REPAIR_QUEUE_DEPTH);
        $this->assertSame(AtlasExternalBrainMetricsOptimizationSignalRouter::ACTION_REPAIR_QUEUE, $signal['action']);
    }

    public function test_queue_depth_alone_is_vanity_without_saturation_context(): void
    {
        $result = $this->router()->route(['metrics' => ['queue_depth' => 200]]);

        $this->assertSame([], $result['ordered_signals']);
        $this->assertContains('queue_depth', $result['skipped_vanity_metrics']);
    }

    public function test_queue_depth_with_high_saturation_triggers_simplify(): void
    {
        $result = $this->router()->route(['metrics' => [
            'queue_depth'      => 200,
            'queue_saturation' => 0.95,
        ]]);

        $signal = $this->findSignal($result, AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_SIMPLIFY_QUEUE);
        $this->assertSame(AtlasExternalBrainMetricsOptimizationSignalRouter::ACTION_SIMPLIFY, $signal['action']);
    }

    public function test_model_lift_without_accepted_evidence_type_triggers_calibrate_model(): void
    {
        $result = $this->router()->route([
            'metrics' => ['model_lift' => 0.30],
            'context' => ['evidence_type' => 'self_declared'],
        ]);

        $signal = $this->findSignal($result, AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_CALIBRATE_MODEL);
        $this->assertSame(AtlasExternalBrainMetricsOptimizationSignalRouter::ACTION_CALIBRATE_MODEL, $signal['action']);
    }

    public function test_model_lift_without_any_evidence_type_triggers_calibrate_model(): void
    {
        $result = $this->router()->route(['metrics' => ['model_lift' => 0.30]]);

        $signal = $this->findSignal($result, AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_CALIBRATE_MODEL);
        $this->assertSame(AtlasExternalBrainMetricsOptimizationSignalRouter::ACTION_CALIBRATE_MODEL, $signal['action']);
    }

    public function test_model_lift_with_accepted_evidence_type_is_not_a_signal(): void
    {
        $result = $this->router()->route([
            'metrics' => ['model_lift' => 0.30],
            'context' => ['evidence_type' => 'before_after_benchmark'],
        ]);

        $this->assertSame([], $result['ordered_signals']);
        $this->assertContains('model_lift', $result['skipped_vanity_metrics']);
    }

    // ── AC2: classification vocabulary — optimize / guardrail / investigate / reject_proxy_metric ──

    public function test_valid_quality_metric_classified_as_optimize(): void
    {
        $result = $this->router()->route(['metrics' => ['evidence_freshness' => 0.90]]); // above threshold, healthy

        $this->assertSame(
            AtlasExternalBrainMetricsOptimizationSignalRouter::CLASSIFICATION_OPTIMIZE,
            $result['metric_classifications']['evidence_freshness'],
        );
    }

    public function test_proxy_count_metric_classified_as_reject_proxy_metric(): void
    {
        $result = $this->router()->route(['metrics' => ['task_count' => 50]]); // no companion context

        $this->assertSame(
            AtlasExternalBrainMetricsOptimizationSignalRouter::CLASSIFICATION_REJECT_PROXY_METRIC,
            $result['metric_classifications']['task_count'],
        );
    }

    public function test_unknown_metric_key_classified_as_reject_proxy_metric(): void
    {
        $result = $this->router()->route(['metrics' => ['fake_vanity_metric' => 42]]);

        $this->assertSame(
            AtlasExternalBrainMetricsOptimizationSignalRouter::CLASSIFICATION_REJECT_PROXY_METRIC,
            $result['metric_classifications']['fake_vanity_metric'],
        );
    }

    public function test_risk_signal_classified_as_guardrail(): void
    {
        $result = $this->router()->route(['metrics' => ['queue_saturation' => 0.90]]);

        $this->assertSame(
            AtlasExternalBrainMetricsOptimizationSignalRouter::CLASSIFICATION_GUARDRAIL,
            $result['metric_classifications']['queue_saturation'],
        );
        $signal = $this->findSignal($result, AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_DRAIN_QUEUE);
        $this->assertSame(AtlasExternalBrainMetricsOptimizationSignalRouter::CLASSIFICATION_GUARDRAIL, $signal['classification']);
    }

    // ── AC4: conflicting metric — accepted evidence type but contradicted by prior result ──

    public function test_conflicting_metric_is_classified_as_investigate(): void
    {
        $result = $this->router()->route([
            'metrics' => ['model_lift' => 0.30],
            'context' => ['evidence_type' => 'before_after_benchmark', 'contradicting_evidence' => true],
        ]);

        $this->assertSame(
            AtlasExternalBrainMetricsOptimizationSignalRouter::CLASSIFICATION_INVESTIGATE,
            $result['metric_classifications']['model_lift'],
        );
        $this->assertSame([], $result['ordered_signals'], 'a conflicting metric must not blindly fire a signal');
    }

    // ── AC4: stale metric — a metric older than the freshness ceiling is never trusted at face value ──

    public function test_stale_metric_is_classified_as_investigate_and_does_not_fire(): void
    {
        $result = $this->router()->route([
            'metrics' => ['give_back_rate' => 0.90], // would otherwise fire reduce_give_back
            'metric_ages_seconds' => ['give_back_rate' => 7200], // 2h old, past the 1h ceiling
        ]);

        $this->assertSame(
            AtlasExternalBrainMetricsOptimizationSignalRouter::CLASSIFICATION_INVESTIGATE,
            $result['metric_classifications']['give_back_rate'],
        );
        $this->assertContains('give_back_rate', $result['stale_metrics']);
        $this->assertSame([], $result['ordered_signals']);
    }

    public function test_fresh_metric_is_not_flagged_stale(): void
    {
        $result = $this->router()->route([
            'metrics' => ['give_back_rate' => 0.90],
            'metric_ages_seconds' => ['give_back_rate' => 60],
        ]);

        $this->assertSame([], $result['stale_metrics']);
        $signal = $this->findSignal($result, AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_REDUCE_GIVE_BACK);
        $this->assertNotEmpty($signal);
    }

    // ── AC4: guardrail route ──────────────────────────────────────────────────

    public function test_guardrail_route_for_calibrate_model_signal(): void
    {
        $result = $this->router()->route(['metrics' => ['model_lift' => 0.30]]);

        $this->assertSame(
            AtlasExternalBrainMetricsOptimizationSignalRouter::CLASSIFICATION_GUARDRAIL,
            $result['metric_classifications']['model_lift'],
        );
    }

    public function test_output_has_metric_classifications_and_stale_metrics_keys(): void
    {
        $result = $this->router()->route([]);

        $this->assertArrayHasKey('metric_classifications', $result);
        $this->assertArrayHasKey('stale_metrics', $result);
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
