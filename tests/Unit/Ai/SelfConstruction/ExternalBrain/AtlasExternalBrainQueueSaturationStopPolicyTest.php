<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainQueueSaturationStopPolicy;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainQueueSaturationStopPolicyTest extends TestCase
{
    private AtlasExternalBrainQueueSaturationStopPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AtlasExternalBrainQueueSaturationStopPolicy;
    }

    private function healthy(array $overrides = []): array
    {
        return array_merge([
            'claimable_depth'       => 25,  // saturated (≥ 20)
            'servable_now'          => 10,  // above floor (≥ 5)
            'recoverable_backlog'   => 0,
            'muscle_burn_rate_floor' => 5,
            'saturation_threshold'  => 20,
        ], $overrides);
    }

    // ── Schema / keys ────────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->policy->evaluate($this->healthy());

        foreach (['schema', 'decision', 'reason', 'next_action', 'next_cycle_hint', 'claimable_depth', 'servable_now',
                  'queue_depth', 'value_density', 'dependency_unlock_score', 'decision_reason', 'saturation_status'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainQueueSaturationStopPolicy::SCHEMA, $result['schema']);
    }

    // ── saturation_status + next_action / value-density consolidate proof ────

    public function test_saturated_low_value_density_chooses_consolidate_or_audit_with_saturated_status(): void
    {
        $result = $this->policy->evaluate($this->healthy());

        $this->assertSame(AtlasExternalBrainQueueSaturationStopPolicy::DECISION_CONSOLIDATE_OR_AUDIT, $result['decision']);
        $this->assertSame('saturated', $result['saturation_status']);
        $this->assertSame($result['next_cycle_hint'], $result['next_action']);
    }

    public function test_stale_low_throughput_low_value_chooses_pause_creation_and_consolidate_with_stale_status(): void
    {
        $result = $this->policy->evaluate($this->healthy([
            'claimable_depth'    => 10,
            'servable_now'       => 10,
            'claimable_age_days' => 20,
            'serve_rate'         => 0.10,
            'value_density'      => 0.20,
        ]));

        $this->assertSame(AtlasExternalBrainQueueSaturationStopPolicy::DECISION_PAUSE_CREATION_AND_CONSOLIDATE, $result['decision']);
        $this->assertSame('stale', $result['saturation_status']);
    }

    // ── poison/malformed pressure pre-empts every other rule ──────────────────

    public function test_poison_packet_count_chooses_self_heal_before_any_create_or_exception_rule(): void
    {
        $result = $this->policy->evaluate($this->healthy([
            'value_density'           => 0.95,            // would otherwise be an exceptional-value exception
            'dependency_unlock_score' => 5,
            'poison_packet_count'     => 2,
        ]));

        $this->assertSame(AtlasExternalBrainQueueSaturationStopPolicy::DECISION_SELF_HEAL_BEFORE_MORE_VOLUME, $result['decision']);
        $this->assertSame('poisoned', $result['saturation_status']);
    }

    public function test_malformed_packet_rate_chooses_self_heal_before_any_create_or_exception_rule(): void
    {
        $result = $this->policy->evaluate($this->healthy([
            'value_density'           => 0.95,
            'dependency_unlock_score' => 5,
            'malformed_packet_rate'   => 0.10,
        ]));

        $this->assertSame(AtlasExternalBrainQueueSaturationStopPolicy::DECISION_SELF_HEAL_BEFORE_MORE_VOLUME, $result['decision']);
        $this->assertSame('poisoned', $result['saturation_status']);
    }

    // ── AC1: healthy deep queue → consolidate_or_audit ────────────────────────

    public function test_saturated_healthy_queue_selects_consolidate_or_audit(): void
    {
        $result = $this->policy->evaluate($this->healthy());

        $this->assertSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_CONSOLIDATE_OR_AUDIT,
            $result['decision'],
        );
    }

    public function test_consolidate_or_audit_at_exact_saturation_threshold(): void
    {
        $result = $this->policy->evaluate($this->healthy([
            'claimable_depth' => 20,  // exact threshold
            'servable_now'    => 5,   // exact floor
        ]));

        $this->assertSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_CONSOLIDATE_OR_AUDIT,
            $result['decision'],
        );
    }

    public function test_custom_saturation_threshold_is_respected(): void
    {
        $result = $this->policy->evaluate([
            'claimable_depth'      => 50,
            'servable_now'         => 10,
            'recoverable_backlog'  => 0,
            'saturation_threshold' => 50,
            'muscle_burn_rate_floor' => 5,
        ]);

        $this->assertSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_CONSOLIDATE_OR_AUDIT,
            $result['decision'],
        );
    }

    // ── AC2: starved + zero recoverable → originate_more ─────────────────────

    public function test_starved_with_zero_recoverable_returns_originate_more(): void
    {
        $result = $this->policy->evaluate($this->healthy([
            'claimable_depth'     => 2,   // below saturation
            'servable_now'        => 1,   // below floor
            'recoverable_backlog' => 0,
        ]));

        $this->assertSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_ORIGINATE_MORE,
            $result['decision'],
        );
    }

    public function test_originate_more_when_servable_exactly_below_floor(): void
    {
        $result = $this->policy->evaluate($this->healthy([
            'claimable_depth'       => 0,
            'servable_now'          => 4,  // below default floor of 5
            'recoverable_backlog'   => 0,
            'muscle_burn_rate_floor' => 5,
        ]));

        $this->assertSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_ORIGINATE_MORE,
            $result['decision'],
        );
    }

    // ── Unblock first when recoverable backlog exists ─────────────────────────

    public function test_unblock_first_when_starved_and_recoverable_backlog_exists(): void
    {
        $result = $this->policy->evaluate($this->healthy([
            'claimable_depth'     => 3,
            'servable_now'        => 2,   // below floor
            'recoverable_backlog' => 5,
        ]));

        $this->assertSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_UNBLOCK_FIRST,
            $result['decision'],
        );
    }

    // ── Monitor (default) ────────────────────────────────────────────────────

    public function test_monitor_when_healthy_but_not_saturated(): void
    {
        $result = $this->policy->evaluate($this->healthy([
            'claimable_depth' => 10,  // below saturation threshold
            'servable_now'    => 8,   // above floor
        ]));

        $this->assertSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_MONITOR,
            $result['decision'],
        );
    }

    // ── consolidate_or_audit wins over unblock when queue is saturated ────────

    public function test_consolidate_or_audit_wins_even_with_recoverable_backlog_when_queue_saturated(): void
    {
        // Saturated + healthy servable → consolidate_or_audit wins regardless of backlog
        $result = $this->policy->evaluate($this->healthy([
            'claimable_depth'     => 30,
            'servable_now'        => 10,
            'recoverable_backlog' => 8,  // backlog present but queue saturated and healthy
        ]));

        $this->assertSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_CONSOLIDATE_OR_AUDIT,
            $result['decision'],
        );
    }

    // ── Output fields populated ───────────────────────────────────────────────

    public function test_reason_and_hint_are_non_empty_strings(): void
    {
        $result = $this->policy->evaluate($this->healthy());

        $this->assertIsString($result['reason']);
        $this->assertNotEmpty($result['reason']);
        $this->assertIsString($result['next_cycle_hint']);
        $this->assertNotEmpty($result['next_cycle_hint']);
    }

    public function test_claimable_depth_and_servable_now_echoed_in_output(): void
    {
        $result = $this->policy->evaluate($this->healthy([
            'claimable_depth' => 42,
            'servable_now'    => 7,
        ]));

        $this->assertSame(42, $result['claimable_depth']);
        $this->assertSame(7,  $result['servable_now']);
    }

    public function test_queue_depth_is_alias_for_claimable_depth(): void
    {
        $result = $this->policy->evaluate($this->healthy(['claimable_depth' => 33]));

        $this->assertSame(33, $result['queue_depth']);
        $this->assertSame($result['claimable_depth'], $result['queue_depth']);
    }

    public function test_value_density_and_dependency_unlock_echoed_in_output(): void
    {
        $result = $this->policy->evaluate($this->healthy([
            'value_density'           => 0.9,
            'dependency_unlock_score' => 2,
        ]));

        $this->assertEqualsWithDelta(0.9, $result['value_density'], 0.001);
        $this->assertSame(2, $result['dependency_unlock_score']);
    }

    public function test_decision_reason_matches_reason(): void
    {
        $result = $this->policy->evaluate($this->healthy());

        $this->assertSame($result['reason'], $result['decision_reason']);
        $this->assertNotEmpty($result['decision_reason']);
    }

    // ── AC1: allow_enqueue_exception on high-value task during saturation ──────

    public function test_high_value_density_allows_enqueue_during_saturation(): void
    {
        $result = $this->policy->evaluate($this->healthy([
            'claimable_depth' => 25,  // saturated
            'servable_now'    => 10,
            'value_density'   => 0.90,
        ]));

        $this->assertSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_ALLOW_ENQUEUE_EXCEPTION,
            $result['decision'],
        );
    }

    public function test_dependency_unlock_allows_enqueue_during_saturation(): void
    {
        $result = $this->policy->evaluate($this->healthy([
            'claimable_depth'         => 30,
            'servable_now'            => 10,
            'value_density'           => 0.2,  // below exceptional floor
            'dependency_unlock_score' => 1,    // but unlocks a dep chain
        ]));

        $this->assertSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_ALLOW_ENQUEUE_EXCEPTION,
            $result['decision'],
        );
    }

    public function test_low_value_density_blocks_enqueue_during_saturation(): void
    {
        $result = $this->policy->evaluate($this->healthy([
            'claimable_depth'         => 25,
            'servable_now'            => 10,
            'value_density'           => 0.3,
            'dependency_unlock_score' => 0,
        ]));

        $this->assertSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_CONSOLIDATE_OR_AUDIT,
            $result['decision'],
        );
    }

    public function test_value_density_exactly_at_floor_allows_enqueue_exception(): void
    {
        $result = $this->policy->evaluate($this->healthy([
            'claimable_depth'                 => 25,
            'servable_now'                    => 10,
            'value_density'                   => 0.80,
            'exceptional_value_density_floor' => 0.80,
        ]));

        $this->assertSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_ALLOW_ENQUEUE_EXCEPTION,
            $result['decision'],
        );
    }

    public function test_allow_enqueue_exception_beats_consolidate_even_with_recoverable_backlog(): void
    {
        $result = $this->policy->evaluate($this->healthy([
            'claimable_depth'         => 25,
            'servable_now'            => 10,
            'recoverable_backlog'     => 5,
            'value_density'           => 0.95,
        ]));

        $this->assertSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_ALLOW_ENQUEUE_EXCEPTION,
            $result['decision'],
        );
    }
}
