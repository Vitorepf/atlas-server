<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolSafetyRunner;
use PHPUnit\Framework\TestCase;

/**
 * Proves the ProviderPoolSafetyRunner gates a pool on credential safety,
 * quota headroom, smoke test completeness, and output normalization.
 *
 * AC1: a credential-leaking pool is refused.
 * AC2: an over-quota pool is refused.
 * AC3: a safe pool passing smoke test is admitted.
 */
final class AtlasExternalBrainProviderPoolSafetyRunnerTest extends TestCase
{
    private AtlasExternalBrainProviderPoolSafetyRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new AtlasExternalBrainProviderPoolSafetyRunner;
    }

    public function test_credential_leaking_pool_is_refused(): void
    {
        // Credential value present → raw_secret_in_payload blocker.
        $result = $this->runner->run([
            'credential_facts' => [
                'credential_value_present' => true,
            ],
        ]);

        $this->assertFalse($result['safe'], 'credential-leaking pool must be refused');
        $this->assertContains('credential:raw_secret_in_payload', $result['reasons']);
    }

    public function test_over_quota_pool_is_refused(): void
    {
        // Observed exhaustion → quota exhausted blocker.
        $result = $this->runner->run([
            'credential_facts' => [
                'local_client_logged_in' => false,
                'subscription_entitlement_observed' => false,
                'redaction_status' => false,
                'environment_scope' => false,
                'operator_attested_entitlement' => false,
            ],
            'quota_facts' => [
                'observed_exhaustion' => true,
            ],
        ]);

        $this->assertFalse($result['safe'], 'over-quota pool must be refused');
        $this->assertContains('quota:exhausted', $result['reasons']);
    }

    public function test_safe_pool_passing_smoke_test_is_admitted(): void
    {
        // All safety gates green: credential safe, quota reliable, smoke ready.
        $result = $this->runner->run([
            'credential_facts' => [
                'local_client_logged_in' => true,
                'subscription_entitlement_observed' => true,
                'credential_value_present' => false,
                'redaction_status' => true,
                'environment_scope' => true,
                'operator_attested_entitlement' => true,
            ],
            'quota_facts' => [
                'hard_limit_known' => true,
                'reset_window' => 'monthly',
                'billing_boundary_known' => true,
                'fallback_pool_available' => true,
            ],
            'smoke_evidence' => [
                'sdk_proof' => true,
                'entitlement_proof' => true,
                'output_contract_proof' => true,
                'cost_proof' => true,
                'fallback_proof' => true,
            ],
        ]);

        $this->assertTrue($result['safe'], 'safe pool must be admitted');
        $this->assertSame([], $result['reasons'], 'no reasons for a safe pool');
        $this->assertTrue($result['credential_safety']['safe_to_probe']);
        $this->assertTrue($result['quota_boundary']['quota_reliable_for_24_7']);
        $this->assertSame('ready_for_optional_routing', $result['smoke_plan']['status']);
    }
}
