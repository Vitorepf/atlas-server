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

    // ── AC2: interactive-only vs repeatable headless loop with evidence ─────────

    public function test_interactive_only_client_is_distinguished_from_headless_loop_capable_client(): void
    {
        $interactiveOnly = $this->svc()->probe($this->fullyCapable(['print_mode_supported' => false]));
        $this->assertSame('interactive_only', $interactiveOnly['interaction_mode']);

        $loopCapable = $this->svc()->probe($this->fullyCapable([
            'loop_execution_supported' => true,
            'reporting_supported' => true,
            'proof_capture_supported' => true,
        ]));
        $this->assertSame('headless_repeatable_loop_with_evidence', $loopCapable['interaction_mode']);
    }

    public function test_headless_but_no_loop_reporting_or_proof_is_single_shot_only(): void
    {
        $r = $this->svc()->probe($this->fullyCapable());

        $this->assertSame('headless_single_shot_only', $r['interaction_mode']);
    }

    // ── AC3: capability, limitation, required_manual_surface, expected_failure_mode, safe_use_class ──

    public function test_output_reports_capability_limitation_manual_surface_failure_mode_and_safe_use_class(): void
    {
        $r = $this->svc()->probe($this->fullyCapable());

        foreach (['capability', 'limitation', 'required_manual_surface', 'expected_failure_mode', 'safe_use_class'] as $key) {
            $this->assertArrayHasKey($key, $r, "Missing key: {$key}");
            $this->assertNotSame('', $r[$key]);
        }
    }

    public function test_not_usable_when_binary_or_login_state_missing(): void
    {
        $r = $this->svc()->probe([]);

        $this->assertSame('not_usable', $r['safe_use_class']);
    }

    public function test_manual_only_when_interaction_is_interactive_only(): void
    {
        $r = $this->svc()->probe($this->fullyCapable(['noninteractive_prompt_supported' => false]));

        $this->assertSame('manual_only', $r['safe_use_class']);
    }

    // ── AC4: refuses to mark a subscription client autonomous without loop+report+proof ──

    public function test_fully_capable_client_without_loop_reporting_or_proof_is_not_autonomous(): void
    {
        $r = $this->svc()->probe($this->fullyCapable());

        $this->assertNotSame('autonomous_muscle', $r['safe_use_class']);
        $this->assertSame('supervised_accelerator', $r['safe_use_class']);
    }

    public function test_partial_loop_capability_alone_does_not_grant_autonomy(): void
    {
        $r = $this->svc()->probe($this->fullyCapable(['loop_execution_supported' => true]));

        $this->assertNotSame('autonomous_muscle', $r['safe_use_class']);
    }

    public function test_full_loop_reporting_and_proof_capture_grants_autonomous_muscle(): void
    {
        $r = $this->svc()->probe($this->fullyCapable([
            'loop_execution_supported' => true,
            'reporting_supported' => true,
            'proof_capture_supported' => true,
        ]));

        $this->assertSame('autonomous_muscle', $r['safe_use_class']);
    }

    public function test_autonomy_is_refused_even_with_full_loop_capability_when_a_hard_blocker_remains(): void
    {
        // paid_api_required is a hard blocker independent of loop/reporting/proof facts.
        $r = $this->svc()->probe($this->fullyCapable([
            'loop_execution_supported' => true,
            'reporting_supported' => true,
            'proof_capture_supported' => true,
            'paid_api_required' => true,
        ]));

        $this->assertFalse($r['ready_for_headless_trial']);
        $this->assertNotSame('autonomous_muscle', $r['safe_use_class']);
    }
}
