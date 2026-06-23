<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\WinningPatternScout;
use PHPUnit\Framework\TestCase;

/**
 * Locks the inverse learning loop: a winner page from the wild goes in, the OS produces a fingerprint
 * (which patterns are present + density per library), the hollowness verdict, and a list of
 * CANDIDATES — heuristic constructs not yet encoded that the next deepening cycle should harvest.
 * Provider-free; foundation for the learned-weights flywheel once real conversion data arrives.
 */
class WinningPatternScoutTest extends TestCase
{
    public function test_scouts_a_winner_and_returns_fingerprint(): void
    {
        $winner = '<html><body>'
            .'<h1>The Triple Hormone Drops Protocol Big Pharma Hides — 41 lbs gone, no needle.</h1>'
            .'<p>If you are a woman over 40, here is the real reason nothing worked.</p>'
            .'<p>It was never your fault. Dr. Attia at Harvard showed it. According to NEJM: 11,847 women lost on average 38 lbs.</p>'
            .'<p>The 60-day money-back guarantee. Limited to 100 spots tonight. As seen on CBS.</p>'
            .'<a class="cta" href="#">Watch the Free Presentation</a>'
            .'</body></html>';

        $r = (new WinningPatternScout)->scout($winner);

        $this->assertArrayHasKey('audit', $r);
        $this->assertArrayHasKey('hollowness', $r);
        $this->assertArrayHasKey('fingerprint', $r);
        $this->assertArrayHasKey('signal_density', $r);
        $this->assertArrayHasKey('candidates', $r);

        $this->assertGreaterThan(0, $r['audit']['overall_score']);
        $this->assertNotEmpty($r['fingerprint']);
        $this->assertSame(0, $r['hollowness']['hollowness'],
            'A real winner page must NOT score as hollow');
    }

    public function test_signal_density_keys_match_libraries(): void
    {
        $r = (new WinningPatternScout)->scout('<p>The Triple Hormone Drops Protocol — 41 lbs lost.</p>');
        foreach (['angle_big_idea', 'persuasion', 'cognitive_bias', 'offer_architecture',
            'objection', 'hook_lead', 'narrative_voice', 'funnel_sequence',
            'awareness_sophistication', 'visual_persuasion'] as $lib) {
            $this->assertArrayHasKey($lib, $r['signal_density'], "Density should include {$lib}");
        }
    }

    public function test_surfaces_candidate_patterns_to_harvest(): void
    {
        $winner = '<html><body>'
            .'<h1>The Carbon Reset Method™ — 11,847 women lost 38 lbs</h1>'
            .'<p>Step 1: Begin. Step 2: Apply. Step 3: Verify. Step 4: Repeat.</p>'
            .'<table><tr><td>Us</td><td>Ozempic</td></tr></table>'
            .'<iframe src="https://youtube.com/embed/x"></iframe>'
            .'</body></html>';

        $r = (new WinningPatternScout)->scout($winner);

        $this->assertGreaterThanOrEqual(2, count($r['candidates']),
            'Should surface at least 2 candidate patterns to harvest');
    }
}
