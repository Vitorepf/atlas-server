<?php

namespace Tests\Feature\Ai\Policy;

use App\Models\AiApprovalRequest;
use App\Services\Ai\Policy\PolicyProfileRegistryService;
use App\Services\Ai\Policy\SafetyDecisionService;
use Tests\Concerns\CreatesPolicySafetyTables;
use Tests\TestCase;

class MarketingPublishApprovalTest extends TestCase
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

    public function test_paid_media_spend_requires_approval(): void
    {
        $decision = app(SafetyDecisionService::class)->decide([
            'requested_action' => 'marketing.paid_media_spend',
            'gate_type' => 'permission',
            'risk_level' => 'medium',
            'domain_id' => 'marketing',
        ]);

        $this->assertSame('require_approval', $decision->decision);
        $approval = AiApprovalRequest::query()->where('requested_action', 'marketing.paid_media_spend')->first();
        $this->assertNotNull($approval);
        $this->assertSame('pending', $approval->status);
    }

    public function test_send_email_requires_approval(): void
    {
        $decision = app(SafetyDecisionService::class)->decide([
            'requested_action' => 'marketing.send_email',
            'gate_type' => 'permission',
            'risk_level' => 'medium',
            'domain_id' => 'marketing',
        ]);

        $this->assertSame('require_approval', $decision->decision);
    }
}
