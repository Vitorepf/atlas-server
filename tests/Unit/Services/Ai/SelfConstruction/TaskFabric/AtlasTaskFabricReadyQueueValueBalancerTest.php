<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricReadyQueueValueBalancer;
use PHPUnit\Framework\TestCase;

/**
 * Focused contract test: proves a high poison ratio stops/consolidates, a deep diverse queue
 * stops, a deep dimension-sparse queue originates targeted missing dimensions, a thin queue
 * originates more, a worker_floor breach overrides deep-diverse stop into originate_more, and
 * external worker-floor signals (queue_facts.replenish_soon/urgently) are surfaced in
 * diagnostics without bypassing dimension diversity.
 */
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

    public function test_high_poison_ratio_stops_or_consolidates_regardless_of_depth(): void
    {
        $r = $this->balancer()->balance([
            'task_groups' => [
                $this->group('implementation', 3, 0.8, 0.05),
                $this->group('verification', 2, 0.7, 1.0),
            ],
        ]);

        self::assertSame('stop_or_consolidate', $r['recommendation']);
        self::assertSame('high_poison_ratio_blocks_progress', $r['reason']);
    }

    public function test_deep_diverse_queue_stops(): void
    {
        $r = $this->balancer()->balance(['task_groups' => $this->diverseGroups(5)]);

        self::assertSame('stop_or_consolidate', $r['recommendation']);
        self::assertSame('queue_deep_and_diverse', $r['reason']);
    }

    public function test_deep_dimension_sparse_queue_originates_targeted_missing_dimensions(): void
    {
        $r = $this->balancer()->balance([
            'task_groups' => [$this->group('implementation', 25)],
        ]);

        self::assertSame('originate_targeted', $r['recommendation']);
        self::assertNotEmpty($r['target_dimensions']);
        self::assertNotContains('implementation', $r['target_dimensions']);
    }

    public function test_target_dimensions_only_appear_for_originate_targeted(): void
    {
        $originateMore = $this->balancer()->balance(['task_groups' => [$this->group('implementation', 5)]]);
        self::assertSame('originate_more', $originateMore['recommendation']);
        self::assertSame([], $originateMore['target_dimensions']);

        $stop = $this->balancer()->balance(['task_groups' => $this->diverseGroups(5)]);
        self::assertSame('stop_or_consolidate', $stop['recommendation']);
        self::assertSame([], $stop['target_dimensions']);

        $targeted = $this->balancer()->balance(['task_groups' => [$this->group('implementation', 25)]]);
        self::assertSame('originate_targeted', $targeted['recommendation']);
        self::assertNotEmpty($targeted['target_dimensions']);
    }

    public function test_target_dimensions_contain_first_missing_canonical_dimensions(): void
    {
        // Only 'implementation' is present — the first 3 canonical dimensions that come
        // after it in CANONICAL_DIMENSIONS order should be the targeted ones.
        $r = $this->balancer()->balance([
            'task_groups' => [$this->group('implementation', 25)],
        ]);

        self::assertSame(['queue_health', 'learning', 'verification'], $r['target_dimensions']);
    }

    public function test_thin_queue_originates_more(): void
    {
        $r = $this->balancer()->balance([
            'task_groups' => [$this->group('implementation', 5)],
        ]);

        self::assertSame('originate_more', $r['recommendation']);
        self::assertSame('queue_too_thin', $r['reason']);
    }

    public function test_worker_floor_breach_overrides_deep_diverse_stop_into_originate_more(): void
    {
        $r = $this->balancer()->balance([
            'task_groups' => $this->diverseGroups(5),
            'worker_count' => 30,
            'minimum_ready_per_worker' => 1,
        ]);

        self::assertSame('originate_more', $r['recommendation']);
        self::assertSame('worker_floor', $r['reason']);
        self::assertTrue($r['diagnostics']['below_worker_floor']);
    }

    public function test_external_worker_floor_signals_are_surfaced_in_diagnostics_without_bypassing_diversity(): void
    {
        // Deep + diverse queue with an external replenish_urgently signal: worker-floor
        // diagnostics fire and origination happens, but dimension diversity is unaffected
        // (this is NOT a dimension-sparse scenario, so it must not become originate_targeted).
        $r = $this->balancer()->balance([
            'task_groups' => $this->diverseGroups(5),
            'queue_facts' => ['replenish_recommendation' => 'replenish_urgently'],
        ]);

        self::assertTrue($r['diagnostics']['external_worker_floor_signal']);
        self::assertSame('originate_more', $r['recommendation']);
        self::assertSame('worker_floor', $r['reason']);
    }

    public function test_replenish_soon_signal_on_dimension_sparse_queue_still_originates_targeted_not_more(): void
    {
        // Even with a worker-floor signal, a dimension-sparse queue must be targeted, not
        // blindly volumed — dimension diversity is never bypassed by worker-floor urgency.
        $r = $this->balancer()->balance([
            'task_groups' => [$this->group('implementation', 25)],
            'queue_facts' => ['replenish_recommendation' => 'replenish_soon'],
        ]);

        self::assertTrue($r['diagnostics']['external_worker_floor_signal']);
        self::assertSame('originate_targeted', $r['recommendation']);
        self::assertNotEmpty($r['target_dimensions']);
    }
}
