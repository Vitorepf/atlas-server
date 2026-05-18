<?php

namespace Tests\Feature\Ai\Policy;

use App\Models\AiApprovalRequest;
use App\Services\Ai\Policy\PolicyProfileRegistryService;
use App\Services\Ai\Policy\SafetyDecisionService;
use Tests\Concerns\CreatesPolicySafetyTables;
use Tests\TestCase;

class ApprovalRequiredTest extends TestCase
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

    public function test_marketing_publish_returns_require_approval_and_creates_request(): void
    {
        $decision = app(SafetyDecisionService::class)->decide([
            'requested_action' => 'marketing.publish',
            'gate_type' => 'permission',
            'risk_level' => 'medium',
            'domain_id' => 'marketing',
        ]);

        $this->assertSame('require_approval', $decision->decision);
        $this->assertNotEmpty($decision->receipt_hash);

        $approval = AiApprovalRequest::query()->where('requested_action', 'marketing.publish')->first();
        $this->assertNotNull($approval, 'approval request should be auto-opened');
        $this->assertSame('pending', $approval->status);
        $this->assertNotNull($approval->expires_at);
    }
}
