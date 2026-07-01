<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainValueDensityQueueOptimizer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainValueDensityQueueOptimizerTest extends TestCase
{
    private function optimizer(): AtlasExternalBrainValueDensityQueueOptimizer
    {
        return new AtlasExternalBrainValueDensityQueueOptimizer;
    }

    // ── AC2: worker-feed buffer reserves top-density candidates ──────────────

    public function test_rank_candidates_reserves_minimum_worker_feed_count_for_top_density_candidates(): void
    {
        $result = $this->optimizer()->rankCandidates([
            'claimable_count' => 20,
            'capacity' => 5,
            'active_worker_count' => 2,
            'minimum_worker_feed_count' => 2,
            'candidates' => [
                ['task_id' => 'high-1', 'impact' => 0.9, 'implementation_size' => 30],
                ['task_id' => 'high-2', 'impact' => 0.8, 'implementation_size' => 30],
                ['task_id' => 'low-1', 'impact' => 0.05, 'implementation_size' => 120],
                ['task_id' => 'low-2', 'impact' => 0.05, 'implementation_size' => 120],
            ],
        ]);

        $byId = [];
        foreach ($result['ranked_candidates'] as $c) {
            $byId[$c['task_id']] = $c;
        }

        // Top 2 by density (high-1, high-2) must be enqueue due to the worker-feed buffer,
        // even under a saturated queue_pressure cutoff.
        $this->assertSame(AtlasExternalBrainValueDensityQueueOptimizer::DECISION_ENQUEUE, $byId['high-1']['decision']);
        $this->assertSame(AtlasExternalBrainValueDensityQueueOptimizer::DECISION_ENQUEUE, $byId['high-2']['decision']);
    }

    public function test_worker_feed_buffer_does_not_apply_when_no_active_workers(): void
    {
        $result = $this->optimizer()->rankCandidates([
            'claimable_count' => 20,
            'capacity' => 5,
            'active_worker_count' => 0,
            'minimum_worker_feed_count' => 2,
            'candidates' => [
                ['task_id' => 'low-1', 'impact' => 0.01, 'implementation_size' => 200],
            ],
        ]);

        $this->assertSame(AtlasExternalBrainValueDensityQueueOptimizer::DECISION_DEFER, $result['ranked_candidates'][0]['decision']);
    }

    // ── AC3: critical blocker removal is never deferred ───────────────────────

    public function test_critical_blocker_removal_is_enqueued_despite_low_density_under_saturated_cutoff(): void
    {
        $result = $this->optimizer()->rankCandidates([
            'claimable_count' => 50,
            'capacity' => 5,
            'candidates' => [
                ['task_id' => 'blocker', 'impact' => 0.01, 'implementation_size' => 300, 'is_critical_blocker_removal' => true],
            ],
        ]);

        $row = $result['ranked_candidates'][0];
        $this->assertSame(AtlasExternalBrainValueDensityQueueOptimizer::DECISION_ENQUEUE, $row['decision']);
        $this->assertSame('critical_blocker_removal_preserved_despite_low_density', $row['decision_reason']);
    }

    // ── AC4: optimize routes to self_heal_or_respec instead of feed_queue when risk dominates ──

    public function test_shallow_queue_dominated_by_risk_routes_to_self_heal_or_respec(): void
    {
        $result = $this->optimizer()->optimize([
            'muscle_count' => 2,
            'packets' => [
                ['packet_id' => 'p1', 'expected_value' => 1.0, 'estimated_worker_minutes' => 10, 'give_back_risk' => 0.9, 'malformed_risk' => 0.9],
                ['packet_id' => 'p2', 'expected_value' => 1.0, 'estimated_worker_minutes' => 10, 'give_back_risk' => 0.9, 'malformed_risk' => 0.9],
            ],
        ]);

        $this->assertSame(AtlasExternalBrainValueDensityQueueOptimizer::ACTION_SELF_HEAL_OR_RESPEC, $result['action']);
    }

    public function test_shallow_queue_with_clean_packets_routes_to_feed_queue_not_self_heal(): void
    {
        $result = $this->optimizer()->optimize([
            'muscle_count' => 2,
            'packets' => [
                ['packet_id' => 'p1', 'expected_value' => 1.0, 'estimated_worker_minutes' => 10],
            ],
        ]);

        $this->assertSame(AtlasExternalBrainValueDensityQueueOptimizer::ACTION_FEED_QUEUE, $result['action']);
    }
}
