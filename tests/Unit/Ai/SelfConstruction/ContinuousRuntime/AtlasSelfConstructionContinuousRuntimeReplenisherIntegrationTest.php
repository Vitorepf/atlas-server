<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ContinuousRuntime;

use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasSelfConstructionContinuousRuntimeReplenisherIntegration;
use Tests\TestCase;

final class AtlasSelfConstructionContinuousRuntimeReplenisherIntegrationTest extends TestCase
{
    public function test_top_up_request_with_bounded_target_and_deterministic_hash(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeReplenisherIntegration)->integrate([
            'runtime_cycle' => ['cycle_id' => 'cyc-1'],
            'queue_health' => [
                'malformed_count' => 0,
                'claimable_depth' => 1,
                'servable_depth' => 1,
                'depth_floor' => 3,
                'target_depth' => 6,
            ],
        ]);

        $this->assertSame('top_up', $verdict['request']['action']);
        $this->assertSame(5, $verdict['request']['target_new_packet_count']);
        $this->assertSame(['claimable_depth_below_floor:1<3'], $verdict['request']['reasons']);
        $this->assertSame('39a4a0c9d7caf15b51b02cd0feb624b062bb3f1e1dcd11beb917819991ecc3b0', $verdict['payload_hash']);
    }

    public function test_wait_when_claimable_depth_at_or_above_floor(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeReplenisherIntegration)->integrate([
            'runtime_cycle' => ['cycle_id' => 'cyc-2'],
            'queue_health' => ['malformed_count' => 0, 'claimable_depth' => 5, 'depth_floor' => 3],
        ]);

        $this->assertSame('wait', $verdict['request']['action']);
        $this->assertSame(0, $verdict['request']['target_new_packet_count']);
        $this->assertSame('bd6dbb1164a63b132cd3690993b4e8a06481cd96be00ad0ef28f221c847ced68', $verdict['payload_hash']);
    }

    public function test_repair_first_when_malformed_packets_present(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeReplenisherIntegration)->integrate([
            'runtime_cycle' => ['cycle_id' => 'cyc-3'],
            'queue_health' => ['malformed_count' => 2, 'claimable_depth' => 5],
        ]);

        $this->assertSame('repair_first', $verdict['request']['action']);
        $this->assertSame(2, $verdict['request']['malformed_count']);
        $this->assertSame(0, $verdict['request']['target_new_packet_count']);
        $this->assertSame('2ab1895fc324c3cd9a68222b0f778c7fb68963095cc85601fe93a96706864809', $verdict['payload_hash']);
    }

    public function test_target_new_packet_count_is_bounded_to_max(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeReplenisherIntegration)->integrate([
            'runtime_cycle' => ['cycle_id' => 'cyc-4'],
            'queue_health' => ['malformed_count' => 0, 'claimable_depth' => 0, 'depth_floor' => 3, 'target_depth' => 999],
        ]);

        $this->assertSame('top_up', $verdict['request']['action']);
        $this->assertSame(8, $verdict['request']['target_new_packet_count']);
    }

    public function test_envelope_carries_schema_version(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeReplenisherIntegration)->integrate([]);
        $this->assertSame('atlas.continuous_runtime.replenisher_integration.v1', $verdict['schema_version']);
    }

    public function test_receipt_fields_schema_request_and_payload_hash_are_all_present(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeReplenisherIntegration)->integrate([
            'runtime_cycle' => ['cycle_id' => 'cyc-receipt'],
            'queue_health' => ['malformed_count' => 0, 'claimable_depth' => 0, 'depth_floor' => 3],
        ]);

        $this->assertArrayHasKey('schema_version', $verdict);
        $this->assertArrayHasKey('request', $verdict);
        $this->assertArrayHasKey('payload_hash', $verdict);
        $this->assertSame(AtlasSelfConstructionContinuousRuntimeReplenisherIntegration::SCHEMA, $verdict['schema_version']);
        $this->assertIsArray($verdict['request']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $verdict['payload_hash']);
        $this->assertArrayHasKey('action', $verdict['request']);
        $this->assertArrayHasKey('target_new_packet_count', $verdict['request']);
    }

    // ── depth_policy (pressure-aware) ─────────────────────────────────────────

    public function test_depth_policy_absent_when_no_pressure_data_supplied(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeReplenisherIntegration)->integrate([
            'runtime_cycle' => ['cycle_id' => 'cyc-nopress'],
            'queue_health' => ['malformed_count' => 0, 'claimable_depth' => 1, 'depth_floor' => 3, 'target_depth' => 6],
        ]);

        $this->assertSame('top_up', $verdict['request']['action']);
        $this->assertArrayNotHasKey('depth_policy', $verdict['request']);
    }

    public function test_depth_policy_emitted_when_muscle_count_supplied(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeReplenisherIntegration)->integrate([
            'runtime_cycle' => ['cycle_id' => 'cyc-p', 'muscle_count' => 3, 'drain_rate_hint' => 1.5],
            'queue_health'  => ['malformed_count' => 0, 'claimable_depth' => 1, 'servable_depth' => 1, 'depth_floor' => 3, 'target_depth' => 6],
        ]);

        $this->assertSame('top_up', $verdict['request']['action']);
        $dp = $verdict['request']['depth_policy'];
        $this->assertSame(3, $dp['muscle_count']);
        $this->assertSame(1.5, $dp['drain_rate_hint']);
        $this->assertSame(5, $dp['safe_target_depth']);
        $this->assertStringContainsString('claimable_1', $dp['deficit_reason']);
        $this->assertSame('deficit_within_cap', $dp['why_target_is_bounded']);
        $this->assertSame('98d1df4af4bbb695f908ff6a2087b089a706669aa89e65dd0d871f28bd0b20cd', $verdict['payload_hash']);
    }

    public function test_why_target_is_bounded_shows_cap_when_deficit_exceeds_max(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeReplenisherIntegration)->integrate([
            'runtime_cycle' => ['cycle_id' => 'cyc-cap', 'muscle_count' => 5],
            'queue_health'  => ['malformed_count' => 0, 'claimable_depth' => 0, 'depth_floor' => 3, 'target_depth' => 999],
        ]);

        $dp = $verdict['request']['depth_policy'];
        $this->assertSame(8, $dp['safe_target_depth']); // capped at MAX
        $this->assertSame(8, $verdict['request']['target_new_packet_count']);
        $this->assertStringContainsString('max_packet_cap_applied', $dp['why_target_is_bounded']);
        $this->assertStringContainsString('999', $dp['why_target_is_bounded']);
    }

    public function test_depth_policy_absent_for_repair_first_and_target_is_zero(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeReplenisherIntegration)->integrate([
            'runtime_cycle' => ['cycle_id' => 'cyc-3', 'muscle_count' => 4],
            'queue_health'  => ['malformed_count' => 2, 'claimable_depth' => 5],
        ]);

        $this->assertSame('repair_first', $verdict['request']['action']);
        $this->assertSame(0, $verdict['request']['target_new_packet_count']);
        $this->assertArrayNotHasKey('depth_policy', $verdict['request']);
    }

    public function test_depth_policy_absent_for_wait_and_target_is_zero(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeReplenisherIntegration)->integrate([
            'runtime_cycle' => ['cycle_id' => 'cyc-2', 'muscle_count' => 4],
            'queue_health'  => ['malformed_count' => 0, 'claimable_depth' => 5, 'depth_floor' => 3],
        ]);

        $this->assertSame('wait', $verdict['request']['action']);
        $this->assertSame(0, $verdict['request']['target_new_packet_count']);
        $this->assertArrayNotHasKey('depth_policy', $verdict['request']);
    }

    // ── hold: duplicate-target exclusion ──────────────────────────────────────

    public function test_hold_when_live_targets_fill_all_needed_slots(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeReplenisherIntegration)->integrate([
            'runtime_cycle'        => ['cycle_id' => 'cyc-hold'],
            'queue_health'         => ['malformed_count' => 0, 'claimable_depth' => 1, 'depth_floor' => 3, 'target_depth' => 6],
            'live_target_exclusions' => ['cap_a', 'cap_b', 'cap_c', 'cap_d', 'cap_e'], // 5 live ≥ bounded(5)
        ]);

        $this->assertSame(AtlasSelfConstructionContinuousRuntimeReplenisherIntegration::ACTION_HOLD, $verdict['request']['action']);
        $this->assertSame(0, $verdict['request']['target_new_packet_count']);
        $this->assertSame(5, $verdict['request']['live_target_exclusion_count']);
    }

    public function test_hold_carries_explicit_reason_with_live_count_and_slots_needed(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeReplenisherIntegration)->integrate([
            'runtime_cycle'          => ['cycle_id' => 'cyc-hold2'],
            'queue_health'           => ['malformed_count' => 0, 'claimable_depth' => 0, 'depth_floor' => 3, 'target_depth' => 4],
            'live_target_exclusions' => ['t1', 't2', 't3', 't4'],
        ]);

        $this->assertSame('hold', $verdict['request']['action']);
        $this->assertStringContainsString('all_candidates_excluded_as_live_targets', $verdict['request']['reasons'][0]);
        $this->assertStringContainsString('4', $verdict['request']['reasons'][0]);
    }

    public function test_partial_live_targets_below_bounded_still_top_up(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeReplenisherIntegration)->integrate([
            'runtime_cycle'          => ['cycle_id' => 'cyc-partial'],
            'queue_health'           => ['malformed_count' => 0, 'claimable_depth' => 1, 'depth_floor' => 3, 'target_depth' => 6],
            'live_target_exclusions' => ['cap_a', 'cap_b'], // 2 live < bounded(5) → top_up
        ]);

        $this->assertSame('top_up', $verdict['request']['action']);
        $this->assertSame(2, $verdict['request']['live_excluded_count']);
    }

    // ── maturity_gaps + recent_outcome_learning signals ───────────────────────

    public function test_top_up_includes_maturity_gap_count_when_gaps_present(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeReplenisherIntegration)->integrate([
            'runtime_cycle'  => ['cycle_id' => 'cyc-gaps'],
            'queue_health'   => ['malformed_count' => 0, 'claimable_depth' => 1, 'depth_floor' => 3, 'target_depth' => 6],
            'maturity_gaps'  => ['organ_a:alpha', 'organ_b:experimental'],
        ]);

        $this->assertSame('top_up', $verdict['request']['action']);
        $this->assertSame(2, $verdict['request']['maturity_gap_count']);
        $this->assertArrayNotHasKey('outcome_signal_count', $verdict['request']);
    }

    public function test_top_up_includes_outcome_signal_count_when_signals_present(): void
    {
        $verdict = (new AtlasSelfConstructionContinuousRuntimeReplenisherIntegration)->integrate([
            'runtime_cycle'           => ['cycle_id' => 'cyc-outcomes'],
            'queue_health'            => ['malformed_count' => 0, 'claimable_depth' => 1, 'depth_floor' => 3, 'target_depth' => 6],
            'recent_outcome_learning' => ['outcome:success:cap_x', 'outcome:failure:cap_y', 'outcome:success:cap_z'],
        ]);

        $this->assertSame('top_up', $verdict['request']['action']);
        $this->assertSame(3, $verdict['request']['outcome_signal_count']);
        $this->assertArrayNotHasKey('maturity_gap_count', $verdict['request']);
    }
}
