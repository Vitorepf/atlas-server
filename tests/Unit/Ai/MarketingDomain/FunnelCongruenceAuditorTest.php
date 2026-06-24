<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\FunnelCongruenceAuditor;
use PHPUnit\Framework\TestCase;

/**
 * FunnelCongruenceAuditor — whole-funnel structural X-ray composing the three structural-truth checks
 * (per-hop message-match congruence + promise continuity + per-stage watch-through leaks). Every signal
 * is a fact, not a quality proxy.
 */
class FunnelCongruenceAuditorTest extends TestCase
{
    public function test_a_congruent_continuous_funnel_is_sound(): void
    {
        $r = (new FunnelCongruenceAuditor)->audit([
            'ad' => 'Women over 40 lose 30 lbs with a morning ritual',
            'page' => 'Women over 40 are losing 30 lbs with this two-minute morning ritual.',
        ]);
        $this->assertSame('sound', $r['verdict']);
        $this->assertSame([], $r['defects']);
    }

    public function test_weak_hop_is_flagged_and_identified(): void
    {
        $r = (new FunnelCongruenceAuditor)->audit([
            'ad' => 'Get your dream beach body this summer',
            'page' => 'Tax planning strategies for retirees who own rental property.',
        ]);
        $this->assertSame('has_defects', $r['verdict']);
        $this->assertNotNull($r['weakest_hop']);
        $this->assertLessThan(20, $r['weakest_hop']['congruence']);
    }

    public function test_dropped_promise_across_the_chain_is_a_defect(): void
    {
        $r = (new FunnelCongruenceAuditor)->audit([
            'ad' => 'Lose 30 lbs with our morning ritual method',
            'page' => 'Our morning ritual method gently resets your metabolism over time.',
        ]);
        $this->assertSame('has_defects', $r['verdict']);
        $this->assertContains('dropped_promise', array_column($r['continuity']['breaks'], 'key'));
    }

    public function test_a_per_stage_leak_is_a_defect(): void
    {
        $leakedPage = 'The secret is a two-minute morning ritual. Here is how it works: you do it daily. '
            .'It is called the method. It resets three hormones. It works for everyone. That is all.';
        $r = (new FunnelCongruenceAuditor)->audit([
            'ad' => 'A morning ritual for women over 40',
            'page' => $leakedPage,
        ]);
        $this->assertSame('has_defects', $r['verdict']);
        $this->assertNotEmpty($r['leaks']);
        $this->assertSame('page', $r['leaks'][0]['stage']);
    }

    public function test_weakest_hop_picks_the_lowest_congruence_pair(): void
    {
        $r = (new FunnelCongruenceAuditor)->audit([
            'ad' => 'Lose 30 lbs with a morning ritual',
            'page' => 'This morning ritual helps women lose 30 lbs naturally.',  // strong hop
            'checkout' => 'Quantum blockchain synergy for enterprise logistics.', // alien hop
        ]);
        $this->assertSame('page', $r['weakest_hop']['from']);
        $this->assertSame('checkout', $r['weakest_hop']['to']);
    }

    public function test_single_stage_is_not_assessed(): void
    {
        $this->assertFalse((new FunnelCongruenceAuditor)->audit(['ad' => 'Lose 30 lbs'])['assessed']);
    }
}
