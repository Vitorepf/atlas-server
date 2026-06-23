<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\CampaignPlanService;
use App\Services\Ai\MarketingDomain\CopyBriefService;
use App\Services\Ai\MarketingDomain\CreativeBriefService;
use App\Services\Ai\MarketingDomain\Decision\MarketingActionPlaybookResolver;
use App\Services\Ai\MarketingDomain\FunnelPlanService;
use App\Services\Ai\MarketingDomain\ICPPositioningService;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;
use PHPUnit\Framework\TestCase;

/**
 * Decision → execution: every recommended action resolves to the deterministic skill blueprint
 * that tells the operator HOW to do it.
 */
class MarketingActionPlaybookResolverTest extends TestCase
{
    private function resolver(): MarketingActionPlaybookResolver
    {
        return new MarketingActionPlaybookResolver(
            new CopyBriefService,
            new CreativeBriefService,
            new FunnelPlanService,
            new ICPPositioningService,
            new CampaignPlanService,
        );
    }

    public function test_edit_hook_resolves_to_vsl_architect_brief_with_anatomy(): void
    {
        $r = $this->resolver()->resolve(MarketingPlaybook::ACTION_EDIT_HOOK, 'problem_aware');

        $this->assertSame('vsl-architect', $r['skill']);
        $this->assertArrayHasKey('vsl_anatomy', $r['blueprint']);
    }

    public function test_strengthen_close_resolves_to_offer_doctor(): void
    {
        $r = $this->resolver()->resolve(MarketingPlaybook::ACTION_STRENGTHEN_CLOSE, 'most_aware');

        $this->assertStringContainsString('offer-doctor', $r['skill']);
        $this->assertArrayHasKey('value_equation', $r['blueprint']);
    }

    public function test_bid_and_audience_actions_resolve_to_traffic_brief(): void
    {
        foreach ([
            MarketingPlaybook::ACTION_RAISE_BID,
            MarketingPlaybook::ACTION_LOWER_BID,
            MarketingPlaybook::ACTION_OPEN_AUDIENCE,
            MarketingPlaybook::ACTION_CLOSE_AUDIENCE,
        ] as $action) {
            $r = $this->resolver()->resolve($action);
            $this->assertArrayHasKey('account_structures', $r['blueprint'], "action {$action} should carry the traffic brief");
        }
    }

    public function test_edit_bridge_headline_resolves_to_funnel_brief(): void
    {
        $r = $this->resolver()->resolve(MarketingPlaybook::ACTION_EDIT_BRIDGE_HEADLINE);
        $this->assertArrayHasKey('message_match', $r['blueprint']);
    }

    public function test_hold_has_no_execution_playbook(): void
    {
        $this->assertNull($this->resolver()->resolve('hold'));
        $this->assertNull($this->resolver()->resolve('unknown_action'));
    }
}
