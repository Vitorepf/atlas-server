<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMetricsOptimizationSignalRouter;
use Tests\TestCase;

final class AtlasExternalBrainMetricsOptimizationSignalRouterTest extends TestCase
{
    public function test_actionable_metrics_emit_ordered_signals_with_batch_constraints(): void
    {
        $result = (new AtlasExternalBrainMetricsOptimizationSignalRouter)->route([
            'metrics' => [
                'give_back_rate' => 0.5,
                'queue_saturation' => 0.9,
                'evidence_freshness' => 0.2,
                'hint_entropy' => 0.1,
                'compounding_score' => 0.2,
            ],
        ]);

        $signalIds = array_column($result['ordered_signals'], 'signal_id');
        $this->assertSame(
            [
                AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_REDUCE_GIVE_BACK,
                AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_DRAIN_QUEUE,
                AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_REFRESH_EVIDENCE,
                AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_DIVERSIFY_HINTS,
                AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_BOOST_COMPOUNDING,
            ],
            $signalIds,
        );
        $this->assertNotEmpty($result['recommended_next_batch_constraints']);
    }

    public function test_unknown_metrics_are_skipped_as_vanity(): void
    {
        $result = (new AtlasExternalBrainMetricsOptimizationSignalRouter)->route([
            'metrics' => ['some_random_metric' => 42],
        ]);

        $this->assertContains('some_random_metric', $result['skipped_vanity_metrics']);
        $this->assertSame([], $result['ordered_signals']);
    }

    public function test_raw_task_count_only_triggers_with_quality_context(): void
    {
        $withoutContext = (new AtlasExternalBrainMetricsOptimizationSignalRouter)->route([
            'metrics' => ['task_count' => 100],
        ]);
        $this->assertContains('task_count', $withoutContext['skipped_vanity_metrics']);
        $this->assertSame([], $withoutContext['ordered_signals']);

        $withContext = (new AtlasExternalBrainMetricsOptimizationSignalRouter)->route([
            'metrics' => ['task_count' => 100],
            'context' => ['task_quality_rate' => 0.1],
        ]);
        $this->assertContains(
            AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_STOP_VOLUME_GROWTH,
            array_column($withContext['ordered_signals'], 'signal_id'),
        );
    }

    public function test_raw_green_commits_only_triggers_with_give_back_context(): void
    {
        $withoutContext = (new AtlasExternalBrainMetricsOptimizationSignalRouter)->route([
            'metrics' => ['green_commits' => 20],
        ]);
        $this->assertContains('green_commits', $withoutContext['skipped_vanity_metrics']);

        $withContext = (new AtlasExternalBrainMetricsOptimizationSignalRouter)->route([
            'metrics' => ['green_commits' => 20, 'give_back_rate' => 0.5],
        ]);
        $this->assertContains(
            AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_REPAIR_QUEUE_DEPTH,
            array_column($withContext['ordered_signals'], 'signal_id'),
        );
    }

    public function test_raw_queue_depth_only_triggers_with_saturation_context(): void
    {
        $withoutContext = (new AtlasExternalBrainMetricsOptimizationSignalRouter)->route([
            'metrics' => ['queue_depth' => 50],
        ]);
        $this->assertContains('queue_depth', $withoutContext['skipped_vanity_metrics']);

        $withContext = (new AtlasExternalBrainMetricsOptimizationSignalRouter)->route([
            'metrics' => ['queue_depth' => 50, 'queue_saturation' => 0.9],
        ]);
        $this->assertContains(
            AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_SIMPLIFY_QUEUE,
            array_column($withContext['ordered_signals'], 'signal_id'),
        );
    }

    public function test_raw_model_lift_only_trusts_accepted_evidence_context(): void
    {
        $unaccepted = (new AtlasExternalBrainMetricsOptimizationSignalRouter)->route([
            'metrics' => ['model_lift' => 0.3],
            'context' => ['evidence_type' => 'self_declared'],
        ]);
        $this->assertContains(
            AtlasExternalBrainMetricsOptimizationSignalRouter::SIGNAL_CALIBRATE_MODEL,
            array_column($unaccepted['ordered_signals'], 'signal_id'),
        );

        $accepted = (new AtlasExternalBrainMetricsOptimizationSignalRouter)->route([
            'metrics' => ['model_lift' => 0.3],
            'context' => ['evidence_type' => 'before_after_benchmark'],
        ]);
        $this->assertContains('model_lift', $accepted['skipped_vanity_metrics']);
    }
}
