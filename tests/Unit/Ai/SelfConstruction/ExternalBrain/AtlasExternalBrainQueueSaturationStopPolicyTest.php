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

        foreach (['schema', 'decision', 'reason', 'next_cycle_hint', 'claimable_depth', 'servable_now'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainQueueSaturationStopPolicy::SCHEMA, $result['schema']);
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
}
