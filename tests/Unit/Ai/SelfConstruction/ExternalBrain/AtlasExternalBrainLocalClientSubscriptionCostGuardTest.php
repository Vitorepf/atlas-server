<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalClientSubscriptionCostGuard;
use Tests\TestCase;

final class AtlasExternalBrainLocalClientSubscriptionCostGuardTest extends TestCase
{
    private AtlasExternalBrainLocalClientSubscriptionCostGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new AtlasExternalBrainLocalClientSubscriptionCostGuard();
    }

    private function facts(array $overrides = []): array
    {
        return array_merge([
            'uses_existing_subscription' => false,
            'paid_api_required' => false,
            'quota_remaining_known' => false,
            'soft_throttle_observed' => false,
            'hard_limit_known' => false,
            'reset_window_known' => false,
            'fallback_available' => false,
            'included_in_pool' => false,
            'run_policy_forbids_paid_api' => false,
        ], $overrides);
    }

    // ── Schema ───────────────────────────────────────────────────────────────────

    public function test_schema_constant(): void
    {
        $this->assertSame('atlas.external_brain.local_client_subscription_cost_guard.v1', AtlasExternalBrainLocalClientSubscriptionCostGuard::SCHEMA);
    }

    // ── Status constants ─────────────────────────────────────────────────────────

    public function test_status_constants(): void
    {
        $this->assertSame('safe_for_24_7', AtlasExternalBrainLocalClientSubscriptionCostGuard::STATUS_SAFE_FOR_24_7);
        $this->assertSame('safe_for_manual_use_only', AtlasExternalBrainLocalClientSubscriptionCostGuard::STATUS_SAFE_FOR_MANUAL_USE_ONLY);
        $this->assertSame('blocked', AtlasExternalBrainLocalClientSubscriptionCostGuard::STATUS_BLOCKED);
    }

    // ── Blocked when paid API required ───────────────────────────────────────────

    public function test_blocked_when_paid_api_required(): void
    {
        $result = $this->guard->evaluate($this->facts(['paid_api_required' => true]));
        $this->assertSame('blocked', $result['cost_guard_status']);
        $this->assertFalse($result['safe_for_manual_use']);
        $this->assertFalse($result['safe_for_24_7']);
    }

    // ── Safe for 24/7 when all boundaries known ─────────────────────────────────

    public function test_safe_for_24_7_when_all_boundaries_known(): void
    {
        $result = $this->guard->evaluate($this->facts([
            'uses_existing_subscription' => true,
            'quota_remaining_known' => true,
            'hard_limit_known' => true,
            'reset_window_known' => true,
        ]));
        $this->assertSame('safe_for_24_7', $result['cost_guard_status']);
        $this->assertTrue($result['safe_for_manual_use']);
        $this->assertTrue($result['safe_for_24_7']);
    }

    // ── Safe for manual use only when some boundaries unknown ────────────────────

    public function test_safe_for_manual_use_only_when_quota_unknown(): void
    {
        $result = $this->guard->evaluate($this->facts([
            'uses_existing_subscription' => true,
            'hard_limit_known' => true,
            'reset_window_known' => true,
        ]));
        $this->assertSame('safe_for_manual_use_only', $result['cost_guard_status']);
        $this->assertTrue($result['safe_for_manual_use']);
        $this->assertFalse($result['safe_for_24_7']);
    }

    // ── Client classification ────────────────────────────────────────────────────

    public function test_client_class_api_metered_when_paid_api_required(): void
    {
        $result = $this->guard->evaluate($this->facts(['paid_api_required' => true]));
        $this->assertSame('api_metered', $result['client_class']);
        $this->assertSame('high', $result['risk_level']);
    }

    public function test_client_class_local_subscription_when_uses_existing(): void
    {
        $result = $this->guard->evaluate($this->facts(['uses_existing_subscription' => true]));
        $this->assertSame('local_subscription', $result['client_class']);
        $this->assertSame('low', $result['risk_level']);
    }

    public function test_client_class_included_pool_when_in_pool(): void
    {
        $result = $this->guard->evaluate($this->facts(['included_in_pool' => true]));
        $this->assertSame('included_pool', $result['client_class']);
        $this->assertSame('medium', $result['risk_level']);
    }

    public function test_client_class_unknown_cost_when_no_signals(): void
    {
        $result = $this->guard->evaluate($this->facts());
        $this->assertSame('unknown_cost', $result['client_class']);
        $this->assertSame('critical', $result['risk_level']);
    }

    // ── Run policy violation ─────────────────────────────────────────────────────

    public function test_run_policy_violation_when_api_metered_and_forbidden(): void
    {
        $result = $this->guard->evaluate($this->facts([
            'paid_api_required' => true,
            'run_policy_forbids_paid_api' => true,
        ]));
        $this->assertTrue($result['run_policy_violation']);
        $this->assertSame('blocked', $result['cost_guard_status']);
    }

    public function test_run_policy_violation_when_unknown_cost_and_forbidden(): void
    {
        $result = $this->guard->evaluate($this->facts([
            'run_policy_forbids_paid_api' => true,
        ]));
        $this->assertTrue($result['run_policy_violation']);
        $this->assertSame('blocked', $result['cost_guard_status']);
    }

    public function test_no_run_policy_violation_when_local_subscription(): void
    {
        $result = $this->guard->evaluate($this->facts([
            'uses_existing_subscription' => true,
            'run_policy_forbids_paid_api' => true,
        ]));
        $this->assertFalse($result['run_policy_violation']);
    }

    // ── Fallback recommendation ──────────────────────────────────────────────────

    public function test_fallback_none_when_safe_for_24_7(): void
    {
        $result = $this->guard->evaluate($this->facts([
            'uses_existing_subscription' => true,
            'quota_remaining_known' => true,
            'hard_limit_known' => true,
            'reset_window_known' => true,
        ]));
        $this->assertSame('none_required', $result['fallback_recommendation']);
    }

    public function test_fallback_restrict_when_manual_use_only(): void
    {
        $result = $this->guard->evaluate($this->facts([
            'uses_existing_subscription' => true,
            'hard_limit_known' => true,
            'reset_window_known' => true,
        ]));
        $this->assertSame('restrict_to_manual_supervised_session_until_quota_boundaries_known', $result['fallback_recommendation']);
    }

    // ── Blockers ─────────────────────────────────────────────────────────────────

    public function test_blockers_include_unknown_boundaries(): void
    {
        $result = $this->guard->evaluate($this->facts(['uses_existing_subscription' => true]));
        $this->assertContains('quota_remaining_unknown', $result['blockers']);
        $this->assertContains('hard_limit_unknown', $result['blockers']);
        $this->assertContains('reset_window_unknown', $result['blockers']);
    }

    // ── No billing/network calls ─────────────────────────────────────────────────

    public function test_no_billing_data_read(): void
    {
        $result = $this->guard->evaluate($this->facts());
        $this->assertFalse($result['billing_data_read']);
        $this->assertFalse($result['network_calls_made']);
    }

    // ── Determinism ──────────────────────────────────────────────────────────────

    public function test_result_is_deterministic(): void
    {
        $facts = $this->facts(['uses_existing_subscription' => true]);
        $this->assertSame($this->guard->evaluate($facts), $this->guard->evaluate($facts));
    }
}
