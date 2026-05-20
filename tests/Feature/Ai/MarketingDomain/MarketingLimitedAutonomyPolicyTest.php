<?php

namespace Tests\Feature\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\MarketingDomainCanon;
use App\Services\Ai\MarketingDomain\MarketingLimitedAutonomyPolicyService;
use Tests\TestCase;

class MarketingLimitedAutonomyPolicyTest extends TestCase
{
    public function test_policy_allows_only_internal_autonomy_with_external_gates(): void
    {
        $packet = app(MarketingLimitedAutonomyPolicyService::class)->policyPacket();

        $this->assertSame(MarketingLimitedAutonomyPolicyService::SCHEMA, $packet['schema']);
        $this->assertSame('limited_internal_autonomy', $packet['autonomy_level']);
        $this->assertSame(0, $packet['budget']['external_spend_ceiling_without_approval']);
        $this->assertFalse($packet['budget']['paid_media_spend_without_approval']);
        $this->assertContains(MarketingDomainCanon::GATE_PUBLISH, $packet['requires_human_approval']);
        $this->assertContains(MarketingDomainCanon::GATE_PAID_MEDIA, $packet['requires_human_approval']);
        $this->assertContains('any_external_publish_attempt', $packet['stop_conditions']);
        $this->assertContains('restore_previous_positioning_or_campaign_pack', $packet['rollback_plan']);
        $this->assertTrue($packet['invariants']['no_auto_publish']);
        $this->assertTrue($packet['invariants']['no_auto_spend']);
        $this->assertTrue($packet['invariants']['operator_review_required_for_external_effects']);
        $this->assertNotEmpty($packet['policy_hash']);
    }
}
