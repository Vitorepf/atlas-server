<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolQuotaBoundaryLedger;
use Tests\TestCase;

final class AtlasExternalBrainProviderPoolQuotaBoundaryLedgerTest extends TestCase
{
    private function ledger(): AtlasExternalBrainProviderPoolQuotaBoundaryLedger
    {
        return new AtlasExternalBrainProviderPoolQuotaBoundaryLedger;
    }

    private function reliableFacts(array $overrides = []): array
    {
        return array_merge([
            'provider_id' => 'cursor',
            'plan_name' => 'cursor-pro',
            'pool_kind' => 'subscription_seat',
            'uses_existing_subscription' => true,
            'paid_api_required' => false,
            'reset_window' => 'daily_00_00_utc',
            'hard_limit_known' => true,
            'soft_throttle_known' => true,
            'estimated_remaining' => 120,
            'observed_exhaustion' => false,
            'billing_boundary_known' => true,
            'fallback_pool_available' => true,
        ], $overrides);
    }

    public function test_records_all_quota_facts(): void
    {
        $entry = $this->ledger()->record($this->reliableFacts());

        foreach ([
            'provider_id', 'plan_name', 'pool_kind', 'uses_existing_subscription',
            'paid_api_required', 'reset_window', 'hard_limit_known', 'soft_throttle_known',
            'estimated_remaining', 'observed_exhaustion', 'billing_boundary_known',
        ] as $field) {
            $this->assertArrayHasKey($field, $entry, "missing field: {$field}");
        }
    }

    public function test_fully_known_subscription_quota_is_reliable_for_24_7(): void
    {
        $entry = $this->ledger()->record($this->reliableFacts());

        $this->assertTrue($entry['quota_reliable_for_24_7']);
        $this->assertSame([], $entry['unreliable_reasons']);
        $this->assertSame('reliable_within_known_boundary', $entry['routing_budget_status']);
        $this->assertSame('low', $entry['risk_level']);
    }

    public function test_paid_api_required_marks_unreliable(): void
    {
        $entry = $this->ledger()->record($this->reliableFacts(['paid_api_required' => true]));

        $this->assertFalse($entry['quota_reliable_for_24_7']);
        $this->assertContains('paid_api_required_has_no_atlas_controlled_ceiling', $entry['unreliable_reasons']);
        $this->assertTrue($entry['fallback_required']);
    }

    public function test_missing_hard_limit_marks_unreliable(): void
    {
        $entry = $this->ledger()->record($this->reliableFacts(['hard_limit_known' => false]));

        $this->assertFalse($entry['quota_reliable_for_24_7']);
        $this->assertContains('hard_limit_unknown', $entry['unreliable_reasons']);
    }

    public function test_missing_reset_window_marks_unreliable(): void
    {
        $entry = $this->ledger()->record($this->reliableFacts(['reset_window' => '']));

        $this->assertFalse($entry['quota_reliable_for_24_7']);
        $this->assertContains('reset_window_unknown', $entry['unreliable_reasons']);
    }

    public function test_missing_billing_boundary_proof_marks_unreliable(): void
    {
        $entry = $this->ledger()->record($this->reliableFacts(['billing_boundary_known' => false]));

        $this->assertFalse($entry['quota_reliable_for_24_7']);
        $this->assertContains('billing_boundary_unknown_no_entitlement_proof', $entry['unreliable_reasons']);
    }

    public function test_missing_fallback_capacity_marks_unreliable(): void
    {
        $entry = $this->ledger()->record($this->reliableFacts(['fallback_pool_available' => false]));

        $this->assertFalse($entry['quota_reliable_for_24_7']);
        $this->assertContains('fallback_capacity_unavailable', $entry['unreliable_reasons']);
    }

    public function test_observed_exhaustion_routes_to_high_risk_and_exhausted_status(): void
    {
        $entry = $this->ledger()->record($this->reliableFacts(['observed_exhaustion' => true]));

        $this->assertSame('exhausted_route_elsewhere', $entry['routing_budget_status']);
        $this->assertSame('high', $entry['risk_level']);
        $this->assertTrue($entry['fallback_required']);
        $this->assertContains('exhaustion_already_observed', $entry['fallback_required_reasons']);
    }

    public function test_never_makes_network_calls_reads_secrets_requires_paid_api_or_persists_account_data(): void
    {
        $entry = $this->ledger()->record($this->reliableFacts());

        $this->assertFalse($entry['network_calls_made']);
        $this->assertFalse($entry['billing_secrets_read']);
        $this->assertFalse($entry['paid_api_usage_required_to_record']);
        $this->assertFalse($entry['account_data_persisted']);
    }
}
