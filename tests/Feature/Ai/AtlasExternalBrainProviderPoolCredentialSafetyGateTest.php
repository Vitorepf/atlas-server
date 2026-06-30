<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolCredentialSafetyGate;
use Tests\TestCase;

final class AtlasExternalBrainProviderPoolCredentialSafetyGateTest extends TestCase
{
    private AtlasExternalBrainProviderPoolCredentialSafetyGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new AtlasExternalBrainProviderPoolCredentialSafetyGate;
    }

    private function safeFacts(array $overrides = []): array
    {
        return array_merge([
            'local_client_logged_in' => true,
            'subscription_entitlement_observed' => true,
            'credential_value_present' => false,
            'redaction_status' => true,
            'environment_scope' => true,
            'operator_attested_entitlement' => true,
        ], $overrides);
    }

    public function test_fully_safe_facts_yield_safe_to_probe(): void
    {
        $result = $this->gate->assess($this->safeFacts());

        $this->assertSame(AtlasExternalBrainProviderPoolCredentialSafetyGate::SCHEMA, $result['schema']);
        $this->assertTrue($result['safe_to_probe']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame(0, $result['blocker_count']);
    }

    public function test_raw_secret_in_payload_blocks(): void
    {
        $result = $this->gate->assess($this->safeFacts(['credential_value_present' => true]));

        $this->assertContains('raw_secret_in_payload', $result['blockers']);
        $this->assertFalse($result['safe_to_probe']);
    }

    public function test_paid_api_key_required_blocks(): void
    {
        $result = $this->gate->assess($this->safeFacts(['subscription_entitlement_observed' => false]));

        $this->assertContains('paid_api_key_required', $result['blockers']);
    }

    public function test_unredacted_secret_reference_blocks(): void
    {
        $result = $this->gate->assess($this->safeFacts(['redaction_status' => false]));

        $this->assertContains('unredacted_secret_reference', $result['blockers']);
        $this->assertFalse($result['safe_to_probe']);
    }

    public function test_user_home_global_secret_required_blocks(): void
    {
        $result = $this->gate->assess($this->safeFacts(['environment_scope' => false]));

        $this->assertContains('user_home_global_secret_required', $result['blockers']);
        $this->assertFalse($result['safe_to_probe']);
    }

    public function test_missing_rotation_plan_blocks(): void
    {
        $result = $this->gate->assess($this->safeFacts(['operator_attested_entitlement' => false]));

        $this->assertContains('missing_rotation_plan', $result['blockers']);
    }

    public function test_provider_required_for_steady_state_blocks(): void
    {
        $result = $this->gate->assess($this->safeFacts(['local_client_logged_in' => false]));

        $this->assertContains('provider_required_for_steady_state', $result['blockers']);
        $this->assertFalse($result['safe_to_probe']);
    }

    public function test_gate_itself_never_allows_provider_calls(): void
    {
        $resultSafe = $this->gate->assess($this->safeFacts());
        $resultUnsafe = $this->gate->assess([]);

        $this->assertFalse($resultSafe['provider_call_allowed']);
        $this->assertFalse($resultUnsafe['provider_call_allowed']);
    }

    public function test_empty_input_defaults_to_unsafe_with_multiple_blockers(): void
    {
        $result = $this->gate->assess([]);

        $this->assertFalse($result['safe_to_probe']);
        $this->assertContains('paid_api_key_required', $result['blockers']);
        $this->assertContains('unredacted_secret_reference', $result['blockers']);
        $this->assertContains('user_home_global_secret_required', $result['blockers']);
        $this->assertContains('missing_rotation_plan', $result['blockers']);
        $this->assertContains('provider_required_for_steady_state', $result['blockers']);
        $this->assertNotContains('raw_secret_in_payload', $result['blockers']);
    }
}
