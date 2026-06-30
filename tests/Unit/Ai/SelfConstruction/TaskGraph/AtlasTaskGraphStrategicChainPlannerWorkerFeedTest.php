<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskGraph;

use App\Services\Ai\SelfConstruction\TaskGraph\AtlasTaskGraphStrategicChainPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasTaskGraphStrategicChainPlannerWorkerFeedTest extends TestCase
{
    private function planner(): AtlasTaskGraphStrategicChainPlanner
    {
        return new AtlasTaskGraphStrategicChainPlanner;
    }

    private function task(array $overrides = []): array
    {
        return array_merge([
            'id'            => 't1',
            'prerequisites' => [],
            'unlocks'       => [],
            'risk'          => 0.10,
            'payoff'        => 0.50,
        ], $overrides);
    }

    public function test_worker_feed_tasks_ordered_before_expansion_when_claimable_per_active_worker_below_target(): void
    {
        $result = $this->planner()->plan([
            'queue_facts' => ['claimable_per_active_worker' => 0.3],
            'tasks' => [
                $this->task(['id' => 'expand_a']),
                $this->task(['id' => 'feed_b', 'worker_feed' => true]),
            ],
        ]);

        $flatOrder = array_merge(...$result['chains']);
        $this->assertSame('feed_b', $flatOrder[0]);
        $this->assertSame('expand_a', $flatOrder[1]);
    }

    public function test_worker_feed_precedence_not_applied_when_claimable_per_active_worker_meets_target(): void
    {
        $result = $this->planner()->plan([
            'queue_facts' => ['claimable_per_active_worker' => 2.0],
            'tasks' => [
                $this->task(['id' => 'expand_a']),
                $this->task(['id' => 'feed_b', 'worker_feed' => true]),
            ],
        ]);

        $flatOrder = array_merge(...$result['chains']);
        // No starvation: stable id-order tiebreak puts expand_a first.
        $this->assertSame('expand_a', $flatOrder[0]);
        $this->assertSame('feed_b', $flatOrder[1]);
    }

    public function test_cyclic_tasks_still_report_unresolved_and_are_excluded_from_chains(): void
    {
        $result = $this->planner()->plan([
            'queue_facts' => ['claimable_per_active_worker' => 0.3],
            'tasks' => [
                $this->task(['id' => 'cyc_a', 'prerequisites' => ['cyc_b']]),
                $this->task(['id' => 'cyc_b', 'prerequisites' => ['cyc_a']]),
                $this->task(['id' => 'feed_c', 'worker_feed' => true]),
            ],
        ]);

        $this->assertSame(['cyc_a', 'cyc_b'], $result['unresolved_tasks']);

        $flatOrder = $result['chains'] === [] ? [] : array_merge(...$result['chains']);
        $this->assertNotContains('cyc_a', $flatOrder);
        $this->assertNotContains('cyc_b', $flatOrder);
        $this->assertContains('feed_c', $flatOrder);
    }

    public function test_replenish_soon_orders_worker_feed_first_even_with_comfortable_ratio(): void
    {
        $result = $this->planner()->plan([
            'queue_facts' => ['claimable_per_active_worker' => 3, 'replenish_recommendation' => 'replenish_soon'],
            'worker_feed_target' => 5,
            'tasks' => [
                $this->task(['id' => 'expand_a', 'worker_feed' => false]),
                $this->task(['id' => 'feed_b', 'worker_feed' => true]),
            ],
        ]);

        $flatOrder = array_merge(...$result['chains']);
        $this->assertSame('feed_b', $flatOrder[0]);
        $this->assertSame('expand_a', $flatOrder[1]);
    }

    public function test_comfortable_buffer_with_high_worker_feed_target_preserves_existing_ordering(): void
    {
        $result = $this->planner()->plan([
            'queue_facts' => ['claimable_per_active_worker' => 10],
            'worker_feed_target' => 5,
            'tasks' => [
                $this->task(['id' => 'expand_a', 'worker_feed' => false]),
                $this->task(['id' => 'feed_b', 'worker_feed' => true]),
            ],
        ]);

        $flatOrder = array_merge(...$result['chains']);
        $this->assertSame('expand_a', $flatOrder[0]);
        $this->assertSame('feed_b', $flatOrder[1]);
    }
}
