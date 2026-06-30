<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAmplifierControlPlane;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAmplifierControlPlaneTest extends TestCase
{
    private AtlasExternalBrainAmplifierControlPlane $plane;

    protected function setUp(): void
    {
        $this->plane = new AtlasExternalBrainAmplifierControlPlane;
    }

    private function allGreen(): array
    {
        return [
            'kill_switch_active'   => false,
            'telemetry_status'     => 'healthy',
            'blocking_signals'     => [],
            'promotion_candidate'  => true,
            'slo_met'              => true,
            'shadow_enabled'       => true,
            'canary_enabled'       => true,
            'scaffolded_available' => true,
        ];
    }

    // ── Schema / AC4 required keys ────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->plane->decide($this->allGreen());

        foreach (['schema', 'mode', 'reasons', 'blocking_signals', 'next_safe_action', 'provider_independence_status'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainAmplifierControlPlane::SCHEMA, $result['schema']);
    }

    // ── AC3: fail closed when telemetry_status is missing ────────────────────

    public function test_missing_telemetry_status_fails_closed_to_baseline(): void
    {
        $result = $this->plane->decide([
            'kill_switch_active'  => false,
            'promotion_candidate' => true,
            'slo_met'             => true,
        ]);

        $this->assertSame(AtlasExternalBrainAmplifierControlPlane::MODE_BASELINE, $result['mode']);
        $reasons = implode(' ', $result['reasons']);
        $this->assertStringContainsString('required_telemetry_missing', $reasons);
    }

    // ── AC3: fail closed on contradictory signals ─────────────────────────────

    public function test_healthy_telemetry_with_blocking_signals_fails_closed(): void
    {
        $result = $this->plane->decide([
            'telemetry_status' => 'healthy',
            'blocking_signals' => ['canary_pass_rate:0.20<0.4'],
        ]);

        $this->assertSame(AtlasExternalBrainAmplifierControlPlane::MODE_BASELINE, $result['mode']);
        $reasons = implode(' ', $result['reasons']);
        $this->assertStringContainsString('contradictory_signals', $reasons);
    }

    // ── AC2: rollback mode ────────────────────────────────────────────────────

    public function test_kill_switch_active_forces_rollback(): void
    {
        $input                       = $this->allGreen();
        $input['kill_switch_active'] = true;

        $result = $this->plane->decide($input);

        $this->assertSame(AtlasExternalBrainAmplifierControlPlane::MODE_ROLLBACK, $result['mode']);
        $this->assertStringContainsString('kill_switch_active', implode(' ', $result['reasons']));
    }

    public function test_rollback_candidate_telemetry_forces_rollback(): void
    {
        $input                   = $this->allGreen();
        $input['telemetry_status'] = 'rollback_candidate';
        $input['blocking_signals'] = ['canary_pass_rate:low'];

        $result = $this->plane->decide($input);

        $this->assertSame(AtlasExternalBrainAmplifierControlPlane::MODE_ROLLBACK, $result['mode']);
    }

    public function test_non_empty_blocking_signals_force_rollback(): void
    {
        $result = $this->plane->decide([
            'telemetry_status' => 'watch',
            'blocking_signals' => ['shadow_pass_rate:0.20<0.5'],
        ]);

        $this->assertSame(AtlasExternalBrainAmplifierControlPlane::MODE_ROLLBACK, $result['mode']);
        $this->assertNotEmpty($result['blocking_signals']);
    }

    // ── AC2: canary mode ──────────────────────────────────────────────────────

    public function test_all_green_signals_select_canary(): void
    {
        $result = $this->plane->decide($this->allGreen());

        $this->assertSame(AtlasExternalBrainAmplifierControlPlane::MODE_CANARY, $result['mode']);
    }

    public function test_canary_disabled_prevents_canary_mode(): void
    {
        $input                 = $this->allGreen();
        $input['canary_enabled'] = false;

        $result = $this->plane->decide($input);

        $this->assertNotSame(AtlasExternalBrainAmplifierControlPlane::MODE_CANARY, $result['mode']);
    }

    public function test_slo_not_met_prevents_canary(): void
    {
        $input            = $this->allGreen();
        $input['slo_met'] = false;

        $result = $this->plane->decide($input);

        $this->assertNotSame(AtlasExternalBrainAmplifierControlPlane::MODE_CANARY, $result['mode']);
    }

    // ── AC2: shadow_only mode ─────────────────────────────────────────────────

    public function test_shadow_enabled_without_promotion_selects_shadow_only(): void
    {
        $result = $this->plane->decide([
            'telemetry_status'    => 'healthy',
            'blocking_signals'    => [],
            'shadow_enabled'      => true,
            'promotion_candidate' => false,
            'canary_enabled'      => false,
        ]);

        $this->assertSame(AtlasExternalBrainAmplifierControlPlane::MODE_SHADOW_ONLY, $result['mode']);
    }

    // ── AC2: scaffolded_live mode ─────────────────────────────────────────────

    public function test_scaffolded_available_with_slo_met_selects_scaffolded_live(): void
    {
        $result = $this->plane->decide([
            'telemetry_status'     => 'healthy',
            'blocking_signals'     => [],
            'shadow_enabled'       => false,
            'promotion_candidate'  => true,
            'canary_enabled'       => false,
            'scaffolded_available' => true,
            'slo_met'              => true,
        ]);

        $this->assertSame(AtlasExternalBrainAmplifierControlPlane::MODE_SCAFFOLDED_LIVE, $result['mode']);
    }

    // ── AC2: baseline fallback ────────────────────────────────────────────────

    public function test_no_conditions_met_falls_back_to_baseline(): void
    {
        $result = $this->plane->decide([
            'telemetry_status'     => 'healthy',
            'blocking_signals'     => [],
            'shadow_enabled'       => false,
            'promotion_candidate'  => true,
            'canary_enabled'       => false,
            'scaffolded_available' => false,
            'slo_met'              => false,
        ]);

        $this->assertSame(AtlasExternalBrainAmplifierControlPlane::MODE_BASELINE, $result['mode']);
    }

    // ── AC4: provider_independence_status ─────────────────────────────────────

    public function test_provider_independence_status_is_always_provider_free(): void
    {
        foreach ([
            $this->allGreen(),
            ['telemetry_status' => 'rollback_candidate', 'blocking_signals' => []],
            ['telemetry_status' => 'healthy', 'blocking_signals' => []],
        ] as $input) {
            $result = $this->plane->decide($input);
            $this->assertStringContainsString('provider_free', $result['provider_independence_status']);
        }
    }

    // ── rollback carries blocking_signals in output ───────────────────────────

    public function test_rollback_mode_echoes_blocking_signals(): void
    {
        $signals = ['shadow_pass_rate:0.30<0.5', 'canary_pass_rate:0.20<0.4'];

        $result = $this->plane->decide([
            'telemetry_status' => 'watch',
            'blocking_signals' => $signals,
        ]);

        $this->assertSame($signals, $result['blocking_signals']);
    }
}
