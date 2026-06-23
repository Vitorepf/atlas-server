<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordLearningLoop;
use App\Services\Ai\MarketingDomain\Campaign\KeywordQualityIndex;
use PHPUnit\Framework\TestCase;

/**
 * Locks the Keyword OS flywheel: real outcomes (keyword → cost/conversions/revenue) produce a Bayesian-
 * shrunk lift per family/root that feeds back into the KeywordQualityIndex — proven sellers rise, proven
 * money-losers fall — so the OS sharpens with spend instead of staying frozen at launch heuristics.
 */
class KeywordLearningLoopTest extends TestCase
{
    /** @return array<int,array<string,mixed>> */
    private function outcomes(): array
    {
        return [
            ['keyword' => 'make america skinny again', 'family' => 'slogan', 'cost' => 180, 'conversions' => 9, 'revenue' => 1080],
            ['keyword' => 'triple hormone drops protocol', 'family' => 'mechanism_trick', 'cost' => 110, 'conversions' => 0, 'revenue' => 0],
        ];
    }

    public function test_seller_gets_lift_above_one_loser_below_one(): void
    {
        $c = (new KeywordLearningLoop)->calibrate($this->outcomes(), ['target_roas' => 1.59, 'kill_cost_floor' => 40]);
        $this->assertGreaterThan(1.0, $c['proven_lift']['family:slogan']);
        $this->assertLessThan(1.0, $c['proven_lift']['family:mechanism_trick']);
    }

    public function test_lift_is_bounded(): void
    {
        $c = (new KeywordLearningLoop)->calibrate($this->outcomes(), ['target_roas' => 1.0]);
        foreach ($c['proven_lift'] as $mult) {
            $this->assertGreaterThanOrEqual(0.6, $mult);
            $this->assertLessThanOrEqual(1.5, $mult);
        }
    }

    public function test_promote_demote_and_proven_lists(): void
    {
        $c = (new KeywordLearningLoop)->calibrate($this->outcomes(), ['kill_cost_floor' => 40]);
        $this->assertContains('make america skinny again', $c['proven_keywords']);
        $this->assertSame('make america skinny again', $c['promote'][0]['keyword']);
        $this->assertSame('triple hormone drops protocol', $c['demote'][0]['keyword']);
    }

    public function test_index_score_rises_for_proven_seller_and_falls_for_loser(): void
    {
        $c = (new KeywordLearningLoop)->calibrate($this->outcomes(), ['target_roas' => 1.59, 'kill_cost_floor' => 40]);
        $idx = new KeywordQualityIndex;

        $base = $idx->score('make america skinny again', 'slogan', ['make america skinny again'], 'phrase', [], ['make', 'america', 'skinny']);
        $lifted = $idx->score('make america skinny again', 'slogan', ['make america skinny again'], 'phrase', [], ['make', 'america', 'skinny'], ['proven_lift' => $c['proven_lift']]);
        $this->assertGreaterThan($base['score'], $lifted['score']);

        $baseM = $idx->score('triple hormone drops protocol', 'mechanism_trick', ['triple hormone drops protocol'], 'exact/phrase', [], ['triple', 'hormone']);
        $liftedM = $idx->score('triple hormone drops protocol', 'mechanism_trick', ['triple hormone drops protocol'], 'exact/phrase', [], ['triple', 'hormone'], ['proven_lift' => $c['proven_lift']]);
        $this->assertLessThan($baseM['score'], $liftedM['score']);
    }

    public function test_no_outcomes_leaves_index_unchanged(): void
    {
        $idx = new KeywordQualityIndex;
        $a = $idx->score('triple hormone drops', 'mechanism_trick', ['triple hormone drops'], 'phrase', [], ['triple']);
        $b = $idx->score('triple hormone drops', 'mechanism_trick', ['triple hormone drops'], 'phrase', [], ['triple'], ['proven_lift' => []]);
        $this->assertSame($a['score'], $b['score']);
    }
}
