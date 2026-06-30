<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Projection;

use App\Services\Ai\SelfConstruction\Maestro\Projection\AtlasMaestroMuscleThroughputContinuityModel;
use Tests\TestCase;

final class AtlasMaestroMuscleThroughputContinuityModelTest extends TestCase
{
    private function model(): AtlasMaestroMuscleThroughputContinuityModel
    {
        return new AtlasMaestroMuscleThroughputContinuityModel();
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->model()->model([]);

        $this->assertSame(AtlasMaestroMuscleThroughputContinuityModel::SCHEMA, $result['schema']);
    }

    public function test_output_has_all_required_keys(): void
    {
        $result = $this->model()->model([]);

        foreach (['schema', 'hours_to_dry', 'burn_rate', 'capacity_status', 'replenish_now', 'reasons'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
    }

    // ── hours_to_dry ──────────────────────────────────────────────────────────

    public function test_hours_to_dry_equals_servable_divided_by_burn_rate(): void
    {
        $result = $this->model()->model([
            'servable_now'       => 20,
            'recent_completions' => 4,
            'burn_rate_floor'    => 3,
        ]);

        $this->assertSame(5.0, $result['hours_to_dry']);
    }

    public function test_hours_to_dry_is_deterministic_given_same_input(): void
    {
        $input = ['servable_now' => 10, 'recent_completions' => 3, 'burn_rate_floor' => 2];

        $this->assertSame(
            $this->model()->model($input)['hours_to_dry'],
            $this->model()->model($input)['hours_to_dry'],
        );
    }

    public function test_hours_to_dry_is_zero_when_servable_now_is_zero(): void
    {
        $result = $this->model()->model([
            'servable_now'       => 0,
            'recent_completions' => 5,
        ]);

        $this->assertSame(0.0, $result['hours_to_dry']);
    }

    // ── burn_rate ─────────────────────────────────────────────────────────────

    public function test_burn_rate_uses_recent_completions_when_positive(): void
    {
        $result = $this->model()->model([
            'active_leases'      => 3,
            'recent_completions' => 7,
        ]);

        $this->assertSame(7.0, $result['burn_rate']);
    }

    public function test_burn_rate_falls_back_to_active_leases_when_completions_zero(): void
    {
        $result = $this->model()->model([
            'active_leases'      => 4,
            'recent_completions' => 0,
        ]);

        $this->assertSame(4.0, $result['burn_rate']);
    }

    public function test_burn_rate_falls_back_to_one_when_no_leases_and_no_completions(): void
    {
        $result = $this->model()->model([
            'active_leases'      => 0,
            'recent_completions' => 0,
        ]);

        $this->assertSame(1.0, $result['burn_rate']);
    }

    // ── capacity_status ───────────────────────────────────────────────────────

    public function test_empty_status_when_servable_now_is_zero(): void
    {
        $result = $this->model()->model([
            'servable_now'    => 0,
            'burn_rate_floor' => 5,
        ]);

        $this->assertSame(AtlasMaestroMuscleThroughputContinuityModel::STATUS_EMPTY, $result['capacity_status']);
    }

    public function test_critically_low_when_servable_now_below_floor_but_not_zero(): void
    {
        $result = $this->model()->model([
            'servable_now'    => 3,
            'burn_rate_floor' => 5,
            'blocked_count'   => 0,
            'claimable_depth' => 3,
        ]);

        $this->assertSame(AtlasMaestroMuscleThroughputContinuityModel::STATUS_CRITICALLY_LOW, $result['capacity_status']);
    }

    public function test_dependency_blocked_when_blocked_fraction_is_fifty_percent_or_more(): void
    {
        // 10 claimable, 5 blocked (50% blocked), servable_now = 5, floor = 3.
        $result = $this->model()->model([
            'servable_now'    => 5,
            'claimable_depth' => 10,
            'blocked_count'   => 5,
            'burn_rate_floor' => 3,
        ]);

        $this->assertSame(AtlasMaestroMuscleThroughputContinuityModel::STATUS_DEPENDENCY_BLOCKED, $result['capacity_status']);
    }

    public function test_healthy_when_servable_above_floor_and_low_blocked_fraction(): void
    {
        $result = $this->model()->model([
            'servable_now'    => 20,
            'claimable_depth' => 25,
            'blocked_count'   => 2,   // 8% blocked
            'burn_rate_floor' => 5,
        ]);

        $this->assertSame(AtlasMaestroMuscleThroughputContinuityModel::STATUS_HEALTHY, $result['capacity_status']);
    }

    public function test_critically_low_takes_priority_over_dependency_blocked(): void
    {
        // servable_now = 2 (< floor 5) → critically_low wins over blocked check.
        $result = $this->model()->model([
            'servable_now'    => 2,
            'claimable_depth' => 4,
            'blocked_count'   => 3,   // 75% blocked — would be dependency_blocked if not critically_low
            'burn_rate_floor' => 5,
        ]);

        $this->assertSame(AtlasMaestroMuscleThroughputContinuityModel::STATUS_CRITICALLY_LOW, $result['capacity_status']);
    }

    // ── replenish_now ─────────────────────────────────────────────────────────

    public function test_replenish_now_true_when_servable_below_floor(): void
    {
        $result = $this->model()->model([
            'servable_now'    => 3,
            'burn_rate_floor' => 5,
        ]);

        $this->assertTrue($result['replenish_now']);
    }

    public function test_replenish_now_false_when_servable_at_or_above_floor(): void
    {
        $result = $this->model()->model([
            'servable_now'    => 5,
            'burn_rate_floor' => 5,
        ]);

        $this->assertFalse($result['replenish_now']);
    }

    public function test_replenish_now_true_when_servable_is_zero(): void
    {
        $result = $this->model()->model(['servable_now' => 0, 'burn_rate_floor' => 5]);

        $this->assertTrue($result['replenish_now']);
    }

    public function test_default_burn_rate_floor_is_five(): void
    {
        // servable_now = 4, no floor override → default floor = 5 → replenish_now = true.
        $result = $this->model()->model(['servable_now' => 4]);

        $this->assertTrue($result['replenish_now']);
    }

    // ── reasons ───────────────────────────────────────────────────────────────

    public function test_reasons_list_is_non_empty(): void
    {
        $result = $this->model()->model(['servable_now' => 10, 'recent_completions' => 2]);

        $this->assertNotEmpty($result['reasons']);
    }

    public function test_replenish_reason_present_when_servable_below_floor(): void
    {
        $result = $this->model()->model(['servable_now' => 2, 'burn_rate_floor' => 5]);

        $reasonStr = implode(' ', $result['reasons']);
        $this->assertStringContainsString('replenish_now', $reasonStr);
    }

    public function test_blocked_count_mentioned_in_reasons_when_present(): void
    {
        $result = $this->model()->model([
            'servable_now'    => 10,
            'claimable_depth' => 20,
            'blocked_count'   => 8,
            'burn_rate_floor' => 5,
        ]);

        $reasonStr = implode(' ', $result['reasons']);
        $this->assertStringContainsString('blocked_count', $reasonStr);
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = [
            'active_leases'      => 3,
            'claimable_depth'    => 15,
            'servable_now'       => 8,
            'blocked_count'      => 7,
            'recent_completions' => 5,
            'burn_rate_floor'    => 5,
        ];

        $this->assertSame($this->model()->model($input), $this->model()->model($input));
    }
}
