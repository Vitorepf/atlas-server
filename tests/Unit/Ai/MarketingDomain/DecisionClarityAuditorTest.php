<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\DecisionClarityAuditor;
use PHPUnit\Framework\TestCase;

/**
 * DecisionClarityAuditor — structural truth at the decision point: no ask = can't convert; many distinct
 * competing asks = choice overload. Facts (ask present? N distinct action categories?), not a score.
 */
class DecisionClarityAuditorTest extends TestCase
{
    private function keys(string $copy): array
    {
        return array_column((new DecisionClarityAuditor)->audit($copy)['flaws'], 'key');
    }

    public function test_flags_a_page_with_no_cta(): void
    {
        $this->assertContains('no_cta', $this->keys('Our supplement supports healthy metabolism for women over 40.'));
    }

    public function test_a_clear_single_cta_is_clean(): void
    {
        $r = (new DecisionClarityAuditor)->audit('Watch the free presentation to see how it works.');
        $this->assertSame([], $r['flaws']);
    }

    public function test_a_generic_cta_counts_as_an_ask(): void
    {
        $this->assertNotContains('no_cta', $this->keys('Read the full story below, then click here to begin.'));
    }

    public function test_repeating_the_same_cta_is_not_overload(): void
    {
        // One action category (watch), reinforced — good, not overload.
        $copy = 'Watch the presentation now. Seriously, watch the video. Assista a apresentação.';
        $this->assertNotContains('competing_ctas', $this->keys($copy));
    }

    public function test_flags_choice_overload_of_distinct_actions(): void
    {
        $copy = 'Buy now to get yours. Or sign up for our newsletter. Download the free guide. '
            .'Follow us on Facebook. Book a call with our team.';
        $this->assertContains('competing_ctas', $this->keys($copy));
        $cats = (new DecisionClarityAuditor)->audit($copy)['action_categories'];
        $this->assertGreaterThanOrEqual(3, count($cats));
    }
}
