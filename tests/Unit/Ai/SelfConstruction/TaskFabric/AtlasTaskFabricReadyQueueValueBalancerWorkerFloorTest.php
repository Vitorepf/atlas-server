<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricReadyQueueValueBalancer;
use PHPUnit\Framework\TestCase;

final class AtlasTaskFabricReadyQueueValueBalancerWorkerFloorTest extends TestCase
{
    private function balancer(): AtlasTaskFabricReadyQueueValueBalancer
    {
        return new AtlasTaskFabricReadyQueueValueBalancer;
    }

    private function group(string $dimension, int $count = 5, float $value = 0.8, float $poison = 0.05): array
    {
        return ['dimension' => $dimension, 'count' => $count, 'expected_value' => $value, 'poison_risk' => $poison];
    }

    private function diverseGroups(int $perGroup = 5): array
    {
        return [
            $this->group('queue_health', $perGroup),
            $this->group('verification', $perGroup),
            $this->group('implementation', $perGroup),
            $this->group('learning', $perGroup),
            $this->group('hardening', $perGroup),
        ];
    }

    public function test_deep_diverse_queue_below_worker_floor_still_originates_more(): void
    {
        // 25 total tasks across 5 dimensions (deep + diverse), but 30 workers each
        // requiring at least 1 ready task means the floor (30) exceeds the total (25).
        $r = $this->balancer()->balance([
            'task_groups' => $this->diverseGroups(5),
            'worker_count' => 30,
            'minimum_ready_per_worker' => 1,
        ]);

        $this->assertSame('originate_more', $r['recommendation']);
        $this->assertSame('worker_floor', $r['reason']);
        $this->assertTrue($r['diagnostics']['below_worker_floor']);
        $this->assertSame(30, $r['diagnostics']['worker_floor']);
    }

    public function test_deep_diverse_queue_above_worker_floor_still_stops_or_consolidates(): void
    {
        $r = $this->balancer()->balance([
            'task_groups' => $this->diverseGroups(5),
            'worker_count' => 5,
            'minimum_ready_per_worker' => 1,
        ]);

        $this->assertSame('stop_or_consolidate', $r['recommendation']);
        $this->assertSame('queue_deep_and_diverse', $r['reason']);
        $this->assertFalse($r['diagnostics']['below_worker_floor']);
    }

    public function test_higher_minimum_ready_per_worker_widens_the_floor(): void
    {
        $r = $this->balancer()->balance([
            'task_groups' => $this->diverseGroups(5),
            'worker_count' => 5,
            'minimum_ready_per_worker' => 6,
        ]);

        $this->assertSame(30, $r['diagnostics']['worker_floor']);
        $this->assertSame('originate_more', $r['recommendation']);
        $this->assertSame('worker_floor', $r['reason']);
    }

    public function test_exactly_at_worker_floor_is_not_below_floor(): void
    {
        $r = $this->balancer()->balance([
            'task_groups' => $this->diverseGroups(5),
            'worker_count' => 25,
            'minimum_ready_per_worker' => 1,
        ]);

        $this->assertFalse($r['diagnostics']['below_worker_floor']);
        $this->assertSame('stop_or_consolidate', $r['recommendation']);
    }

    public function test_deep_dimension_sparse_queue_with_replenish_soon_recommends_originate_targeted(): void
    {
        $r = $this->balancer()->balance([
            'task_groups' => [
                $this->group('implementation', 25),
            ],
            'worker_count' => 1,
            'minimum_ready_per_worker' => 1,
            'queue_facts' => ['replenish_recommendation' => 'replenish_soon'],
        ]);

        $this->assertSame('originate_targeted', $r['recommendation']);
        $this->assertNotEmpty($r['target_dimensions']);
        $this->assertTrue($r['diagnostics']['external_worker_floor_signal']);
    }

    public function test_deep_dimension_sparse_queue_with_worker_floor_breach_recommends_originate_targeted(): void
    {
        $r = $this->balancer()->balance([
            'task_groups' => [
                $this->group('implementation', 25),
            ],
            'worker_count' => 1,
            'minimum_ready_per_worker' => 1,
            'queue_facts' => ['worker_floor_breach' => true],
        ]);

        $this->assertSame('originate_targeted', $r['recommendation']);
        $this->assertNotEmpty($r['target_dimensions']);
    }

    public function test_poison_heavy_queue_with_worker_floor_signal_still_stops_or_consolidates(): void
    {
        $r = $this->balancer()->balance([
            'task_groups' => [
                $this->group('implementation', 25, 0.5, 0.9),
            ],
            'worker_count' => 1,
            'minimum_ready_per_worker' => 1,
            'queue_facts' => ['replenish_recommendation' => 'replenish_soon'],
        ]);

        $this->assertSame('stop_or_consolidate', $r['recommendation']);
        $this->assertSame('high_poison_ratio_blocks_progress', $r['reason']);
    }
}
