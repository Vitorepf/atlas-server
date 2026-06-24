<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\MarketSophisticationRouter;
use PHPUnit\Framework\TestCase;

/**
 * MarketSophisticationRouter — Schwartz's 1-5 ladder. The move escalates with how burned-out the market
 * is: plain claim → bigger claim → mechanism → new mechanism → identification.
 */
class MarketSophisticationRouterTest extends TestCase
{
    public function test_level_1_is_a_plain_direct_claim_no_mechanism(): void
    {
        $s = (new MarketSophisticationRouter)->strategy(1);
        $this->assertSame('direct_claim', $s['strategy']);
        $this->assertFalse($s['forge_mechanism']);
    }

    public function test_levels_3_and_4_call_for_a_named_mechanism(): void
    {
        $r = new MarketSophisticationRouter;
        $this->assertTrue($r->shouldForgeMechanism(3));
        $this->assertTrue($r->shouldForgeMechanism(4));
        $this->assertSame('new_unique_mechanism', $r->strategy(4)['strategy']);
    }

    public function test_level_5_exhausted_market_switches_to_identification(): void
    {
        $s = (new MarketSophisticationRouter)->strategy(5);
        $this->assertSame('identify_and_experience', $s['strategy']);
        $this->assertFalse($s['forge_mechanism'], 'arguing the mechanism is dead in an exhausted market');
        $this->assertContains('hook_story_open', $s['moves']);
    }

    public function test_normalizes_numbers_text_and_clamps(): void
    {
        $r = new MarketSophisticationRouter;
        $this->assertSame(5, $r->normalize('exhausted / saturated'));
        $this->assertSame(1, $r->normalize('brand new market'));
        $this->assertSame(4, $r->normalize('level 4'));
        $this->assertSame(5, $r->normalize(9));   // clamp
        $this->assertSame(1, $r->normalize(0));   // clamp
        $this->assertSame(3, $r->normalize('unknown')); // sane mid-default
    }
}
