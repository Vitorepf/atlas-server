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
}
