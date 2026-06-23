<?php

namespace App\Services\Ai\MarketingDomain\Decision;

use App\Services\Ai\MarketingDomain\CampaignPlanService;
use App\Services\Ai\MarketingDomain\CopyBriefService;
use App\Services\Ai\MarketingDomain\CreativeBriefService;
use App\Services\Ai\MarketingDomain\FunnelPlanService;
use App\Services\Ai\MarketingDomain\ICPPositioningService;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;

/**
 * Closes decision → execution. A recommended action ("edit_hook") is only half the value; this
 * resolver attaches the deterministic skill blueprint that says HOW to execute it (the vsl-architect
 * hook checklist, the message-match rules, the Grand Slam stack, …). Same action → same playbook.
 */
class MarketingActionPlaybookResolver
{
    public function __construct(
        private readonly CopyBriefService $copy,
        private readonly CreativeBriefService $creative,
        private readonly FunnelPlanService $funnel,
        private readonly ICPPositioningService $icp,
        private readonly CampaignPlanService $campaign,
    ) {}

    /**
     * @return array<string,mixed>|null  {skill, blueprint} for the action, or null (e.g. HOLD)
     */
    public function resolve(string $action, ?string $awareness = null): ?array
    {
        return match ($action) {
            MarketingPlaybook::ACTION_EDIT_HOOK,
            MarketingPlaybook::ACTION_REFRESH_VSL,
            MarketingPlaybook::ACTION_EDIT_VSL_HEADLINE => $this->wrap('vsl-architect', $this->copy->blueprint($awareness)),

            MarketingPlaybook::ACTION_EDIT_BRIDGE_HEADLINE => $this->wrap('bridge-builder + page-architect', $this->funnel->blueprint('cold')),

            MarketingPlaybook::ACTION_STRENGTHEN_CLOSE => $this->wrap('offer-doctor + grand-slam-builder', $this->icp->blueprint($awareness)),

            MarketingPlaybook::ACTION_RAISE_BID,
            MarketingPlaybook::ACTION_LOWER_BID,
            MarketingPlaybook::ACTION_OPEN_AUDIENCE,
            MarketingPlaybook::ACTION_CLOSE_AUDIENCE => $this->wrap('bidding-strategist + keyword-intent-mapper', $this->campaign->blueprint('google_search')),

            default => null, // hold / unknown → no execution playbook (gather data)
        };
    }

    /**
     * The creative-refresh action can also pull the video-ad playbook (YouTube).
     *
     * @return array<string,mixed>
     */
    public function videoRefresh(): array
    {
        return $this->wrap('video-ad-architect + creative-pipeline', $this->creative->blueprint());
    }

    /**
     * @param  array<string,mixed>  $blueprint
     * @return array<string,mixed>
     */
    private function wrap(string $skill, array $blueprint): array
    {
        return ['skill' => $skill, 'blueprint' => $blueprint];
    }
}
