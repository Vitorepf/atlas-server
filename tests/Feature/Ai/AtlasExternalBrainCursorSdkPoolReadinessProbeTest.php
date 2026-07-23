<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

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
        ], $overrides);
    }

    public function test_all_facts_true_is_ready_for_optional_adapter(): void
    {
        $result = $this->probe->probe($this->readyFacts());

        $this->assertSame(AtlasExternalBrainCursorSdkPoolReadinessProbe::SCHEMA, $result['schema']);
        $this->assertSame('ready_for_optional_adapter', $result['status']);
        $this->assertNotEmpty($result['next_verification_command']);
    }

    public function test_missing_sdk_blocks(): void
    {
        $result = $this->probe->probe($this->readyFacts(['sdk_package_detected' => false]));

        $this->assertSame('blocked_missing_sdk', $result['status']);
    }

    public function test_missing_billing_boundary_blocks(): void
    {
        $result = $this->probe->probe($this->readyFacts(['billing_boundary_known' => false]));

        $this->assertSame('blocked_billing_unknown', $result['status']);
    }

    public function test_missing_headless_patch_apply_blocks_ui_only(): void
    {
        $result = $this->probe->probe($this->readyFacts(['headless_patch_apply_supported' => false]));

        $this->assertSame('blocked_ui_only', $result['status']);
    }

    public function test_missing_entitlement_observation_blocks(): void
    {
        $result = $this->probe->probe($this->readyFacts(['model_pool_entitlement_observed' => false]));

        $this->assertSame('blocked_missing_entitlement_proof', $result['status']);
    }

    public function test_missing_api_key_blocks_entitlement_proof(): void
    {
        $result = $this->probe->probe($this->readyFacts(['api_key_configured' => false]));

        $this->assertSame('blocked_missing_entitlement_proof', $result['status']);
    }

    public function test_missing_cloud_agent_api_reachable_blocks_entitlement_proof(): void
    {
        $result = $this->probe->probe($this->readyFacts(['cloud_agent_api_reachable' => false]));

        $this->assertSame('blocked_missing_entitlement_proof', $result['status']);
    }

    public function test_sdk_check_takes_priority_over_other_failures(): void
    {
        $result = $this->probe->probe([
            'sdk_package_detected' => false,
            'billing_boundary_known' => false,
            'headless_patch_apply_supported' => false,
        ]);

        $this->assertSame('blocked_missing_sdk', $result['status']);
    }

    public function test_never_grants_provider_call_or_token_spend(): void
    {
        $result = $this->probe->probe($this->readyFacts());

        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
    }

    public function test_empty_input_defaults_all_facts_false_and_blocks_on_sdk(): void
    {
        $result = $this->probe->probe([]);

        $this->assertSame('blocked_missing_sdk', $result['status']);
        foreach ($result['facts'] as $value) {
            $this->assertFalse($value);
        }
    }
}
