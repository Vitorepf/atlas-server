<?php

namespace Tests\Feature\Ai\Policy;

use App\Services\Ai\Policy\PolicyProfileRegistryService;
use App\Services\Ai\Policy\SafetyDecisionService;
use Tests\Concerns\CreatesPolicySafetyTables;
use Tests\TestCase;

class LowRiskAllowTest extends TestCase
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

    public function test_low_risk_programming_read_action_is_allowed(): void
    {
        $decision = app(SafetyDecisionService::class)->decide([
            'requested_action' => 'programming.read',
            'gate_type' => 'permission',
            'risk_level' => 'low',
            'domain_id' => 'programming',
        ]);

        $this->assertSame('allow', $decision->decision);
        $this->assertNotEmpty($decision->receipt_hash);
        $this->assertContains('policy_profile_allows', $decision->reasons);
    }
}
