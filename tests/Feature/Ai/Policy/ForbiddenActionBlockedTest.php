<?php

namespace Tests\Feature\Ai\Policy;

use App\Services\Ai\Policy\ForbiddenActionService;
use App\Services\Ai\Policy\PolicyProfileRegistryService;
use App\Services\Ai\Policy\SafetyDecisionService;
use Tests\Concerns\CreatesPolicySafetyTables;
use Tests\TestCase;

class ForbiddenActionBlockedTest extends TestCase
{
    use CreatesPolicySafetyTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPolicySafetyTables();
        app(PolicyProfileRegistryService::class)->seedDefaults();
    }

    protected function tearDown(): void
    {
        $this->dropPolicySafetyTables();
        parent::tearDown();
    }

    public function test_explicitly_blocked_action_returns_blocked(): void
    {
        $forbidden = app(ForbiddenActionService::class);
        $forbidden->block(
            'integration.delete_production_db',
            'destructive production action, hard block',
            severity: 'critical',
        );

        $decision = app(SafetyDecisionService::class)->decide([
            'requested_action' => 'integration.delete_production_db',
            'gate_type' => 'permission',
            'risk_level' => 'critical',
        ]);

        $this->assertSame('blocked', $decision->decision);
        $this->assertContains('forbidden_action_registered:integration.delete_production_db', $decision->reasons);
    }

    public function test_seeded_finance_live_trade_is_blocked_by_default(): void
    {
        $decision = app(SafetyDecisionService::class)->decide([
            'requested_action' => 'finance.live_trade',
            'gate_type' => 'permission',
            'risk_level' => 'high',
            'domain_id' => 'finance',
        ]);

        $this->assertSame('blocked', $decision->decision);
    }
}
