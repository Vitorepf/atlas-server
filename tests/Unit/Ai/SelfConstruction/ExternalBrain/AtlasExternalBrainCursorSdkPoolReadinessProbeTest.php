<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCursorSdkPoolReadinessProbe;
use Tests\TestCase;

final class AtlasExternalBrainCursorSdkPoolReadinessProbeTest extends TestCase
{
    private AtlasExternalBrainCursorSdkPoolReadinessProbe $probe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->probe = new AtlasExternalBrainCursorSdkPoolReadinessProbe;
    }

    private function readyFacts(array $overrides = []): array
    {
        return array_merge([
            'sdk_package_detected' => true,
            'api_key_configured' => true,
            'cloud_agent_api_reachable' => true,
            'model_pool_entitlement_observed' => true,
            'headless_patch_apply_supported' => true,
            'billing_boundary_known' => true,
            'proof_capture_observed' => true,
        ], $overrides);
    }

    // ── AC2: readiness_class coarse vocabulary ──────────────────────────────────

    public function test_ready_status_maps_to_ready_readiness_class(): void
    {
        $result = $this->probe->probe($this->readyFacts());

        $this->assertSame('ready', $result['readiness_class']);
    }

    public function test_missing_sdk_maps_to_no_sdk_boundary(): void
    {
        $result = $this->probe->probe($this->readyFacts(['sdk_package_detected' => false]));

        $this->assertSame('no_sdk_boundary', $result['readiness_class']);
    }

    public function test_missing_billing_boundary_maps_to_unknown_cost(): void
    {
        $result = $this->probe->probe($this->readyFacts(['billing_boundary_known' => false]));

        $this->assertSame('unknown_cost', $result['readiness_class']);
    }

    public function test_missing_headless_maps_to_interactive_only(): void
    {
        $result = $this->probe->probe($this->readyFacts(['headless_patch_apply_supported' => false]));

        $this->assertSame('interactive_only', $result['readiness_class']);
    }

    public function test_missing_entitlement_proof_maps_to_unsafe_dependency(): void
    {
        $result = $this->probe->probe($this->readyFacts(['model_pool_entitlement_observed' => false]));

        $this->assertSame('unsafe_dependency', $result['readiness_class']);
    }

    // ── AC3: Atlas-native steady-state autonomy is never laundered in ──────────

    public function test_steady_state_autonomy_true_only_when_headless_and_proof_capture_and_ready(): void
    {
        $result = $this->probe->probe($this->readyFacts());

        $this->assertTrue($result['atlas_native_steady_state_autonomy']);
    }

    public function test_steady_state_autonomy_false_without_proof_capture(): void
    {
        $result = $this->probe->probe($this->readyFacts(['proof_capture_observed' => false]));

        $this->assertFalse($result['atlas_native_steady_state_autonomy']);
    }

    public function test_steady_state_autonomy_false_without_headless_support(): void
    {
        $result = $this->probe->probe($this->readyFacts(['headless_patch_apply_supported' => false]));

        $this->assertFalse($result['atlas_native_steady_state_autonomy']);
    }

    public function test_steady_state_autonomy_false_when_not_ready(): void
    {
        $result = $this->probe->probe($this->readyFacts(['sdk_package_detected' => false]));

        $this->assertFalse($result['atlas_native_steady_state_autonomy']);
    }

    // ── AC4: safe usage notes + fallback recommendation, no paid access needed ─

    public function test_ready_status_includes_usage_notes_and_fallback(): void
    {
        $result = $this->probe->probe($this->readyFacts());

        $this->assertNotEmpty($result['safe_usage_notes']);
        $this->assertNotEmpty($result['fallback_recommendation']);
    }

    public function test_blocked_status_includes_usage_notes_and_fallback_without_paid_access(): void
    {
        $result = $this->probe->probe([]);

        $this->assertSame('blocked_missing_sdk', $result['status']);
        $this->assertNotEmpty($result['safe_usage_notes']);
        $this->assertNotEmpty($result['fallback_recommendation']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
    }

    public function test_proof_capture_defaults_false_and_present_in_facts(): void
    {
        $result = $this->probe->probe([]);

        $this->assertArrayHasKey('proof_capture_observed', $result['facts']);
        $this->assertFalse($result['facts']['proof_capture_observed']);
    }

    // AC: output includes optional_adapter_only=true
    public function test_output_includes_optional_adapter_only(): void
    {
        $result = $this->probe->probe([]);
        $this->assertTrue($result['optional_adapter_only']);
    }

    // AC: probe never allows provider calls or token spend
    public function test_probe_never_allows_provider_calls_or_token_spend(): void
    {
        $result = $this->probe->probe([
            'sdk_package_detected' => true,
            'billing_boundary_known' => true,
            'headless_patch_apply_supported' => true,
            'entitlement_proof_present' => true,
            'proof_capture_observed' => true,
        ]);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
    }
}
