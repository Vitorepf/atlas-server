<?php

namespace Tests\Feature\Ai\Policy;

use App\Models\AiForbiddenAction;
use App\Models\AiPolicyProfile;
use App\Services\Ai\Policy\PolicyProfileRegistryService;
use Tests\Concerns\CreatesPolicySafetyTables;
use Tests\TestCase;

class PolicySeedDefaultsTest extends TestCase
{
    use CreatesPolicySafetyTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPolicySafetyTables();
    }

    protected function tearDown(): void
    {
        $this->dropPolicySafetyTables();
        parent::tearDown();
    }

    public function test_seed_defaults_creates_nine_canonical_profiles(): void
    {
        $seeded = app(PolicyProfileRegistryService::class)->seedDefaults();

        $this->assertSame(9, $seeded->count());
        foreach ([
            'global.default',
            'programming.default',
            'finance.research_only',
            'finance.live_trade_blocked_by_default',
            'cyber.defensive_only',
            'cyber.offensive_requires_authorization',
            'marketing.publish_requires_approval',
            'automation.external_action_requires_policy',
            'tool.external_cost_requires_approval',
        ] as $policyId) {
            $this->assertNotNull(
                AiPolicyProfile::query()->where('policy_id', $policyId)->first(),
                "missing default profile [{$policyId}]"
            );
        }
    }

    public function test_seed_defaults_is_idempotent(): void
    {
        $registry = app(PolicyProfileRegistryService::class);
        $first = $registry->seedDefaults();
        $second = $registry->seedDefaults();

        $this->assertSame(9, $first->count());
        $this->assertSame(9, $second->count());
        $this->assertSame(9, AiPolicyProfile::query()->count());
    }

    public function test_seed_defaults_creates_hard_forbidden_actions(): void
    {
        app(PolicyProfileRegistryService::class)->seedDefaults();

        foreach ([
            'finance.execute_trade',
            'finance.transfer_funds',
            'finance.live_trade',
            'finance.broker_order',
            'cyber.offensive_without_authorization',
            'cyber.exploit_without_scope',
            'automation.anti_bot_bypass',
        ] as $key) {
            $this->assertNotNull(
                AiForbiddenAction::query()->where('action_key', $key)->first(),
                "missing forbidden action [{$key}]"
            );
        }
    }
}
