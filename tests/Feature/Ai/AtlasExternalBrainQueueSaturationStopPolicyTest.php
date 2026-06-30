<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainQueueSaturationStopPolicy;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainQueueSaturationStopPolicyTest extends TestCase
{
    private AtlasExternalBrainQueueSaturationStopPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new AtlasExternalBrainQueueSaturationStopPolicy;
    }

    private function eval(array $input): array
    {
        return $this->policy->evaluate($input);
    }

    // ── AC1: high claimable age + low serve rate → pause_creation_and_consolidate ─

    public function test_old_queue_with_low_serve_rate_recommends_pause_and_consolidate(): void
    {
        $r = $this->eval([
            'claimable_age_days' => 20,
            'serve_rate'         => 0.10,
        ]);

        $this->assertSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_PAUSE_CREATION_AND_CONSOLIDATE,
            $r['decision']
        );
    }

    public function test_stale_queue_with_healthy_serve_rate_does_not_pause(): void
    {
        $r = $this->eval([
            'claimable_age_days' => 20,
            'serve_rate'         => 0.80,
            'value_density'      => 0.70,
        ]);

        $this->assertNotSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_PAUSE_CREATION_AND_CONSOLIDATE,
            $r['decision']
        );
    }

    public function test_fresh_queue_with_low_serve_rate_does_not_pause(): void
    {
        $r = $this->eval([
            'claimable_age_days' => 3,
            'serve_rate'         => 0.10,
        ]);

        $this->assertNotSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_PAUSE_CREATION_AND_CONSOLIDATE,
            $r['decision']
        );
    }

    public function test_pause_creation_reason_mentions_age_and_serve_rate(): void
    {
        $r = $this->eval([
            'claimable_age_days' => 20,
            'serve_rate'         => 0.10,
        ]);

        $this->assertStringContainsString('age', $r['reason']);
        $this->assertStringContainsString('serve_rate', $r['reason']);
    }

    // ── AC2: healthy throughput + fresh + high value density → continue_creation ─

    public function test_healthy_queue_with_high_value_density_recommends_continue_creation(): void
    {
        $r = $this->eval([
            'claimable_depth'    => 5,
            'servable_now'       => 10,
            'claimable_age_days' => 3,
            'serve_rate'         => 0.80,
            'value_density'      => 0.75,
            'recoverable_backlog' => 0,
        ]);

        $this->assertSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_CONTINUE_CREATION,
            $r['decision']
        );
    }

    public function test_healthy_queue_with_low_value_density_does_not_continue_creation(): void
    {
        $r = $this->eval([
            'claimable_depth'    => 5,
            'servable_now'       => 10,
            'claimable_age_days' => 3,
            'serve_rate'         => 0.80,
            'value_density'      => 0.20,  // below floor
            'recoverable_backlog' => 0,
        ]);

        $this->assertNotSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_CONTINUE_CREATION,
            $r['decision']
        );
        $this->assertSame(AtlasExternalBrainQueueSaturationStopPolicy::DECISION_MONITOR, $r['decision']);
    }

    public function test_continue_creation_reason_mentions_value_density(): void
    {
        $r = $this->eval([
            'claimable_age_days' => 3,
            'serve_rate'         => 0.80,
            'value_density'      => 0.75,
            'servable_now'       => 10,
        ]);

        $this->assertSame(AtlasExternalBrainQueueSaturationStopPolicy::DECISION_CONTINUE_CREATION, $r['decision']);
        $this->assertStringContainsString('value_density', $r['reason']);
    }

    // ── AC3: poison pressure or malformed packets → self_heal_before_more_volume ─

    public function test_poison_packet_count_above_threshold_recommends_self_heal(): void
    {
        $r = $this->eval(['poison_packet_count' => 3]);

        $this->assertSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_SELF_HEAL_BEFORE_MORE_VOLUME,
            $r['decision']
        );
    }

    public function test_malformed_packet_rate_above_zero_recommends_self_heal(): void
    {
        $r = $this->eval(['malformed_packet_rate' => 0.15]);

        $this->assertSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_SELF_HEAL_BEFORE_MORE_VOLUME,
            $r['decision']
        );
    }

    public function test_self_heal_takes_priority_over_all_other_decisions(): void
    {
        // Even with high value density and healthy throughput, poison wins
        $r = $this->eval([
            'poison_packet_count' => 2,
            'value_density'      => 0.95,
            'serve_rate'         => 0.90,
            'servable_now'       => 20,
        ]);

        $this->assertSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_SELF_HEAL_BEFORE_MORE_VOLUME,
            $r['decision']
        );
    }

    public function test_no_poison_does_not_trigger_self_heal(): void
    {
        $r = $this->eval(['poison_packet_count' => 0, 'malformed_packet_rate' => 0.0]);

        $this->assertNotSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_SELF_HEAL_BEFORE_MORE_VOLUME,
            $r['decision']
        );
    }

    // ── AC4: deterministic, quota goals not evidence ───────────────────────────

    public function test_output_is_deterministic(): void
    {
        $input = ['claimable_depth' => 10, 'serve_rate' => 0.5, 'value_density' => 0.7];

        $this->assertSame(json_encode($this->eval($input)), json_encode($this->eval($input)));
    }

    public function test_default_healthy_state_is_monitor(): void
    {
        // servable_now must be >= burn_rate_floor (default 5) to avoid starved branch
        $r = $this->eval(['servable_now' => 10, 'value_density' => 0.0]);

        $this->assertSame(AtlasExternalBrainQueueSaturationStopPolicy::DECISION_MONITOR, $r['decision']);
    }

    public function test_schema_is_set(): void
    {
        $r = $this->eval([]);

        $this->assertSame(AtlasExternalBrainQueueSaturationStopPolicy::SCHEMA, $r['schema']);
    }

    public function test_depth_saturation_without_exceptional_value_consolidates(): void
    {
        $r = $this->eval([
            'claimable_depth' => 25,  // above default 20
            'servable_now'    => 10,
            'value_density'   => 0.20,
        ]);

        $this->assertSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_CONSOLIDATE_OR_AUDIT,
            $r['decision']
        );
    }

    public function test_saturated_queue_with_exceptional_value_allows_exception(): void
    {
        $r = $this->eval([
            'claimable_depth' => 25,
            'value_density'   => 0.95,
        ]);

        $this->assertSame(
            AtlasExternalBrainQueueSaturationStopPolicy::DECISION_ALLOW_ENQUEUE_EXCEPTION,
            $r['decision']
        );
    }
}
