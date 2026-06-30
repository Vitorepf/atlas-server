<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainBacklogCostModel;
use Tests\TestCase;

final class AtlasExternalBrainBacklogCostModelTest extends TestCase
{
    private function model(): AtlasExternalBrainBacklogCostModel
    {
        return new AtlasExternalBrainBacklogCostModel();
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->model()->model([]);

        $this->assertSame(AtlasExternalBrainBacklogCostModel::SCHEMA, $result['schema']);
    }

    public function test_output_has_all_required_keys(): void
    {
        $result = $this->model()->model([]);

        foreach (['schema', 'carrying_cost', 'saturation_risk', 'preferred_action', 'reasons', 'cost_breakdown'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function test_cost_breakdown_has_all_components(): void
    {
        $result = $this->model()->model(['backlog_size' => 10]);

        $bd = $result['cost_breakdown'];
        foreach (['worker_hours_cost', 'give_back_burden', 'review_burden', 'integration_load', 'opportunity_cost'] as $k) {
            $this->assertArrayHasKey($k, $bd);
        }
    }

    public function test_empty_input_yields_zero_carrying_cost(): void
    {
        $result = $this->model()->model([]);

        $this->assertSame(0.0, $result['carrying_cost']);
    }

    // ── carrying_cost ─────────────────────────────────────────────────────────

    public function test_larger_backlog_increases_carrying_cost(): void
    {
        $small = $this->model()->model(['backlog_size' => 5,  'worker_throughput' => 1.0]);
        $large = $this->model()->model(['backlog_size' => 50, 'worker_throughput' => 1.0]);

        $this->assertGreaterThan($small['carrying_cost'], $large['carrying_cost']);
    }

    public function test_high_give_back_rate_increases_carrying_cost(): void
    {
        $low  = $this->model()->model(['backlog_size' => 20, 'give_back_rate' => 0.0]);
        $high = $this->model()->model(['backlog_size' => 20, 'give_back_rate' => 0.8]);

        $this->assertGreaterThan($low['carrying_cost'], $high['carrying_cost']);
    }

    public function test_blocked_count_adds_opportunity_cost(): void
    {
        $noBlocked   = $this->model()->model(['backlog_size' => 20, 'blocked_count' => 0]);
        $manyBlocked = $this->model()->model(['backlog_size' => 20, 'blocked_count' => 5]);

        $this->assertGreaterThan($noBlocked['carrying_cost'], $manyBlocked['carrying_cost']);
        $this->assertSame(10.0, $manyBlocked['cost_breakdown']['opportunity_cost']);
    }

    public function test_higher_throughput_lowers_worker_hours_cost(): void
    {
        $slow = $this->model()->model(['backlog_size' => 10, 'worker_throughput' => 0.5]);
        $fast = $this->model()->model(['backlog_size' => 10, 'worker_throughput' => 5.0]);

        $this->assertGreaterThan($fast['cost_breakdown']['worker_hours_cost'], $slow['cost_breakdown']['worker_hours_cost']);
    }

    // ── saturation_risk ───────────────────────────────────────────────────────

    public function test_high_give_back_rate_yields_high_saturation_risk(): void
    {
        $result = $this->model()->model(['give_back_rate' => 0.6]);

        $this->assertSame(AtlasExternalBrainBacklogCostModel::SATURATION_HIGH, $result['saturation_risk']);
    }

    public function test_low_servable_ratio_yields_high_saturation_risk(): void
    {
        // servable_depth = 1 out of 20 = 5% → HIGH
        $result = $this->model()->model(['backlog_size' => 20, 'servable_depth' => 1, 'give_back_rate' => 0.0]);

        $this->assertSame(AtlasExternalBrainBacklogCostModel::SATURATION_HIGH, $result['saturation_risk']);
    }

    public function test_medium_give_back_yields_medium_saturation_risk(): void
    {
        $result = $this->model()->model(['give_back_rate' => 0.35, 'backlog_size' => 20, 'servable_depth' => 15]);

        $this->assertSame(AtlasExternalBrainBacklogCostModel::SATURATION_MEDIUM, $result['saturation_risk']);
    }

    public function test_healthy_queue_yields_low_saturation_risk(): void
    {
        $result = $this->model()->model([
            'backlog_size'   => 20,
            'servable_depth' => 18,
            'give_back_rate' => 0.05,
        ]);

        $this->assertSame(AtlasExternalBrainBacklogCostModel::SATURATION_LOW, $result['saturation_risk']);
    }

    // ── preferred_action: seed ────────────────────────────────────────────────

    public function test_healthy_queue_with_high_confidence_yields_seed(): void
    {
        $result = $this->model()->model([
            'backlog_size'      => 5,
            'servable_depth'    => 5,
            'give_back_rate'    => 0.05,
            'blocked_count'     => 0,
            'impact_confidence' => 0.9,
        ]);

        $this->assertSame(AtlasExternalBrainBacklogCostModel::ACTION_SEED, $result['preferred_action']);
    }

    // ── preferred_action: consolidate ────────────────────────────────────────

    public function test_healthy_depth_plus_low_impact_confidence_yields_consolidate(): void
    {
        $result = $this->model()->model([
            'backlog_size'      => 30,
            'servable_depth'    => 15,
            'give_back_rate'    => 0.10,
            'blocked_count'     => 0,
            'impact_confidence' => 0.30,  // below 0.5
        ]);

        $this->assertSame(AtlasExternalBrainBacklogCostModel::ACTION_CONSOLIDATE, $result['preferred_action']);
    }

    public function test_healthy_depth_plus_elevated_give_back_yields_consolidate(): void
    {
        $result = $this->model()->model([
            'backlog_size'      => 40,
            'servable_depth'    => 20,
            'give_back_rate'    => 0.35,  // >= 0.30
            'blocked_count'     => 1,     // not high enough for unblock
            'impact_confidence' => 0.8,
        ]);

        $this->assertSame(AtlasExternalBrainBacklogCostModel::ACTION_CONSOLIDATE, $result['preferred_action']);
    }

    public function test_consolidate_preferred_over_seed_when_impact_confidence_falls(): void
    {
        $seed = $this->model()->model([
            'backlog_size' => 30, 'servable_depth' => 15, 'give_back_rate' => 0.05,
            'blocked_count' => 0, 'impact_confidence' => 0.9,
        ]);
        $consolidate = $this->model()->model([
            'backlog_size' => 30, 'servable_depth' => 15, 'give_back_rate' => 0.05,
            'blocked_count' => 0, 'impact_confidence' => 0.30,
        ]);

        $this->assertSame(AtlasExternalBrainBacklogCostModel::ACTION_SEED, $seed['preferred_action']);
        $this->assertSame(AtlasExternalBrainBacklogCostModel::ACTION_CONSOLIDATE, $consolidate['preferred_action']);
    }

    // ── preferred_action: pause ───────────────────────────────────────────────

    public function test_high_saturation_with_no_blocking_pressure_yields_pause(): void
    {
        $result = $this->model()->model([
            'backlog_size'      => 10,
            'servable_depth'    => 1,   // thin servable → HIGH saturation
            'give_back_rate'    => 0.0,
            'blocked_count'     => 0,
            'impact_confidence' => 0.9,
        ]);

        $this->assertSame(AtlasExternalBrainBacklogCostModel::ACTION_PAUSE, $result['preferred_action']);
    }

    // ── preferred_action: unblock ─────────────────────────────────────────────

    public function test_high_blocked_fraction_yields_unblock(): void
    {
        // blocked_count = 10 out of 20 = 50% → unblock
        $result = $this->model()->model([
            'backlog_size'   => 20,
            'servable_depth' => 15,
            'blocked_count'  => 10,
            'give_back_rate' => 0.05,
        ]);

        $this->assertSame(AtlasExternalBrainBacklogCostModel::ACTION_UNBLOCK, $result['preferred_action']);
    }

    public function test_large_absolute_blocked_count_yields_unblock(): void
    {
        // 5 blocked tasks regardless of fraction
        $result = $this->model()->model([
            'backlog_size'   => 100,
            'servable_depth' => 90,
            'blocked_count'  => 5,
            'give_back_rate' => 0.05,
        ]);

        $this->assertSame(AtlasExternalBrainBacklogCostModel::ACTION_UNBLOCK, $result['preferred_action']);
    }

    public function test_unblock_prioritized_over_consolidate(): void
    {
        // Both unblock and consolidate conditions met → unblock wins.
        $result = $this->model()->model([
            'backlog_size'      => 20,
            'servable_depth'    => 15,
            'blocked_count'     => 8,   // >= 5 → unblock trigger
            'give_back_rate'    => 0.35, // would trigger consolidate
            'impact_confidence' => 0.3,  // would trigger consolidate
        ]);

        $this->assertSame(AtlasExternalBrainBacklogCostModel::ACTION_UNBLOCK, $result['preferred_action']);
    }

    // ── reasons ───────────────────────────────────────────────────────────────

    public function test_reasons_list_is_non_empty(): void
    {
        $result = $this->model()->model(['backlog_size' => 10]);

        $this->assertNotEmpty($result['reasons']);
        $this->assertIsArray($result['reasons']);
    }

    public function test_consolidate_reason_mentions_impact_confidence(): void
    {
        $result = $this->model()->model([
            'backlog_size'      => 30,
            'servable_depth'    => 15,
            'impact_confidence' => 0.2,
        ]);

        $this->assertSame(AtlasExternalBrainBacklogCostModel::ACTION_CONSOLIDATE, $result['preferred_action']);
        $reasonStr = implode(' ', $result['reasons']);
        $this->assertStringContainsString('impact_confidence', $reasonStr);
    }

    public function test_unblock_reason_mentions_blocked_pressure(): void
    {
        $result = $this->model()->model(['backlog_size' => 20, 'blocked_count' => 10]);

        $reasonStr = implode(' ', $result['reasons']);
        $this->assertStringContainsString('blocked_pressure', $reasonStr);
    }

    // ── preferred_action: drain ───────────────────────────────────────────────

    public function test_high_claimable_depth_with_low_value_density_yields_drain(): void
    {
        // claimable_depth=25 >= 20 AND expected_value_density=0.30 < 0.50 → drain
        $result = $this->model()->model([
            'backlog_size'           => 40,
            'claimable_depth'        => 25,
            'expected_value_density' => 0.30,
            'give_back_rate'         => 0.05,
            'blocked_count'          => 0,
            'impact_confidence'      => 0.80,
        ]);

        $this->assertSame(AtlasExternalBrainBacklogCostModel::ACTION_DRAIN, $result['preferred_action']);
    }

    public function test_drain_reason_mentions_claimable_depth_and_value_density(): void
    {
        $result = $this->model()->model([
            'backlog_size'           => 40,
            'claimable_depth'        => 25,
            'expected_value_density' => 0.20,
        ]);

        $this->assertSame(AtlasExternalBrainBacklogCostModel::ACTION_DRAIN, $result['preferred_action']);
        $reasonStr = implode(' ', $result['reasons']);
        $this->assertStringContainsString('claimable_depth_high', $reasonStr);
        $this->assertStringContainsString('expected_value_density_low', $reasonStr);
    }

    public function test_high_claimable_depth_with_healthy_value_density_does_not_yield_drain(): void
    {
        // claimable_depth=25 (high) but expected_value_density=0.80 (healthy) → no drain
        $result = $this->model()->model([
            'backlog_size'           => 40,
            'claimable_depth'        => 25,
            'expected_value_density' => 0.80,
            'give_back_rate'         => 0.05,
            'blocked_count'          => 0,
            'impact_confidence'      => 0.80,
        ]);

        $this->assertNotSame(AtlasExternalBrainBacklogCostModel::ACTION_DRAIN, $result['preferred_action']);
        $this->assertSame(AtlasExternalBrainBacklogCostModel::ACTION_SEED, $result['preferred_action']);
    }

    public function test_low_claimable_depth_with_low_value_density_does_not_yield_drain(): void
    {
        // claimable_depth=5 (low) despite low value density → no drain → seed
        $result = $this->model()->model([
            'backlog_size'           => 10,
            'claimable_depth'        => 5,
            'expected_value_density' => 0.10,
            'give_back_rate'         => 0.05,
            'blocked_count'          => 0,
            'impact_confidence'      => 0.80,
        ]);

        $this->assertNotSame(AtlasExternalBrainBacklogCostModel::ACTION_DRAIN, $result['preferred_action']);
    }

    public function test_drain_preferred_over_seed_when_depth_high_and_density_falling(): void
    {
        $seed = $this->model()->model([
            'backlog_size' => 40, 'claimable_depth' => 25,
            'expected_value_density' => 0.80,  // healthy → seed
        ]);
        $drain = $this->model()->model([
            'backlog_size' => 40, 'claimable_depth' => 25,
            'expected_value_density' => 0.20,  // falling → drain
        ]);

        $this->assertSame(AtlasExternalBrainBacklogCostModel::ACTION_SEED,  $seed['preferred_action']);
        $this->assertSame(AtlasExternalBrainBacklogCostModel::ACTION_DRAIN, $drain['preferred_action']);
    }

    public function test_unblock_prioritized_over_drain(): void
    {
        // Both drain and unblock conditions met → unblock wins.
        $result = $this->model()->model([
            'backlog_size'           => 40,
            'claimable_depth'        => 25,
            'expected_value_density' => 0.20,
            'blocked_count'          => 5,  // triggers unblock
        ]);

        $this->assertSame(AtlasExternalBrainBacklogCostModel::ACTION_UNBLOCK, $result['preferred_action']);
    }

    // ── recommended_queue_action ──────────────────────────────────────────────

    public function test_recommended_queue_action_field_present(): void
    {
        $result = $this->model()->model([]);

        $this->assertArrayHasKey('recommended_queue_action', $result);
    }

    public function test_recommended_queue_action_has_action_reasons_and_economics(): void
    {
        $result = $this->model()->model([]);

        $rqa = $result['recommended_queue_action'];
        $this->assertArrayHasKey('action',    $rqa);
        $this->assertArrayHasKey('reasons',   $rqa);
        $this->assertArrayHasKey('economics', $rqa);
    }

    public function test_recommended_queue_action_economics_contains_all_six_fields(): void
    {
        $result = $this->model()->model([]);

        $eco = $result['recommended_queue_action']['economics'];
        foreach (['worker_capacity', 'claimable_depth', 'blocked_count', 'give_back_rate', 'expected_value_density', 'age_cost'] as $key) {
            $this->assertArrayHasKey($key, $eco, "Economics missing field: {$key}");
        }
    }

    public function test_recommended_queue_action_action_matches_preferred_action(): void
    {
        $result = $this->model()->model([
            'backlog_size'      => 30,
            'claimable_depth'   => 25,
            'expected_value_density' => 0.20,
        ]);

        $this->assertSame($result['preferred_action'], $result['recommended_queue_action']['action']);
    }

    public function test_recommended_queue_action_economics_reflects_provided_inputs(): void
    {
        $result = $this->model()->model([
            'worker_capacity'        => 4,
            'claimable_depth'        => 12,
            'blocked_count'          => 2,
            'give_back_rate'         => 0.15,
            'expected_value_density' => 0.70,
            'age_cost'               => 3.5,
        ]);

        $eco = $result['recommended_queue_action']['economics'];
        $this->assertSame(4,    $eco['worker_capacity']);
        $this->assertSame(12,   $eco['claimable_depth']);
        $this->assertSame(2,    $eco['blocked_count']);
        $this->assertEqualsWithDelta(0.15, $eco['give_back_rate'],         0.0001);
        $this->assertEqualsWithDelta(0.70, $eco['expected_value_density'], 0.0001);
        $this->assertEqualsWithDelta(3.5,  $eco['age_cost'],               0.0001);
    }

    // ── age_cost ──────────────────────────────────────────────────────────────

    public function test_age_cost_increases_carrying_cost(): void
    {
        $noAge   = $this->model()->model(['backlog_size' => 10, 'age_cost' => 0.0]);
        $withAge = $this->model()->model(['backlog_size' => 10, 'age_cost' => 5.0]);

        $this->assertGreaterThan($noAge['carrying_cost'], $withAge['carrying_cost']);
    }

    public function test_age_cost_contribution_in_cost_breakdown(): void
    {
        $result = $this->model()->model(['backlog_size' => 10, 'age_cost' => 2.0]);

        $this->assertArrayHasKey('age_cost_contribution', $result['cost_breakdown']);
        $this->assertEqualsWithDelta(20.0, $result['cost_breakdown']['age_cost_contribution'], 0.001);
    }

    public function test_zero_age_cost_contributes_nothing(): void
    {
        $result = $this->model()->model(['backlog_size' => 10]);

        $this->assertSame(0.0, $result['cost_breakdown']['age_cost_contribution']);
    }

    // ── claimable_depth input (canonical name) ────────────────────────────────

    public function test_claimable_depth_input_used_for_saturation_ratio(): void
    {
        // claimable_depth=1 out of backlog=20 → HIGH saturation (same behaviour as servable_depth)
        $result = $this->model()->model(['backlog_size' => 20, 'claimable_depth' => 1]);

        $this->assertSame(AtlasExternalBrainBacklogCostModel::SATURATION_HIGH, $result['saturation_risk']);
    }

    public function test_claimable_depth_takes_priority_over_servable_depth(): void
    {
        // claimable_depth=25 should be used; servable_depth=5 is the legacy alias
        $result = $this->model()->model([
            'backlog_size'           => 40,
            'claimable_depth'        => 25,
            'servable_depth'         => 5,    // ignored when claimable_depth present
            'expected_value_density' => 0.20,
        ]);

        // claimable_depth=25 >= HIGH_CLAIMABLE_DEPTH_THRESHOLD and value density low → drain
        $this->assertSame(AtlasExternalBrainBacklogCostModel::ACTION_DRAIN, $result['preferred_action']);
        $this->assertSame(25, $result['recommended_queue_action']['economics']['claimable_depth']);
    }
}
