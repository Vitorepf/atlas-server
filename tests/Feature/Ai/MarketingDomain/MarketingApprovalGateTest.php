<?php

namespace Tests\Feature\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\CampaignPlanService;
use App\Services\Ai\MarketingDomain\MarketingApprovalGateService;
use App\Services\Ai\MarketingDomain\MarketingDomainCanon;
use App\Services\Ai\MarketingDomain\MarketingRuntimeService;
use InvalidArgumentException;
use Tests\Concerns\CreatesMarketingDomainTables;
use Tests\TestCase;

class MarketingApprovalGateTest extends TestCase
{
    use CreatesMarketingDomainTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMarketingDomainTables();
    }

    protected function tearDown(): void
    {
        $this->dropMarketingDomainTables();
        parent::tearDown();
    }

    public function test_paid_media_gate_requires_proposed_budget(): void
    {
        $run = app(MarketingRuntimeService::class)->open('Atlas Vault', 'objective');
        $this->expectException(InvalidArgumentException::class);
        app(MarketingApprovalGateService::class)->request($run, [
            'gate_type' => MarketingDomainCanon::GATE_PAID_MEDIA,
            'requested_action' => 'paid_media_spend',
        ]);
    }

    public function test_gate_starts_pending_with_receipt_hash(): void
    {
        $run = app(MarketingRuntimeService::class)->open('Atlas Vault', 'objective');
        $gate = app(MarketingApprovalGateService::class)->request($run, [
            'gate_type' => MarketingDomainCanon::GATE_PUBLISH,
            'requested_action' => 'publish_campaign',
        ]);
        $this->assertSame(MarketingDomainCanon::GATE_PENDING, $gate->status);
        $this->assertSame(64, strlen((string) $gate->receipt_hash));
    }

    public function test_approve_marks_artifact_approved_and_blocks_double_decision(): void
    {
        $run = app(MarketingRuntimeService::class)->open('Atlas Vault', 'objective');
        $campaign = app(CampaignPlanService::class)->plan($run, [
            'name' => 'C', 'objective' => 'O',
            'channels' => ['email'], 'kpis' => [['name' => 'x', 'target' => 1]],
            'budget_proposed' => 5000.0,
        ]);
        $gate = app(MarketingApprovalGateService::class)->request($run, [
            'gate_type' => MarketingDomainCanon::GATE_PUBLISH,
            'artifact_id' => $campaign->id,
            'requested_action' => 'publish_campaign',
        ]);
        $approved = app(MarketingApprovalGateService::class)->approve($gate, 'operator@example.com');
        $this->assertSame(MarketingDomainCanon::GATE_APPROVED, $approved->status);
        $this->assertSame(MarketingDomainCanon::ARTIFACT_APPROVED, $campaign->fresh()->status);

        $this->expectException(InvalidArgumentException::class);
        app(MarketingApprovalGateService::class)->reject($approved, 'operator2@example.com');
    }
}
