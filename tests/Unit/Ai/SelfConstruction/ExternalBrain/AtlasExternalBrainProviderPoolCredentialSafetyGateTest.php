<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolCredentialSafetyGate;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainProviderPoolCredentialSafetyGateTest extends TestCase
{
    private function gate(): AtlasExternalBrainProviderPoolCredentialSafetyGate
    {
        return new AtlasExternalBrainProviderPoolCredentialSafetyGate;
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

    // ── Schema / envelope ────────────────────────────────────────────────────

    public function test_output_has_redacted_diagnostics_key(): void
    {
        $result = $this->gate()->assess($this->safeFacts());

        $this->assertArrayHasKey('redacted_diagnostics', $result);
        $this->assertSame([], $result['redacted_diagnostics']);
    }

    // ── AC2: raw token/API key/password-like fields are rejected and redacted ──

    public function test_api_key_field_in_client_descriptor_is_rejected_and_redacted(): void
    {
        $result = $this->gate()->assess($this->safeFacts([
            'client_descriptor' => ['api_key' => 'sk-live-super-secret-abc123'],
        ]));

        $this->assertContains('raw_secret_in_payload', $result['blockers']);
        $this->assertFalse($result['safe_to_probe']);
        $this->assertContains('api_key=[REDACTED]', $result['redacted_diagnostics']);
        $this->assertStringNotContainsString('sk-live-super-secret-abc123', json_encode($result));
    }

    public function test_token_field_in_client_descriptor_is_rejected_and_redacted(): void
    {
        $result = $this->gate()->assess($this->safeFacts([
            'client_descriptor' => ['auth_token' => 'ghp_abcdef123456'],
        ]));

        $this->assertContains('raw_secret_in_payload', $result['blockers']);
        $this->assertContains('auth_token=[REDACTED]', $result['redacted_diagnostics']);
        $this->assertStringNotContainsString('ghp_abcdef123456', json_encode($result));
    }

    public function test_password_field_in_client_descriptor_is_rejected_and_redacted(): void
    {
        $result = $this->gate()->assess($this->safeFacts([
            'client_descriptor' => ['password' => 'hunter2'],
        ]));

        $this->assertContains('raw_secret_in_payload', $result['blockers']);
        $this->assertContains('password=[REDACTED]', $result['redacted_diagnostics']);
        $this->assertStringNotContainsString('hunter2', json_encode($result));
    }

    public function test_multiple_secret_fields_all_appear_in_redacted_diagnostics(): void
    {
        $result = $this->gate()->assess($this->safeFacts([
            'client_descriptor' => ['api_key' => 'x', 'session_secret' => 'y'],
        ]));

        $this->assertCount(2, $result['redacted_diagnostics']);
        $this->assertContains('api_key=[REDACTED]', $result['redacted_diagnostics']);
        $this->assertContains('session_secret=[REDACTED]', $result['redacted_diagnostics']);
    }

    // ── AC3: billing-required assumptions are disallowed_steady_state_dependency ──

    public function test_billing_required_is_flagged_as_disallowed_steady_state_dependency(): void
    {
        $result = $this->gate()->assess($this->safeFacts(['billing_required' => true]));

        $this->assertContains('disallowed_steady_state_dependency', $result['blockers']);
        $this->assertFalse($result['safe_to_probe']);
    }

    public function test_billing_not_required_does_not_flag_dependency_blocker(): void
    {
        $result = $this->gate()->assess($this->safeFacts(['billing_required' => false]));

        $this->assertNotContains('disallowed_steady_state_dependency', $result['blockers']);
    }

    // ── AC4: safe local client descriptors (capability/boundary metadata only) pass ──

    public function test_client_descriptor_with_only_capability_metadata_does_not_block(): void
    {
        $result = $this->gate()->assess($this->safeFacts([
            'client_descriptor' => [
                'headless_supported' => true,
                'model_hints' => ['gpt-5'],
                'local_invocation_supported' => true,
            ],
        ]));

        $this->assertSame([], $result['blockers']);
        $this->assertSame([], $result['redacted_diagnostics']);
        $this->assertTrue($result['safe_to_probe']);
    }

    public function test_empty_client_descriptor_does_not_block(): void
    {
        $result = $this->gate()->assess($this->safeFacts(['client_descriptor' => []]));

        $this->assertSame([], $result['blockers']);
        $this->assertTrue($result['safe_to_probe']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $input = $this->safeFacts(['client_descriptor' => ['api_key' => 'x']]);

        $this->assertSame(
            json_encode($this->gate()->assess($input)),
            json_encode($this->gate()->assess($input)),
        );
    }

    // ── AC2: explicit blocker tests (raw_secret already tested via descriptor) ──

    public function test_paid_api_key_required_blocker_when_not_entitled(): void
    {
        $result = $this->gate()->assess($this->safeFacts([
            'subscription_entitlement_observed' => false,
        ]));

        $this->assertContains('paid_api_key_required', $result['blockers']);
        $this->assertFalse($result['safe_to_probe']);
    }

    public function test_unredacted_secret_reference_blocker_when_not_redacted(): void
    {
        $result = $this->gate()->assess($this->safeFacts([
            'redaction_status' => false,
        ]));

        $this->assertContains('unredacted_secret_reference', $result['blockers']);
        $this->assertFalse($result['safe_to_probe']);
    }

    public function test_user_home_global_secret_required_blocker_when_not_environment_scoped(): void
    {
        $result = $this->gate()->assess($this->safeFacts([
            'environment_scope' => false,
        ]));

        $this->assertContains('user_home_global_secret_required', $result['blockers']);
        $this->assertFalse($result['safe_to_probe']);
    }

    public function test_missing_rotation_plan_blocker_when_not_attested(): void
    {
        $result = $this->gate()->assess($this->safeFacts([
            'operator_attested_entitlement' => false,
        ]));

        $this->assertContains('missing_rotation_plan', $result['blockers']);
        $this->assertFalse($result['safe_to_probe']);
    }

    // ── AC2: each blocker is independent — removing one doesn't drop others ──

    public function test_multiple_blockers_all_reported(): void
    {
        $result = $this->gate()->assess([]);

        $this->assertContains('paid_api_key_required', $result['blockers']);
        $this->assertContains('unredacted_secret_reference', $result['blockers']);
        $this->assertContains('user_home_global_secret_required', $result['blockers']);
        $this->assertContains('missing_rotation_plan', $result['blockers']);
        $this->assertContains('provider_required_for_steady_state', $result['blockers']);
        $this->assertCount(5, $result['blockers']);
        $this->assertFalse($result['safe_to_probe']);
    }

    // ── AC4: output shape ─────────────────────────────────────────────────────

    public function test_output_includes_safe_to_probe_blockers_facts_and_provider_call_allowed(): void
    {
        $result = $this->gate()->assess($this->safeFacts());

        $this->assertArrayHasKey('safe_to_probe', $result);
        $this->assertArrayHasKey('blockers', $result);
        $this->assertArrayHasKey('facts', $result);
        $this->assertArrayHasKey('provider_call_allowed', $result);

        // provider_call_allowed must always be false — the gate never calls a provider.
        $this->assertFalse($result['provider_call_allowed']);
    }

    public function test_facts_reflect_input_booleans(): void
    {
        $result = $this->gate()->assess($this->safeFacts());

        $this->assertTrue($result['facts']['local_client_logged_in']);
        $this->assertTrue($result['facts']['subscription_entitlement_observed']);
        $this->assertFalse($result['facts']['credential_value_present']);
        $this->assertTrue($result['facts']['redaction_status']);
        $this->assertTrue($result['facts']['environment_scope']);
        $this->assertTrue($result['facts']['operator_attested_entitlement']);
    }

    // ── AC3: never allows provider calls from credential assessment ──────────

    public function test_provider_call_allowed_is_always_false_regardless_of_input(): void
    {
        $allBlocked = $this->gate()->assess([]);
        $allSafe    = $this->gate()->assess($this->safeFacts());
        $mixed      = $this->gate()->assess($this->safeFacts(['credential_value_present' => true]));

        $this->assertFalse($allBlocked['provider_call_allowed']);
        $this->assertFalse($allSafe['provider_call_allowed']);
        $this->assertFalse($mixed['provider_call_allowed']);
    }
}
