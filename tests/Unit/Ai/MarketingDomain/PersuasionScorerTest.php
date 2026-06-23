<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\PersuasionScorer;
use PHPUnit\Framework\TestCase;

/**
 * Locks the persuasion engine: a copy loaded with elite levers scores high and detects the mechanism,
 * enemy, scarcity, authority and risk-reversal patterns; flat copy scores low and surfaces the
 * highest-leverage missing patterns; regex markers (specific numbers) fire. Deterministic.
 */
class PersuasionScorerTest extends TestCase
{
    public function test_elite_copy_scores_high_and_detects_core_levers(): void
    {
        $copy = 'If you are a woman over 40, this is not your fault — it is biology Big Pharma does not '
            .'want you to know. The Triple Hormone Drops Protocol switches three hormones back on. '
            .'Amy lost 41 lbs in 12 weeks. 9,400 reviews. As seen on CBS. 60-day money-back guarantee. '
            .'No needle, no diet — just seconds in the morning. But isn\'t this a scam? Watch the free presentation before it is taken down.';
        $r = (new PersuasionScorer)->score($copy);

        $this->assertGreaterThanOrEqual(70, $r['score']);
        $this->assertContains('unique_mechanism', $r['present']);
        $this->assertContains('common_enemy', $r['present']);
        $this->assertContains('risk_reversal', $r['present']);
        $this->assertContains('scarcity', $r['present']);
        $this->assertContains('specificity', $r['present']);    // regex marker fired on "41 lbs"
        $this->assertContains($r['grade'], ['strong', 'killer']);
    }

    public function test_flat_copy_scores_low_and_names_missing_levers(): void
    {
        $r = (new PersuasionScorer)->score('Our company sells a wellness product. Learn more on our website.');

        $this->assertLessThan(30, $r['score']);
        $this->assertNotEmpty($r['missing_high_leverage']);
        $keys = array_column($r['missing_high_leverage'], 'key');
        $this->assertContains('unique_mechanism', $keys);
        foreach ($r['missing_high_leverage'] as $m) {
            $this->assertNotEmpty($m['lever']);   // each gap explains how to deploy it
        }
    }

    public function test_category_coverage_is_reported(): void
    {
        $r = (new PersuasionScorer)->score('guarantee money-back risk-free');
        $this->assertArrayHasKey('offer', $r['by_category']);
        $this->assertGreaterThan(0, $r['by_category']['offer']);
        $this->assertSame(0, $r['by_category']['mechanism']);
    }
}
