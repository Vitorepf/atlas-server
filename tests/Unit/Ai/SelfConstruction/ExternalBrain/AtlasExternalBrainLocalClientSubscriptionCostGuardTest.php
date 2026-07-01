<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalClientSubscriptionCostGuard;
use PHPUnit\Framework\TestCase;

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

    // ── AC2: classify local subscription / included-pool / API-metered / unknown-cost ──

    public function test_local_subscription_client_classified_with_low_risk(): void
    {
        $result = $this->guard()->evaluate($this->safeFacts());

        $this->assertSame(AtlasExternalBrainLocalClientSubscriptionCostGuard::CLIENT_CLASS_LOCAL_SUBSCRIPTION, $result['client_class']);
        $this->assertSame('low', $result['risk_level']);
    }

    public function test_included_pool_client_classified_with_medium_risk(): void
    {
        $result = $this->guard()->evaluate($this->safeFacts([
            'uses_existing_subscription' => false,
            'included_in_pool' => true,
        ]));

        $this->assertSame(AtlasExternalBrainLocalClientSubscriptionCostGuard::CLIENT_CLASS_INCLUDED_POOL, $result['client_class']);
        $this->assertSame('medium', $result['risk_level']);
    }

    public function test_api_metered_client_classified_with_high_risk(): void
    {
        $result = $this->guard()->evaluate($this->safeFacts(['paid_api_required' => true]));

        $this->assertSame(AtlasExternalBrainLocalClientSubscriptionCostGuard::CLIENT_CLASS_API_METERED, $result['client_class']);
        $this->assertSame('high', $result['risk_level']);
    }

    public function test_unknown_cost_client_classified_with_critical_risk(): void
    {
        $result = $this->guard()->evaluate($this->safeFacts([
            'uses_existing_subscription' => false,
            'included_in_pool' => false,
        ]));

        $this->assertSame(AtlasExternalBrainLocalClientSubscriptionCostGuard::CLIENT_CLASS_UNKNOWN_COST, $result['client_class']);
        $this->assertSame('critical', $result['risk_level']);
    }

    // ── AC3: run policy forbidding paid-API dependence blocks hidden usage ─────

    public function test_run_policy_forbidding_paid_api_blocks_explicit_api_metered_client(): void
    {
        $result = $this->guard()->evaluate($this->safeFacts([
            'paid_api_required' => true,
            'run_policy_forbids_paid_api' => true,
        ]));

        $this->assertTrue($result['run_policy_violation']);
        $this->assertSame('blocked', $result['cost_guard_status']);
        $this->assertContains('run_policy_forbids_paid_api_dependence', $result['blockers']);
    }

    public function test_run_policy_forbidding_paid_api_also_blocks_hidden_unknown_cost_client(): void
    {
        $result = $this->guard()->evaluate($this->safeFacts([
            'uses_existing_subscription' => false,
            'run_policy_forbids_paid_api' => true,
        ]));

        $this->assertSame(AtlasExternalBrainLocalClientSubscriptionCostGuard::CLIENT_CLASS_UNKNOWN_COST, $result['client_class']);
        $this->assertTrue($result['run_policy_violation']);
        $this->assertSame('blocked', $result['cost_guard_status']);
    }

    public function test_run_policy_forbidding_paid_api_does_not_flag_local_subscription_client(): void
    {
        $result = $this->guard()->evaluate($this->safeFacts([
            'run_policy_forbids_paid_api' => true,
        ]));

        $this->assertFalse($result['run_policy_violation']);
        $this->assertSame('safe_for_24_7', $result['cost_guard_status']);
    }

    // ── AC4: fallback recommendation preserves quality, never silently pays ────

    public function test_api_metered_fallback_recommendation_never_switches_to_paid_api(): void
    {
        $result = $this->guard()->evaluate($this->safeFacts(['paid_api_required' => true]));

        $this->assertStringNotContainsString('use_paid_api', (string) $result['fallback_recommendation']);
        $this->assertStringContainsString('defer', (string) $result['fallback_recommendation']);
    }

    public function test_safe_for_24_7_fallback_recommendation_is_none_required(): void
    {
        $result = $this->guard()->evaluate($this->safeFacts());

        $this->assertSame('none_required', $result['fallback_recommendation']);
    }

    public function test_manual_use_only_fallback_recommendation_restricts_to_supervised_session(): void
    {
        $result = $this->guard()->evaluate($this->safeFacts(['quota_remaining_known' => false]));

        $this->assertStringContainsString('manual_supervised_session', (string) $result['fallback_recommendation']);
    }
}
