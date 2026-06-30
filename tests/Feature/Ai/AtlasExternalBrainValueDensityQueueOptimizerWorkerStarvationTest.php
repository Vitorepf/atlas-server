<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainValueDensityQueueOptimizer;
use Tests\TestCase;

class AtlasExternalBrainValueDensityQueueOptimizerWorkerStarvationTest extends TestCase
{
    public function test_starvation_does_not_raise_cutoff_and_enqueues_replenishing_candidate(): void
    {
        $optimizer = new AtlasExternalBrainValueDensityQueueOptimizer();

        $result = $optimizer->rankCandidates([
            'value_density_floor' => 0.50,
            'claimable_count' => 2,
            'worker_floor' => 5,
            'capacity' => 1,
            'candidates' => [
                [
                    'task_id' => 'replenish-1',
                    'impact' => 1.0,
                    'dependency_unlocks' => 0,
                    'risk_reduction' => 0.0,
                    'implementation_size' => 30,
                    'replenishes_worker_capacity' => true,
                ],
            ],
        ]);

        $this->assertSame(0.5, $result['cutoff']);

        $candidate = $result['ranked_candidates'][0];
        $this->assertSame('enqueue', $candidate['decision']);
        $this->assertGreaterThanOrEqual(0.5, $candidate['value_density']);
    }

    public function test_saturated_queue_above_worker_floor_still_raises_cutoff_and_defers_low_density(): void
    {
        $optimizer = new AtlasExternalBrainValueDensityQueueOptimizer();

        $result = $optimizer->rankCandidates([
            'value_density_floor' => 0.50,
            'claimable_count' => 10,
            'worker_floor' => 2,
            'capacity' => 1,
            'candidates' => [
                [
                    'task_id' => 'low-density-1',
                    'impact' => 0.5,
                    'dependency_unlocks' => 0,
                    'risk_reduction' => 0.0,
                    'implementation_size' => 30,
                    'replenishes_worker_capacity' => true,
                ],
            ],
        ]);

        $this->assertGreaterThan(0.5, $result['cutoff']);

        $candidate = $result['ranked_candidates'][0];
        $this->assertSame('defer', $candidate['decision']);
        $this->assertSame('value_density_below_cutoff_deferred', $candidate['decision_reason']);
    }

    // ── minimum worker-feed buffer reservation ───────────────────────────────

    public function test_minimum_worker_feed_buffer_preserves_top_low_density_candidates_when_workers_active(): void
    {
        $optimizer = new AtlasExternalBrainValueDensityQueueOptimizer();

        $result = $optimizer->rankCandidates([
            'value_density_floor' => 0.50,
            'claimable_count' => 10,
            'capacity' => 5,
            'active_worker_count' => 2,
            'minimum_worker_feed_count' => 2,
            'candidates' => [
                [
                    'task_id' => 'low-1',
                    'impact' => 0.1,
                    'dependency_unlocks' => 0,
                    'risk_reduction' => 0.0,
                    'implementation_size' => 60,
                ],
                [
                    'task_id' => 'low-2',
                    'impact' => 0.1,
                    'dependency_unlocks' => 0,
                    'risk_reduction' => 0.0,
                    'implementation_size' => 90,
                ],
                [
                    'task_id' => 'low-3',
                    'impact' => 0.05,
                    'dependency_unlocks' => 0,
                    'risk_reduction' => 0.0,
                    'implementation_size' => 120,
                ],
            ],
        ]);

        $enqueued = array_column(
            array_filter($result['ranked_candidates'], static fn (array $c): bool => $c['decision'] === 'enqueue'),
            'task_id',
        );
        $this->assertCount(2, $enqueued);
        $this->assertSame(2, $result['enqueue_count']);
        $this->assertSame(1, $result['defer_count']);
    }

    public function test_no_active_workers_does_not_force_any_minimum_feed_reservation(): void
    {
        $optimizer = new AtlasExternalBrainValueDensityQueueOptimizer();

        $result = $optimizer->rankCandidates([
            'value_density_floor' => 0.50,
            'claimable_count' => 10,
            'capacity' => 5,
            'active_worker_count' => 0,
            'minimum_worker_feed_count' => 2,
            'candidates' => [
                [
                    'task_id' => 'low-1',
                    'impact' => 0.1,
                    'dependency_unlocks' => 0,
                    'risk_reduction' => 0.0,
                    'implementation_size' => 60,
                ],
            ],
        ]);

        $this->assertSame(0, $result['enqueue_count']);
        $this->assertSame(1, $result['defer_count']);
    }

    public function test_feed_buffer_satisfied_still_prefers_higher_value_density_for_remaining_slots(): void
    {
        $optimizer = new AtlasExternalBrainValueDensityQueueOptimizer();

        $result = $optimizer->rankCandidates([
            'value_density_floor' => 0.50,
            'claimable_count' => 10,
            'capacity' => 5,
            'active_worker_count' => 1,
            'minimum_worker_feed_count' => 1,
            'candidates' => [
                [
                    'task_id' => 'high-density',
                    'impact' => 1.0,
                    'dependency_unlocks' => 3,
                    'risk_reduction' => 1.0,
                    'implementation_size' => 10,
                ],
                [
                    'task_id' => 'low-density',
                    'impact' => 0.1,
                    'dependency_unlocks' => 0,
                    'risk_reduction' => 0.0,
                    'implementation_size' => 90,
                ],
            ],
        ]);

        // The buffer (size 1) is consumed by the top-ranked high-density candidate already; the
        // low-density one still defers — the buffer never overrides preference for higher density.
        $this->assertSame('enqueue', $result['ranked_candidates'][0]['decision']);
        $this->assertSame('high-density', $result['ranked_candidates'][0]['task_id']);
        $this->assertSame('defer', $result['ranked_candidates'][1]['decision']);
    }
}
