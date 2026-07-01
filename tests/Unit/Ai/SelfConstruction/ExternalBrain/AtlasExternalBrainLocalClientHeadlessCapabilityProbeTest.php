<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalClientHeadlessCapabilityProbe;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainLocalClientHeadlessCapabilityProbeTest extends TestCase
{
    private function svc(): AtlasExternalBrainLocalClientHeadlessCapabilityProbe
    {
        return new AtlasExternalBrainLocalClientHeadlessCapabilityProbe;
    }

    private function fullyCapable(array $overrides = []): array
    {
        return array_merge([
            'binary_exists' => true,
            'login_state_known' => true,
            'print_mode_supported' => true,
            'noninteractive_prompt_supported' => true,
            'patch_output_supported' => true,
            'workspace_scoping_supported' => true,
            'paid_api_required' => false,
        ], $overrides);
    }

    public function test_fully_capable_input_is_ready_for_headless_trial(): void
    {
        $r = $this->svc()->probe($this->fullyCapable());

        $this->assertTrue($r['ready_for_headless_trial']);
        $this->assertSame([], $r['blockers']);
    }

    public function test_blocks_readiness_when_paid_api_required_even_with_other_capabilities_present(): void
    {
        $r = $this->svc()->probe($this->fullyCapable(['paid_api_required' => true]));

        $this->assertFalse($r['ready_for_headless_trial']);
        $this->assertContains('paid_api_required', $r['blockers']);
    }

    public function test_reports_ui_fragility_risk_from_print_mode_prompt_patch_and_workspace_facts(): void
    {
        $highFragility = $this->svc()->probe($this->fullyCapable([
            'print_mode_supported' => false,
            'noninteractive_prompt_supported' => false,
            'patch_output_supported' => false,
        ]));
        $this->assertSame('high', $highFragility['ui_fragility_risk']);

        $mediumFragility = $this->svc()->probe($this->fullyCapable(['patch_output_supported' => false]));
        $this->assertSame('medium', $mediumFragility['ui_fragility_risk']);

        $lowFragility = $this->svc()->probe($this->fullyCapable());
        $this->assertSame('low', $lowFragility['ui_fragility_risk']);
    }

    public function test_output_includes_ready_flag_blockers_ui_fragility_risk_and_provider_call_disallowed(): void
    {
        $r = $this->svc()->probe($this->fullyCapable());

        foreach (['ready_for_headless_trial', 'blockers', 'ui_fragility_risk', 'provider_call_allowed'] as $key) {
            $this->assertArrayHasKey($key, $r, "Missing key: {$key}");
        }
        $this->assertFalse($r['provider_call_allowed']);
    }

    public function test_missing_binary_and_login_state_are_reported(): void
    {
        $r = $this->svc()->probe([]);

        $this->assertFalse($r['ready_for_headless_trial']);
        $this->assertContains('binary_missing', $r['blockers']);
        $this->assertContains('missing_login_state', $r['blockers']);
    }
}
