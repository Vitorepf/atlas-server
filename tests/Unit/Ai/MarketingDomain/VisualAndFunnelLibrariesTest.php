<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\PatternLibraryScorer;
use App\Services\Ai\MarketingDomain\Knowledge\FunnelSequenceLibrary;
use App\Services\Ai\MarketingDomain\Knowledge\VisualPersuasionLibrary;
use PHPUnit\Framework\TestCase;

/**
 * Locks the two final libraries of the Conversion OS: VisualPersuasion (the conversion that happens
 * without text — cta contrast, sticky cta, trust bar, social proof grid, before/after, video, urgency
 * countdown, notifications, mobile thumb-zone, white-space pacing) and FunnelSequence (the journey,
 * not the page — message match, micro-commit, value ladder, tripwire, order bump, OTO, downsell,
 * abandoned cart, reengage, post-purchase, winback, retention). Both fire on elite construction and
 * score zero on empty pages.
 */
class VisualAndFunnelLibrariesTest extends TestCase
{
    public function test_visual_persuasion_fires_on_elite_html_construction(): void
    {
        $html = '<html><body><div class="cdbar" id="cd">11:59</div>'
            .'<h1>The Hidden Cause</h1><p class="dek">Subhead</p>'
            .'<section class="vsl" id="vsl"><svg viewBox="0 0 680 383">'
            .'<text>LEAKED · SPECIAL REPORT</text><text>WATCH BEFORE THIS STORY IS TAKEN DOWN</text>'
            .'<path d="M150 250" stroke-linecap="round"/></svg></section>'
            .'<a class="cta cta-hero" href="#vsl">Watch</a>'
            .'<ul class="trust"><li>As seen on CBS</li></ul>'
            .'<div class="ba"><div class="bacard"><div class="ph"><span class="bares">−41 lbs</span></div></div></div>'
            .'<div class="rev"><div class="rc"><div class="hd">★★★★★ verified buyer</div></div></div>'
            .'<div class="toast" id="toast"><b id="tt">Karen</b><span id="ts">just requested</span></div>'
            .'<a class="cta stick" href="#vsl">Watch</a>'
            .'<style>.stick{position:fixed;bottom:12px}@media(max-width:520px){}</style>'
            .'<meta name="viewport" content="width=device-width">'
            .'</body></html>';

        $r = (new PatternLibraryScorer)->score(new VisualPersuasionLibrary, $html);

        $this->assertSame('visual_persuasion', $r['library']);
        $this->assertGreaterThanOrEqual(70, $r['score']);
        foreach (['cta_contrast', 'cta_repeated', 'sticky_cta', 'visual_hierarchy', 'trust_bar',
            'social_proof_volume', 'before_after_grid', 'video_first', 'thumbnail_compelling',
            'urgency_visual', 'notification_stream', 'mobile_thumb_zone'] as $k) {
            $this->assertContains($k, $r['present'], "Expected visual {$k}");
        }
    }

    public function test_funnel_sequence_fires_on_full_journey_plan(): void
    {
        $plan = "Ad → Bridge: as advertised, watch the free presentation. "
            .'Take the quiz to see if it fits you. Starter pack $7 trial. Upgrade to premium tier. '
            .'At checkout: add to your order this bonus. One-time offer: wait, before you go. '
            .'No thanks? maybe this lighter option. '
            .'Email: you left items in your cart. Email 3-email follow-up sequence. '
            .'Day 1: first result in 24 hours. Subscribe and save, cancel anytime. Refer a friend.';

        $r = (new PatternLibraryScorer)->score(new FunnelSequenceLibrary, $plan);

        $this->assertSame('funnel_sequence', $r['library']);
        $this->assertGreaterThanOrEqual(65, $r['score']);
        foreach (['micro_commit', 'tripwire_offer', 'order_bump', 'oto_upsell',
            'abandoned_cart', 'subscription_lock', 'referral_loop'] as $k) {
            $this->assertContains($k, $r['present'], "Expected funnel pattern {$k}");
        }
    }

    public function test_both_libraries_score_zero_on_empty_construction(): void
    {
        $sc = new PatternLibraryScorer;
        $this->assertSame(0, $sc->score(new VisualPersuasionLibrary, '<html><body><p>buy</p></body></html>')['score']);
        $this->assertSame(0, $sc->score(new FunnelSequenceLibrary, 'Buy now.')['score']);
    }
}
