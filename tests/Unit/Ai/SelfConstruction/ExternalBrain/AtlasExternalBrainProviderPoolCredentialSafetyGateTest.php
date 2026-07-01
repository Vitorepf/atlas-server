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
}
