<?php

namespace Tests\Feature\Ai\Policy;

use App\Services\Ai\Policy\PolicyProfileRegistryService;
use App\Services\Ai\Policy\SafetyDecisionService;
use Tests\Concerns\CreatesPolicySafetyTables;
use Tests\TestCase;

class SafetyDecisionReceiptTest extends TestCase
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

    public function test_safety_decision_generates_deterministic_receipt_hash(): void
    {
        $service = app(SafetyDecisionService::class);
        $request = [
            'requested_action' => 'programming.read',
            'gate_type' => 'permission',
            'risk_level' => 'low',
            'domain_id' => 'programming',
        ];

        $a = $service->decide($request);
        $b = $service->decide($request);

        $this->assertNotEmpty($a->receipt_hash);
        $this->assertNotEmpty($b->receipt_hash);
        $this->assertSame(64, strlen((string) $a->receipt_hash));
    }

    public function test_safety_decision_records_policy_profile_link_when_match(): void
    {
        $decision = app(SafetyDecisionService::class)->decide([
            'requested_action' => 'finance.research',
            'gate_type' => 'permission',
            'risk_level' => 'low',
            'domain_id' => 'finance',
        ]);

        $this->assertNotNull($decision->policy_profile_id);
        $this->assertContains($decision->decision, ['allow', 'deny', 'require_approval', 'blocked']);
    }
}
