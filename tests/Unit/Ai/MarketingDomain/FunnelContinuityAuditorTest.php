<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\FunnelContinuityAuditor;
use PHPUnit\Framework\TestCase;

/**
 * FunnelContinuityAuditor — structural-truth congruence. The dispositive rule (from the brutal panel):
 * UNDER-firing is honest and fine; FALSE-firing destroys trust. So this suite proves it fires on the
 * unambiguous true breaks AND stays SILENT on every legitimate funnel shape the panel surfaced
 * (free-trial→paid, free shipping + price, value-anchor, order-bump, "feel free", spelled-out carry,
 * guarantee/trial timeframes).
 */
class FunnelContinuityAuditorTest extends TestCase
{
    private function keys(array $r): array
    {
        return array_column($r['breaks'], 'key');
    }

    // ───────────────────────── TRUE POSITIVES (must fire) ─────────────────────────

    public function test_flags_a_dropped_weight_promise(): void
    {
        $r = (new FunnelContinuityAuditor)->audit([
            'ad' => 'Lose 30 lbs without dieting',
            'page' => 'A simple morning routine that resets your metabolism naturally.',
        ]);
        $this->assertContains('dropped_promise', $this->keys($r));
    }

    public function test_flags_a_dropped_percentage_promise(): void
    {
        // The "%" branch used to be dead code — this proves percentage claims are now extracted.
        $r = (new FunnelContinuityAuditor)->audit([
            'ad' => 'Cut your monthly bills by 40%',
            'page' => 'A totally different topic with no figures at all.',
        ]);
        $this->assertContains('dropped_promise', $this->keys($r));
        $this->assertContains('40%', $r['committed']);
    }

    public function test_flags_a_dropped_income_promise(): void
    {
        $r = (new FunnelContinuityAuditor)->audit([
            'ad' => 'How I make $5,000 a month on autopilot',
            'page' => 'A rule-based system anyone can follow in their spare time.',
        ]);
        $this->assertContains('dropped_promise', $this->keys($r));
    }

    public function test_flags_core_bait_and_switch(): void
    {
        $r = (new FunnelContinuityAuditor)->audit([
            'ad' => 'Watch the free presentation',
            'page' => 'Discover the method on the next page.',
            'checkout' => 'Get full access today for just $97.',
        ]);
        $this->assertContains('price_scent_break', $this->keys($r));
    }

    public function test_substring_collision_is_not_laundered_as_carried(): void
    {
        // "30" must not be considered carried by "2030"/"300" — boundary match, so this IS a real drop.
        $r = (new FunnelContinuityAuditor)->audit([
            'ad' => 'Lose 30 lbs without dieting',
            'page' => 'Join our 2030 wellness vision with 300 five-star reviews.',
        ]);
        $this->assertContains('dropped_promise', $this->keys($r));
    }

    // ───────────────────────── CLEAN / NO FALSE FIRING ─────────────────────────

    public function test_carried_number_is_clean(): void
    {
        $r = (new FunnelContinuityAuditor)->audit([
            'ad' => 'Lose 30 lbs without dieting',
            'page' => 'How women are losing 30 lbs with a two-minute ritual — no diet.',
        ]);
        $this->assertSame([], $r['breaks']);
        $this->assertSame(100, $r['continuity']);
    }

    public function test_spelled_out_carry_is_not_a_false_drop(): void
    {
        $r = (new FunnelContinuityAuditor)->audit([
            'ad' => 'Lose 30 lbs without dieting',
            'page' => 'Women everywhere are dropping thirty pounds with this ritual.',
        ]);
        $this->assertNotContains('dropped_promise', $this->keys($r));
    }

    public function test_free_shipping_plus_product_price_is_not_a_break(): void
    {
        $r = (new FunnelContinuityAuditor)->audit([
            'ad' => 'Order today and get free shipping',
            'checkout' => 'Your bottle is $49 with free shipping included.',
        ]);
        $this->assertNotContains('price_scent_break', $this->keys($r));
    }

    public function test_free_trial_then_paid_is_not_a_break(): void
    {
        $r = (new FunnelContinuityAuditor)->audit([
            'ad' => 'Start your 14 day free trial',
            'page' => 'Free for 14 days, cancel anytime.',
            'checkout' => 'After your trial it is just $29/month.',
        ]);
        $this->assertSame([], $r['breaks'], 'legit free-trial→paid must not be flagged');
    }

    public function test_value_anchor_is_not_a_price_break(): void
    {
        $r = (new FunnelContinuityAuditor)->audit([
            'ad' => 'Claim your free workshop',
            'page' => 'Normally worth $500, yours completely free today.',
        ]);
        $this->assertNotContains('price_scent_break', $this->keys($r));
    }

    public function test_disclosed_order_bump_is_not_a_price_break(): void
    {
        $r = (new FunnelContinuityAuditor)->audit([
            'ad' => 'Download the free report',
            'page' => 'Get the free 20-page report instantly. Optional: add the toolkit for $7.',
        ]);
        $this->assertNotContains('price_scent_break', $this->keys($r));
    }

    public function test_feel_free_idiom_is_not_a_price_break(): void
    {
        $r = (new FunnelContinuityAuditor)->audit([
            'ad' => 'Feel free to explore our method',
            'page' => 'Enroll today for $97.',
        ]);
        $this->assertNotContains('price_scent_break', $this->keys($r));
    }

    public function test_guarantee_window_is_not_a_hero_promise(): void
    {
        $r = (new FunnelContinuityAuditor)->audit([
            'ad' => 'Try it with a 60-day money-back guarantee',
            'page' => 'A natural blend made for women over 40.',
        ]);
        $this->assertNotContains('dropped_promise', $this->keys($r));
    }

    public function test_relationship_no_numbers_no_false_positive(): void
    {
        $r = (new FunnelContinuityAuditor)->audit([
            'ad' => 'The secret to winning your ex back',
            'page' => 'Real psychology that rebuilds attraction, honestly.',
        ]);
        $this->assertSame([], $r['breaks']);
        $this->assertSame(100, $r['continuity']);
    }

    public function test_single_stage_is_not_assessed(): void
    {
        $this->assertFalse((new FunnelContinuityAuditor)->audit(['ad' => 'Lose 30 lbs'])['assessed']);
    }
}
