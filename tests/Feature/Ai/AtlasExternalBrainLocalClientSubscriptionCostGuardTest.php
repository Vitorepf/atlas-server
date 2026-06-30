<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalClientSubscriptionCostGuard;
use Tests\TestCase;

final class AtlasExternalBrainLocalClientSubscriptionCostGuardTest extends TestCase
{
    private function guard(): AtlasExternalBrainLocalClientSubscriptionCostGuard
    {
        return new AtlasExternalBrainLocalClientSubscriptionCostGuard;
    }

    private function safeFacts(array $overrides = []): array
    {
        return array_merge([
            'uses_existing_subscription' => true,
            'paid_api_required' => false,
            'quota_remaining_known' => true,
            'soft_throttle_observed' => false,
            'hard_limit_known' => true,
            'reset_window_known' => true,
            'fallback_available' => true,
        ], $overrides);
    }

    public function test_evaluates_all_required_facts(): void
    {
        $result = $this->guard()->evaluate($this->safeFacts());

        foreach ([
            'uses_existing_subscription', 'paid_api_required', 'quota_remaining_known',
            'soft_throttle_observed', 'hard_limit_known', 'reset_window_known', 'fallback_available',
        ] as $field) {
            $this->assertArrayHasKey($field, $result, "missing field: {$field}");
        }
    }

    public function test_fully_known_boundaries_are_safe_for_24_7(): void
    {
        $result = $this->guard()->evaluate($this->safeFacts());

        $this->assertSame('safe_for_24_7', $result['cost_guard_status']);
        $this->assertTrue($result['safe_for_manual_use']);
        $this->assertTrue($result['safe_for_24_7']);
        $this->assertFalse($result['fallback_required']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_paid_api_required_blocks_autonomous_routing(): void
    {
        $result = $this->guard()->evaluate($this->safeFacts(['paid_api_required' => true]));

        $this->assertSame('blocked', $result['cost_guard_status']);
        $this->assertFalse($result['safe_for_manual_use']);
        $this->assertFalse($result['safe_for_24_7']);
        $this->assertContains('paid_api_required', $result['blockers']);
    }

    public function test_unknown_quota_boundary_blocks_24_7_but_allows_manual_use(): void
    {
        $result = $this->guard()->evaluate($this->safeFacts(['quota_remaining_known' => false]));

        $this->assertSame('safe_for_manual_use_only', $result['cost_guard_status']);
        $this->assertTrue($result['safe_for_manual_use']);
        $this->assertFalse($result['safe_for_24_7']);
        $this->assertTrue($result['fallback_required']);
        $this->assertContains('quota_remaining_unknown', $result['blockers']);
    }

    public function test_unknown_hard_limit_blocks_24_7(): void
    {
        $result = $this->guard()->evaluate($this->safeFacts(['hard_limit_known' => false]));

        $this->assertFalse($result['safe_for_24_7']);
        $this->assertContains('hard_limit_unknown', $result['blockers']);
    }

    public function test_unknown_reset_window_blocks_24_7(): void
    {
        $result = $this->guard()->evaluate($this->safeFacts(['reset_window_known' => false]));

        $this->assertFalse($result['safe_for_24_7']);
        $this->assertContains('reset_window_unknown', $result['blockers']);
    }

    public function test_no_fallback_available_when_unsafe_for_24_7_adds_blocker(): void
    {
        $result = $this->guard()->evaluate($this->safeFacts([
            'quota_remaining_known' => false,
            'fallback_available' => false,
        ]));

        $this->assertContains('no_fallback_available_for_unsafe_24_7_routing', $result['blockers']);
    }

    public function test_never_reads_billing_data_or_makes_network_calls(): void
    {
        $result = $this->guard()->evaluate($this->safeFacts());

        $this->assertFalse($result['billing_data_read']);
        $this->assertFalse($result['network_calls_made']);
    }
}
