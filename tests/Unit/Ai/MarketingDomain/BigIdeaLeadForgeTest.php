<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\BigIdeaLeadForge;
use App\Services\Ai\MarketingDomain\Content\WatchThroughLeakDetector;
use PHPUnit\Framework\TestCase;

/**
 * BigIdeaLeadForge — originates the lead (the biggest conversion multiplier). Grounded in the asset's
 * wound/dream/enemy/promise, routed to awareness, opens a curiosity loop, HOLDS the mechanism (no reveal).
 */
class BigIdeaLeadForgeTest extends TestCase
{
    public function test_routes_archetype_to_awareness(): void
    {
        $f = new BigIdeaLeadForge;
        $this->assertSame('story', $f->forge($this->asset('weight loss', 'unaware'))['best_archetype']);
        $this->assertSame('problem_agitate', $f->forge($this->asset('finance', 'problem_aware'))['best_archetype']);
        $this->assertSame('proclamation', $f->forge($this->asset('relationship', 'most_aware'))['best_archetype']);
    }

    public function test_lead_holds_the_mechanism_no_watch_through_leak(): void
    {
        $f = new BigIdeaLeadForge;
        $leaks = new WatchThroughLeakDetector;
        foreach (['weight loss', 'finance', 'relationship'] as $niche) {
            foreach (['unaware', 'problem_aware', 'most_aware'] as $aw) {
                $best = $f->forge($this->asset($niche, $aw))['best'];
                $this->assertSame([], $leaks->detect($best)['flaws'], "lead for {$niche}/{$aw} must not leak the reveal");
            }
        }
    }

    public function test_lead_is_grounded_in_the_niche_enemy_and_promise(): void
    {
        $r = (new BigIdeaLeadForge)->forge($this->asset('finance', 'problem_aware', 'grow your money'));
        $this->assertStringContainsStringIgnoringCase('Wall Street', $r['best']);
    }

    public function test_offers_multiple_archetype_variants(): void
    {
        $leads = (new BigIdeaLeadForge)->forge($this->asset('weight loss', 'unaware'))['leads'];
        $this->assertGreaterThanOrEqual(2, count($leads));
        foreach ($leads as $l) {
            $this->assertNotEmpty($l['text']);
        }
    }

    private function asset(string $niche, string $awareness, string $promise = 'a real change'): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset(['niche' => $niche, 'awareness_level' => $awareness, 'core_promise' => $promise]);
    }
}
