<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\MarketSpyHarvester;
use PHPUnit\Framework\TestCase;

/**
 * Locks the scaled scout: N winner pages in → trend patterns (those that repeat in K+ pages),
 * trend candidates (heuristic constructs that recur), aggregated library density, and the average
 * audit + hollowness across the batch. Provider-free; same idea as Scout but for the safra atual
 * do mercado, not a single page.
 */
class MarketSpyHarvesterTest extends TestCase
{
    private array $pages = [
        '<h1>Triple Hormone Drops — 41 lbs in 12 weeks. Big Pharma hides it. Dr. Attia at Harvard. 60-day money-back guarantee.</h1>',
        '<h1>The Carbon Reset Method — Big Pharma fights it. Published in NEJM. 90-day money-back. 11,847 women joined.</h1>',
        '<h1>Triple GLP-1 Drops protocol — secret the wealthy use. The industry hides this. As seen on CBS. 60-day money-back. 9,400 reviews.</h1>',
    ];

    public function test_harvests_n_pages_and_surfaces_trend_patterns(): void
    {
        $r = (new MarketSpyHarvester)->harvest($this->pages);

        $this->assertSame(3, $r['n_pages']);
        $this->assertNotEmpty($r['trend_patterns']);

        $present = array_column($r['trend_patterns'], 'pattern');
        // 'common_enemy' (Big Pharma) appears in ALL 3 — must be a trend
        $this->assertContains('persuasion:common_enemy', $present);
        $this->assertContains('angle_big_idea:common_enemy', $present);
        // risk_reversal_strong (money-back) is in all 3
        $this->assertContains('offer_architecture:risk_reversal_strong', $present);

        // Each trend pattern has a share fraction
        $this->assertGreaterThanOrEqual(0.66, $r['trend_patterns'][0]['share']);
    }

    public function test_returns_aggregated_density_per_library(): void
    {
        $r = (new MarketSpyHarvester)->harvest($this->pages);
        $this->assertArrayHasKey('persuasion', $r['library_density']);
        $this->assertGreaterThan(0, $r['library_density']['persuasion']);
    }

    public function test_empty_input_returns_neutral_report(): void
    {
        $r = (new MarketSpyHarvester)->harvest([]);
        $this->assertSame(0, $r['n_pages']);
        $this->assertSame([], $r['trend_patterns']);
        $this->assertSame(0.0, $r['avg_overall_score']);
    }

    public function test_only_isolated_patterns_filtered_below_threshold(): void
    {
        $mixed = [
            '<p>Big Pharma hides it. 60-day money-back guarantee.</p>',          // 2 patterns
            '<p>Completely unrelated product page. Buy now.</p>',                // none of those
        ];
        $r = (new MarketSpyHarvester)->harvest($mixed, repeatThreshold: 2);

        foreach ($r['trend_patterns'] as $p) {
            $this->assertGreaterThanOrEqual(2, $p['seen_in']);
        }
    }
}
