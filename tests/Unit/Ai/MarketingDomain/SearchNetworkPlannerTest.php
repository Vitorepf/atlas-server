<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Campaign\SearchNetworkPlanner;
use PHPUnit\Framework\TestCase;

/**
 * Locks the search-network wiring: a dissected VSL becomes a real Google Search plan — ad groups by
 * intent (from the VSL's own clusters), match-type hints, a curated negative list that blocks
 * non-buying clicks, and a match mix. Before this the engine pieces existed but nothing fed them the
 * VSL. Deterministic.
 */
class SearchNetworkPlannerTest extends TestCase
{
    private function asset(): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset([
            'target_geo' => 'US / English',
            'keywords' => ['clusters' => [
                ['name' => 'Lipo Bliss Brand', 'awareness' => 'product_aware', 'terms' => ['lipo bliss', 'buy lipo bliss']],
                ['name' => 'Triple Hormone Drops', 'awareness' => 'solution_aware', 'terms' => ['triple hormone drops', 'retatrutide alternative']],
            ]],
        ]);
    }

    public function test_builds_ad_groups_from_the_vsl_clusters(): void
    {
        $plan = (new SearchNetworkPlanner)->plan($this->asset());

        $this->assertCount(2, $plan['ad_groups']);
        $names = array_column($plan['ad_groups'], 'name');
        $this->assertContains('Lipo Bliss Brand', $names);
        foreach ($plan['ad_groups'] as $g) {
            $this->assertArrayHasKey('intent_bucket', $g);
            $this->assertArrayHasKey('match_type_hint', $g);
            $this->assertNotEmpty($g['terms']);
        }
    }

    public function test_includes_a_curated_negative_list_of_non_buying_clicks(): void
    {
        $neg = (new SearchNetworkPlanner)->plan($this->asset())['negatives'];

        $this->assertGreaterThanOrEqual(15, $neg['count']);
        $this->assertContains('free', $neg['campaign_negatives']);
        $this->assertContains('recipe', $neg['campaign_negatives']);
        $this->assertContains('jobs', $neg['campaign_negatives']);
    }

    public function test_has_a_match_mix(): void
    {
        $plan = (new SearchNetworkPlanner)->plan($this->asset());
        $this->assertArrayHasKey('match_mix', $plan);
    }
}
