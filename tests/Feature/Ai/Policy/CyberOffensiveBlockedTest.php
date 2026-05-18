<?php

namespace Tests\Feature\Ai\Policy;

use App\Services\Ai\Policy\PolicyProfileRegistryService;
use App\Services\Ai\Policy\SafetyDecisionService;
use Tests\Concerns\CreatesPolicySafetyTables;
use Tests\TestCase;

class CyberOffensiveBlockedTest extends TestCase
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

    public function test_cyber_offensive_without_authorization_is_blocked(): void
    {
        $decision = app(SafetyDecisionService::class)->decide([
            'requested_action' => 'cyber.offensive_without_authorization',
            'gate_type' => 'permission',
            'risk_level' => 'critical',
            'domain_id' => 'cyber',
        ]);

        $this->assertSame('blocked', $decision->decision);
    }

    public function test_cyber_exploit_without_scope_is_blocked(): void
    {
        $decision = app(SafetyDecisionService::class)->decide([
            'requested_action' => 'cyber.exploit_without_scope',
            'gate_type' => 'permission',
            'risk_level' => 'critical',
            'domain_id' => 'cyber',
        ]);

        $this->assertSame('blocked', $decision->decision);
    }
}
