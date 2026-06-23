<?php

namespace App\Services\Ai\MarketingDomain\Campaign;

use App\Models\AiMarketingVslAsset;
use App\Models\AiMarketingWinningPattern;
use App\Services\Ai\MarketingDomain\CreativeVariationGeneratorService;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;

/**
 * DemandGenArchitectService — the demand-gen-architect skill (YouTube). Builds the Demand Gen asset
 * group from the VSL: 5 video concepts (from the creative-variation generator), audience signals,
 * tROAS bidding, and the New-Customer-Acquisition goal, plus the 4-best-practice checklist (≥3 = +40%
 * conversions). Deterministic. Closes the YouTube path the Search-only blueprint left open.
 */
class DemandGenArchitectService
{
    public function __construct(
        private readonly MarketingPlaybook $playbook = new MarketingPlaybook,
        private readonly CreativeVariationGeneratorService $variations = new CreativeVariationGeneratorService,
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $hookVariations  (optional — generated if empty)
     * @return array<string,mixed>
     */
    public function build(AiMarketingVslAsset $asset, array $hookVariations = [], ?AiMarketingWinningPattern $pattern = null): array
    {
        $dg = $this->playbook->demandGen();
        $videos = $hookVariations !== [] ? $hookVariations : $this->variations->generateHookVariations($asset, 5);
        $videos = array_slice($videos, 0, 5);

        $checklist = [
            'troas_bidding' => true,
            'consolidated_campaign' => true,
            'audience_signals' => $pattern !== null,
            'creative_volume_5_videos' => count($videos) >= 5,
        ];
        $adopted = count(array_filter($checklist));

        return [
            'skill' => 'demand-gen-architect',
            'channel' => 'youtube_demand_gen',
            'asset_group' => [
                'video_concepts' => $videos,
                'audience_signals' => $this->playbook->audienceSignals(),
                'bidding' => $this->playbook->valueBasedBidding(),
                'nca_goal' => $dg['new_customer_acquisition'],
                'proven_keywords_as_signal' => $pattern !== null
                    ? array_slice((array) $pattern->converting_keywords, 0, 20)
                    : [],
            ],
            'best_practices_checklist' => $checklist,
            'best_practices_adopted' => $adopted,
            'expected_lift' => $adopted >= 3 ? '+40% conversões (média) por adotar ≥3 das 4' : 'adotar ≥3 das 4 best-practices p/ o lift de +40%',
            'creative_is_lever_1' => $dg['creative_is_lever_1'],
        ];
    }
}
