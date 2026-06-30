<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricReadyQueueValueBalancer;
use PHPUnit\Framework\TestCase;

final class AtlasTaskFabricReadyQueueValueBalancerTest extends TestCase
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

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->balancer()->balance([]);
        $this->assertSame(AtlasTaskFabricReadyQueueValueBalancer::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('recommendation', $r);
        $this->assertArrayHasKey('reason', $r);
        $this->assertArrayHasKey('target_dimensions', $r);
        $this->assertArrayHasKey('diagnostics', $r);
    }

    public function test_empty_queue_recommends_originate_more(): void
    {
        $r = $this->balancer()->balance([]);
        $this->assertSame('originate_more', $r['recommendation']);
        $this->assertSame('queue_too_thin', $r['reason']);
    }

    // ── AC1: recommendation based on task mix ─────────────────────────────────

    public function test_thin_queue_recommends_originate_more(): void
    {
        $r = $this->balancer()->balance([
            'task_groups' => [$this->group('implementation', 5)],
        ]);
        $this->assertSame('originate_more', $r['recommendation']);
    }

    public function test_deep_and_diverse_queue_stops_origination(): void
    {
        // 5 groups × 5 tasks = 25 tasks (>= 20) with 5 distinct dimensions (>= 3).
        $r = $this->balancer()->balance(['task_groups' => $this->diverseGroups()]);
        $this->assertSame('stop_or_consolidate', $r['recommendation']);
        $this->assertSame('queue_deep_and_diverse', $r['reason']);
    }

    public function test_deep_but_dimension_sparse_recommends_originate_targeted(): void
    {
        // 25 tasks in only 1 dimension → deep but not diverse.
        $r = $this->balancer()->balance([
            'task_groups' => [$this->group('implementation', 25)],
        ]);
        $this->assertSame('originate_targeted', $r['recommendation']);
        $this->assertSame('queue_deep_but_dimension_sparse', $r['reason']);
        $this->assertNotEmpty($r['target_dimensions']); // missing dims suggested
    }

    public function test_target_dimensions_are_canonical_gaps(): void
    {
        $r = $this->balancer()->balance([
            'task_groups' => [$this->group('implementation', 25)],
        ]);
        // implementation is present; target_dimensions should not contain it.
        $this->assertNotContains('implementation', $r['target_dimensions']);
        $this->assertNotEmpty($r['target_dimensions']);
    }

    // ── AC2: stop_or_consolidate when already deep+diverse ───────────────────

    public function test_exactly_at_deep_threshold_with_diversity_stops(): void
    {
        // 3 groups × 7 = 21 tasks (>= DEEP_QUEUE_THRESHOLD=20), 3 dims (>= MIN_DIMENSIONS=3).
        $r = $this->balancer()->balance([
            'task_groups' => [
                $this->group('queue_health', 7),
                $this->group('verification', 7),
                $this->group('implementation', 7),
            ],
        ]);
        $this->assertSame('stop_or_consolidate', $r['recommendation']);
    }

    public function test_just_below_deep_threshold_with_diversity_still_originates(): void
    {
        // 3 groups × 6 = 18 tasks (< 20) — not deep enough.
        $r = $this->balancer()->balance([
            'task_groups' => [
                $this->group('queue_health', 6),
                $this->group('verification', 6),
                $this->group('implementation', 6),
            ],
        ]);
        $this->assertSame('originate_more', $r['recommendation']);
    }

    // ── Poison ratio override ─────────────────────────────────────────────────

    public function test_high_poison_ratio_stops_origination_regardless_of_depth(): void
    {
        // Small queue but high poison ratio (5 tasks, 2 fully poisoned → ratio 0.4 > 0.3).
        $r = $this->balancer()->balance([
            'task_groups' => [
                $this->group('implementation', 3, 0.8, 0.05),
                $this->group('verification', 2, 0.7, 1.0), // 100% poison
            ],
        ]);
        $this->assertSame('stop_or_consolidate', $r['recommendation']);
        $this->assertSame('high_poison_ratio_blocks_progress', $r['reason']);
    }

    public function test_poison_check_uses_weighted_ratio(): void
    {
        // 18 low-poison + 2 full-poison → ratio = 2/20 = 0.1 < 0.3 → not poison-stopped.
        $r = $this->balancer()->balance([
            'task_groups' => [
                $this->group('implementation', 18, 0.8, 0.0),
                $this->group('verification', 2, 0.9, 1.0),
            ],
        ]);
        $this->assertNotSame('high_poison_ratio_blocks_progress', $r['reason']);
    }

    // ── Diagnostics ───────────────────────────────────────────────────────────

    public function test_diagnostics_total_tasks_is_sum_of_group_counts(): void
    {
        $r = $this->balancer()->balance([
            'task_groups' => [$this->group('a', 7), $this->group('b', 5)],
        ]);
        $this->assertSame(12, $r['diagnostics']['total_tasks']);
        $this->assertSame(2, $r['diagnostics']['distinct_dimensions']);
    }

    public function test_output_is_deterministic(): void
    {
        $facts = ['task_groups' => $this->diverseGroups()];
        $a     = $this->balancer()->balance($facts);
        $b     = $this->balancer()->balance($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
