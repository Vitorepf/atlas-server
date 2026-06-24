<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\ValueEquationAuditor;
use PHPUnit\Framework\TestCase;

/**
 * ValueEquationAuditor — Hormozi's 4 levers as a structural-completeness check (Eixo 5). Flags which
 * levers the offer leaves unanswered (gaps), highest-leverage first. Not a quality score — a fact about
 * which of the 4 the copy addresses.
 */
class ValueEquationAuditorTest extends TestCase
{
    public function test_a_full_grand_slam_offer_has_no_gaps(): void
    {
        $copy = 'Imagine finally waking up lighter. Backed by a 60-day money-back guarantee and a real '
            .'study, you see results in just 14 days — with no diet, no gym, just 2 minutes a day.';
        $r = (new ValueEquationAuditor)->audit($copy);
        $this->assertSame([], $r['gaps']);
        $this->assertSame(100, $r['score']);
    }

    public function test_flags_the_missing_levers(): void
    {
        // Dream outcome only; no proof, no timeframe, no effort-collapse.
        $copy = 'Imagine the body you have always dreamed of. Become the person you want to be.';
        $r = (new ValueEquationAuditor)->audit($copy);
        $keys = array_column($r['gaps'], 'key');
        $this->assertContains('perceived_likelihood', $keys);
        $this->assertContains('time_delay', $keys);
        $this->assertContains('effort_sacrifice', $keys);
        $this->assertNotContains('dream_outcome', $keys);
    }

    public function test_a_delivery_or_past_timeframe_is_not_the_time_to_result_lever(): void
    {
        $ve = new ValueEquationAuditor;
        // Shipping window and a past reference are NOT "how soon do I see results".
        $this->assertNotContains('time_delay', $ve->audit('Your order ships in 3 days to your door.')['covered']);
        $this->assertNotContains('time_delay', $ve->audit('Just 3 days ago a reader wrote to me.')['covered']);
        // A real time-to-result still counts.
        $this->assertContains('time_delay', $ve->audit('You will see results in 21 days.')['covered']);
    }

    public function test_high_leverage_gaps_are_surfaced_first(): void
    {
        // Has dream + timeframe, but no proof (likelihood) and no ease (effort) → both high-leverage.
        $copy = 'Imagine your new body in just 21 days.';
        $gaps = (new ValueEquationAuditor)->audit($copy)['gaps'];
        $this->assertTrue($gaps[0]['high_leverage'], 'a high-leverage gap (likelihood/effort) must come first');
    }

    public function test_cross_niche_finance_offer(): void
    {
        $copy = 'Picture your account compounding. Audited track record, money-back guarantee, results in '
            .'3 weeks, and it takes just 10 minutes a day — no experience.';
        $this->assertSame(100, (new ValueEquationAuditor)->audit($copy)['score']);
    }
}
