<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalClientInvocationBoundary;
use Tests\TestCase;

final class AtlasExternalBrainLocalClientInvocationBoundaryTest extends TestCase
{
    private AtlasExternalBrainLocalClientInvocationBoundary $boundary;

    protected function setUp(): void
    {
        parent::setUp();
        $this->boundary = new AtlasExternalBrainLocalClientInvocationBoundary;
    }

    private function safeFacts(array $overrides = []): array
    {
        return array_merge([
            'replaceable' => true,
            'scoped' => true,
            'non_authoritative' => true,
            'requires_raw_secret_passthrough' => false,
            'requires_broad_filesystem_authority' => false,
            'requires_direct_git_commit_rights' => false,
            'requires_direct_atlas_task_report_rights' => false,
            'requires_paid_api_credentials' => false,
        ], $overrides);
    }

    // ── AC: rejects raw credential, token or billing-field capture ─────────────

    public function test_raw_api_key_field_is_rejected(): void
    {
        $result = $this->boundary->describe($this->safeFacts(['api_key' => 'sk-live-abc123']));

        $this->assertFalse($result['invocation_contract_ready']);
        $this->assertContains('raw_credential_or_billing_field_captured:api_key', $result['blockers']);
    }

    public function test_raw_token_field_is_rejected(): void
    {
        $result = $this->boundary->describe($this->safeFacts(['token' => 'abc123']));

        $this->assertFalse($result['invocation_contract_ready']);
        $this->assertContains('raw_credential_or_billing_field_captured:token', $result['blockers']);
    }

    public function test_raw_billing_field_is_rejected(): void
    {
        $result = $this->boundary->describe($this->safeFacts(['billing_amount' => 42.0]));

        $this->assertFalse($result['invocation_contract_ready']);
        $this->assertContains('raw_credential_or_billing_field_captured:billing_amount', $result['blockers']);
    }

    public function test_safe_facts_alone_do_not_trigger_credential_scan(): void
    {
        $result = $this->boundary->describe($this->safeFacts());

        $this->assertSame([], $result['blockers']);
        $this->assertTrue($result['invocation_contract_ready']);
    }

    public function test_empty_string_credential_shaped_field_does_not_trigger_scan(): void
    {
        // An explicitly empty/false value means "nothing was captured" — not a violation.
        $result = $this->boundary->describe($this->safeFacts(['token' => '']));

        $this->assertSame([], $result['blockers']);
    }

    // ── AC: subscription/local clients marked optional_capability ──────────────

    public function test_capability_classification_is_optional_capability(): void
    {
        $result = $this->boundary->describe($this->safeFacts());

        $this->assertSame('optional_capability', $result['capability_classification']);
        $this->assertFalse($result['required_for_steady_state']);
    }

    public function test_required_for_steady_state_is_false_even_when_ready(): void
    {
        $result = $this->boundary->describe($this->safeFacts());

        $this->assertTrue($result['invocation_contract_ready']);
        $this->assertFalse($result['required_for_steady_state']);
    }

    // ── AC: normalized output plus runnable task proof required before trust ───

    public function test_trust_requirements_include_normalized_output_and_runnable_proof(): void
    {
        $result = $this->boundary->describe($this->safeFacts());

        $this->assertContains('normalized_output_matches_expected_output_shape', $result['trust_requirements']);
        $this->assertContains('runnable_task_proof_command_passes', $result['trust_requirements']);
    }

    public function test_trust_requirements_present_even_when_not_ready(): void
    {
        $result = $this->boundary->describe([]);

        $this->assertNotEmpty($result['trust_requirements']);
    }
}
