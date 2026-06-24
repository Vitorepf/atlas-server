<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Campaign\KeywordQualityIndex;
use PHPUnit\Framework\TestCase;

/**
 * Locks the Keyword Quality Index: waste is eliminated BEFORE scoring (don't pay to learn a keyword is
 * bad), survivors are scored 0-100 on the 6 research-grounded components, and owned-root (post-VSL-
 * exposure) keywords outrank generic category terms. This is the "índice de qualidade" + elimination
 * the operator asked for.
 */
class KeywordQualityIndexTest extends TestCase
{
    private function asset(): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset([
            'core_promise' => 'lose weight without injections by re-syncing three fat-burning hormones at home',
            'mechanism_name' => 'Triple Hormone Drops Protocol',
            'angle' => 'a home-made retatrutide that activates GLP-1, GIP and glucagon',
            'hook' => 'Melania Trump lost 63 lbs on a free government at-home protocol',
            'trick' => 'at-home retatrutide protocol',
            'power_phrases' => ['four simple ingredients', 'activates three fat-burning hormones', 'Make America Skinny Again'],
        ]);
    }

    /** @return array<string,mixed> */
    private function engineResult(): array
    {
        return [
            'artifacts' => ['product_name' => 'lipo bliss'],
            'tiers' => [
                ['family' => 'mechanism_trick', 'match_type' => 'exact/phrase', 'roots' => ['triple hormone drops protocol'],
                    'keywords' => ['triple hormone drops protocol', 'triple hormone drops protocol where to buy']],
                ['family' => 'category_alternative', 'match_type' => 'phrase', 'roots' => ['glp-1'],
                    'keywords' => ['glp-1 alternative', 'glp-1 over the counter']],
                // Bad keywords (as if from an external list) to prove elimination:
                ['family' => 'generic', 'match_type' => 'broad', 'roots' => [],
                    'keywords' => ['weight loss', 'what is retatrutide', 'free weight loss protocol', 'lipo bliss reviews', 'retatrutide', 'ozempic jobs']],
            ],
        ];
    }

    public function test_owned_root_outranks_category_alternative(): void
    {
        $r = (new KeywordQualityIndex)->scoreEngineResult($this->engineResult(), $this->asset(), ['payout' => 120, 'cvr' => 0.012]);
        $byKw = collect($r['scored'])->keyBy('keyword');
        $this->assertGreaterThan(
            $byKw['glp-1 alternative']['score'],
            $byKw['triple hormone drops protocol']['score'],
            'A coined owned-root keyword must score above a generic category term.'
        );
    }

    public function test_waste_is_eliminated_before_scoring(): void
    {
        $r = (new KeywordQualityIndex)->scoreEngineResult($this->engineResult(), $this->asset());
        $killedKw = array_column($r['killed'], 'keyword');
        $this->assertContains('weight loss', $killedKw);            // generic head
        $this->assertContains('what is retatrutide', $killedKw);   // informational, no owned root
        $this->assertContains('free weight loss protocol', $killedKw); // free
        $this->assertContains('lipo bliss reviews', $killedKw);    // product name
        $this->assertContains('retatrutide', $killedKw);           // bare drug
        $this->assertContains('ozempic jobs', $killedKw);          // jobs
        // None of the killed keywords reached the scored set.
        $scoredKw = array_column($r['scored'], 'keyword');
        $this->assertNotContains('weight loss', $scoredKw);
    }

    public function test_every_scored_keyword_has_score_band_and_components(): void
    {
        $r = (new KeywordQualityIndex)->scoreEngineResult($this->engineResult(), $this->asset());
        foreach ($r['scored'] as $s) {
            $this->assertIsInt($s['score']);
            $this->assertGreaterThanOrEqual(0, $s['score']);
            $this->assertLessThanOrEqual(100, $s['score']);
            $this->assertContains($s['band'], ['scale', 'launch', 'test', 'kill']);
            $this->assertArrayHasKey('owned_root_provenance', $s['components']);
        }
    }

    public function test_results_are_sorted_best_first(): void
    {
        $r = (new KeywordQualityIndex)->scoreEngineResult($this->engineResult(), $this->asset());
        $scores = array_column($r['scored'], 'score');
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores);
    }

    public function test_unprofitable_economics_caps_score(): void
    {
        // A tiny payout + low CVR makes the forecast CPC exceed breakeven → score capped into the kill band.
        $r = (new KeywordQualityIndex)->scoreEngineResult($this->engineResult(), $this->asset(), ['payout' => 8, 'cvr' => 0.004, 'margin' => 0.3]);
        foreach ($r['scored'] as $s) {
            $this->assertLessThanOrEqual(60, $s['score'], 'unprofitable economics should pull scores down');
        }
    }

    public function test_each_scored_keyword_carries_intent_rationale(): void
    {
        // cycle 2: every qualified keyword explains WHY (tier/polarity/confidence/action) — the operator
        // sees the intent, not just a number.
        $r = (new KeywordQualityIndex)->scoreEngineResult($this->engineResult(), $this->asset(), ['payout' => 120, 'cvr' => 0.012]);
        foreach ($r['scored'] as $s) {
            $this->assertArrayHasKey('intent', $s);
            foreach (['tier', 'journey', 'pain', 'polarity', 'confidence', 'action', 'intent_score'] as $k) {
                $this->assertArrayHasKey($k, $s['intent']);
            }
        }
    }

    public function test_owned_root_intent_is_most_aware(): void
    {
        $r = (new KeywordQualityIndex)->scoreEngineResult($this->engineResult(), $this->asset(), ['payout' => 120, 'cvr' => 0.012]);
        $byKw = collect($r['scored'])->keyBy('keyword');
        // the coined mechanism keyword keeps the most-aware override (intent_score 100) and reads T4.
        $this->assertSame(100, $byKw['triple hormone drops protocol']['intent']['intent_score']);
        $this->assertSame('T4', $byKw['triple hormone drops protocol']['intent']['tier']);
    }
}
