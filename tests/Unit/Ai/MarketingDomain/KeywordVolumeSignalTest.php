<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordVolumeSignal;
use PHPUnit\Framework\TestCase;

/**
 * Locks the L5 volume signal: ingests Keyword Planner demand (operator-supplied CSV, no live spend),
 * prioritizes by demanda × intenção WITHOUT killing a high-score low-volume hero (demand modula, não veta),
 * and stays HONEST (basis=unknown without data — never fabricates volume).
 */
class KeywordVolumeSignalTest extends TestCase
{
    private KeywordVolumeSignal $v;

    protected function setUp(): void
    {
        $this->v = new KeywordVolumeSignal;
    }

    public function test_assess_unknown_without_data_never_fabricates(): void
    {
        $r = $this->v->assess('blue salt trick');
        $this->assertNull($r['volume']);
        $this->assertSame('unknown', $r['demand_tier']);
        $this->assertSame('unknown', $r['basis']);
    }

    public function test_assess_demand_tiers_from_provided_planner_data(): void
    {
        $map = [
            'big' => ['searches' => 5000, 'top_bid' => 4.2],
            'mid' => ['searches' => 300],
            'small' => ['searches' => 20],
        ];
        $this->assertSame('high', $this->v->assess('big', $map)['demand_tier']);
        $this->assertSame('medium', $this->v->assess('mid', $map)['demand_tier']);
        $this->assertSame('low', $this->v->assess('small', $map)['demand_tier']);
        $this->assertSame('provided', $this->v->assess('big', $map)['basis']);
        $this->assertSame(4.2, $this->v->assess('big', $map)['top_of_page_bid']);
    }

    public function test_prioritize_is_intent_only_without_volume(): void
    {
        $r = $this->v->prioritize([['keyword' => 'a', 'score' => 80]]);
        $this->assertSame('intent_only', $r['basis']);
    }

    public function test_low_volume_hero_is_not_killed_by_demand(): void
    {
        // hero: high score (90) but low volume; generic: low score (40) but high volume.
        $scored = [
            ['keyword' => 'hero mechanism', 'score' => 90],
            ['keyword' => 'generic term', 'score' => 40],
        ];
        $map = ['hero mechanism' => ['searches' => 30], 'generic term' => ['searches' => 9000]];
        $r = $this->v->prioritize($scored, $map);
        $this->assertSame('demand_weighted', $r['basis']);
        // the high-CVR low-volume hero must still rank first (demand modula, não veta).
        $this->assertSame('hero mechanism', $r['ranked'][0]['keyword']);
    }
}
