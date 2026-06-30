<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierControlPlane;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAmplifierControlPlaneTest extends TestCase
{
    private AtlasExternalBrainAmplifierControlPlane $cp;

    protected function setUp(): void
    {
        $this->cp = new AtlasExternalBrainAmplifierControlPlane;
    }

    private function decide(array $input = []): array
    {
        return $this->cp->decide($input);
    }

    // ── AC2: missing telemetry_status → baseline ──────────────────────────────

    public function test_missing_telemetry_returns_baseline(): void
    {
        $r = $this->decide([]);

        $this->assertSame(AtlasExternalBrainAmplifierControlPlane::MODE_BASELINE, $r['mode']);
    }

    public function test_missing_telemetry_reason_names_required_absence(): void
    {
        $r = $this->decide([]);

        $reasons = implode(' ', $r['reasons']);
        $this->assertStringContainsString('telemetry', $reasons);
    }

    public function test_missing_telemetry_sets_next_action_to_await_telemetry(): void
    {
        $r = $this->decide([]);

        $this->assertStringContainsString('telemetry', $r['next_safe_action']);
    }

    // ── AC3: healthy telemetry + blocking_signals → baseline contradiction ────

    public function test_healthy_telemetry_with_blocking_signals_returns_baseline(): void
    {
        $r = $this->decide([
            'telemetry_status' => 'healthy',
            'blocking_signals' => ['shadow_fail'],
        ]);

        $this->assertSame(AtlasExternalBrainAmplifierControlPlane::MODE_BASELINE, $r['mode']);
    }

    public function test_contradiction_next_action_names_investigation(): void
    {
        $r = $this->decide([
            'telemetry_status' => 'healthy',
            'blocking_signals' => ['shadow_fail'],
        ]);

        $this->assertStringContainsString('contradiction', $r['next_safe_action']);
    }

    public function test_contradiction_reason_mentions_healthy_and_blocking(): void
    {
        $r = $this->decide([
            'telemetry_status' => 'healthy',
            'blocking_signals' => ['shadow_fail'],
        ]);

        $reasons = implode(' ', $r['reasons']);
        $this->assertStringContainsString('contradictory', $reasons);
    }

    // ── AC4: mode resolution is deterministic ─────────────────────────────────

    public function test_rollback_mode_on_rollback_candidate_telemetry(): void
    {
        $r = $this->decide([
            'telemetry_status' => 'rollback_candidate',
        ]);

        $this->assertSame(AtlasExternalBrainAmplifierControlPlane::MODE_ROLLBACK, $r['mode']);
    }

    public function test_rollback_mode_on_kill_switch(): void
    {
        $r = $this->decide([
            'telemetry_status'   => 'healthy',
            'kill_switch_active' => true,
        ]);

        $this->assertSame(AtlasExternalBrainAmplifierControlPlane::MODE_ROLLBACK, $r['mode']);
    }

    public function test_canary_mode_on_all_canary_conditions(): void
    {
        $r = $this->decide([
            'telemetry_status'    => 'healthy',
            'canary_enabled'      => true,
            'promotion_candidate' => true,
            'slo_met'             => true,
        ]);

        $this->assertSame(AtlasExternalBrainAmplifierControlPlane::MODE_CANARY, $r['mode']);
    }

    public function test_shadow_only_when_shadow_enabled_and_not_promotion(): void
    {
        $r = $this->decide([
            'telemetry_status'    => 'healthy',
            'shadow_enabled'      => true,
            'promotion_candidate' => false,
        ]);

        $this->assertSame(AtlasExternalBrainAmplifierControlPlane::MODE_SHADOW_ONLY, $r['mode']);
    }

    public function test_scaffolded_live_when_scaffold_available_and_slo_met(): void
    {
        $r = $this->decide([
            'telemetry_status'    => 'healthy',
            'scaffolded_available' => true,
            'slo_met'             => true,
        ]);

        $this->assertSame(AtlasExternalBrainAmplifierControlPlane::MODE_SCAFFOLDED_LIVE, $r['mode']);
    }

    public function test_frontier_escalation_on_quality_fail_with_high_leverage(): void
    {
        $r = $this->decide([
            'telemetry_status'         => 'healthy',
            'held_out_pass_rate'       => 0.50,  // below 0.70 threshold
            'structural_leverage_score' => 8.0,   // above 7.0 threshold
        ]);

        $this->assertSame(AtlasExternalBrainAmplifierControlPlane::MODE_FRONTIER_ESCALATION, $r['mode']);
    }

    public function test_baseline_when_no_amplifier_condition_met(): void
    {
        $r = $this->decide([
            'telemetry_status' => 'healthy',
        ]);

        $this->assertSame(AtlasExternalBrainAmplifierControlPlane::MODE_BASELINE, $r['mode']);
    }

    public function test_output_always_includes_required_fields(): void
    {
        $r = $this->decide(['telemetry_status' => 'healthy']);

        $this->assertArrayHasKey('mode', $r);
        $this->assertArrayHasKey('reasons', $r);
        $this->assertArrayHasKey('blocking_signals', $r);
        $this->assertArrayHasKey('next_safe_action', $r);
        $this->assertArrayHasKey('provider_independence_status', $r);
    }

    // ── deterministic ─────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $input = ['telemetry_status' => 'rollback_candidate'];

        $this->assertSame(json_encode($this->decide($input)), json_encode($this->decide($input)));
    }

    public function test_schema_is_set(): void
    {
        $r = $this->decide(['telemetry_status' => 'healthy']);

        $this->assertSame(AtlasExternalBrainAmplifierControlPlane::SCHEMA, $r['schema']);
    }
}
