<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Models\AiMarketingWinningPattern;
use App\Services\Ai\MarketingDomain\Content\KeywordRelevanceGate;
use PHPUnit\Framework\TestCase;

/**
 * Locks the "no crime" contract: every prominent THEMATIC keyword on the bridge must be anchored in
 * the VSL or in a proven converting keyword of the niche. Orphan thematic terms fail closed; generic
 * persuasion words are never crimes. Deterministic.
 */
class KeywordRelevanceGateTest extends TestCase
{
    private function asset(): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset([
            'transcript' => 'This at-home protocol uses triple-hormone drops with retatrutide to burn belly fat naturally.',
            'big_idea' => 'a home version of the retatrutide drops that reactivate three fat-burning hormones',
            'mechanism_name' => 'Triple-Hormone Drops Protocol',
            'angle' => 'retatrutide replicated at home in drops',
            'niche' => 'weight_loss',
        ]);
    }

    private function pattern(): AiMarketingWinningPattern
    {
        return new AiMarketingWinningPattern([
            'converting_keywords' => [
                ['term' => 'gelatin trick', 'conversions' => 1083],
                ['term' => 'pink gelatin', 'conversions' => 85],
            ],
        ]);
    }

    public function test_all_keywords_anchored_in_vsl_or_pattern_is_ok(): void
    {
        $bridge = [
            'headline' => 'The Gelatin Trick Behind The Retatrutide Drops Protocol',
            'kicker' => 'SPECIAL REPORT',
            'subheadline' => 'How triple-hormone drops burn belly fat at home',
            'body_sections' => [['heading' => 'The fat-burning hormones', 'body' => '...']],
        ];

        $v = (new KeywordRelevanceGate)->evaluate($bridge, $this->asset(), $this->pattern());

        $this->assertSame('ok', $v['verdict']);
        $this->assertTrue($v['safe_to_publish']);
        $this->assertSame(100, $v['anchor_rate']);
        $this->assertContains('gelatin', $v['anchored_keywords']);   // anchored via the winning pattern
        $this->assertContains('retatrutide', $v['anchored_keywords']); // anchored via the VSL
    }

    public function test_orphan_thematic_keyword_is_a_crime(): void
    {
        $bridge = [
            'headline' => 'The Keto Carnivore Diet Secret For Belly Fat',
            'kicker' => 'REPORT',
            'subheadline' => 'A simple shift anyone can make',
            'body_sections' => [],
        ];

        $v = (new KeywordRelevanceGate)->evaluate($bridge, $this->asset(), $this->pattern());

        $this->assertSame('critical', $v['verdict']);
        $this->assertFalse($v['safe_to_publish']);
        $orphans = array_column($v['orphan_keywords'], 'keyword');
        $this->assertContains('keto', $orphans);       // never in the VSL or pattern → crime
        $this->assertContains('carnivore', $orphans);
        $this->assertNotContains('fat', $orphans);     // "fat" IS in the VSL → anchored
    }

    public function test_generic_persuasion_words_are_never_crimes(): void
    {
        $bridge = [
            'headline' => 'Discover The Shocking Truth Everyone Is Talking About',
            'kicker' => 'EXCLUSIVE REPORT',
            'subheadline' => 'What they finally revealed',
            'body_sections' => [],
        ];

        $v = (new KeywordRelevanceGate)->evaluate($bridge, $this->asset(), $this->pattern());

        $this->assertTrue($v['safe_to_publish']);   // no thematic keyword at all → nothing to anchor
        $this->assertSame('ok', $v['verdict']);
    }

    public function test_common_words_and_meta_leaks_are_not_crimes(): void
    {
        $bridge = [
            // "different / plans / past / authority" are common words; "fold / teaser" are meta leaks.
            'headline' => 'The Different Plans Women Tried In The Past',
            'kicker' => 'AUTHORITY REPORT',
            'subheadline' => 'A new fold teaser format',
            'body_sections' => [['heading' => 'The retatrutide drops', 'body' => '...']],
        ];

        $v = (new KeywordRelevanceGate)->evaluate($bridge, $this->asset(), $this->pattern());

        $this->assertTrue($v['safe_to_publish']);          // no thematic-niche crime
        $this->assertSame('warn', $v['verdict']);          // but meta leaks were detected
        $this->assertNotEmpty($v['meta_leaks']);
        $this->assertEmpty($v['orphan_keywords']);
    }

    public function test_gelatin_on_a_drops_vsl_is_a_crime_even_though_same_niche(): void
    {
        // Regression: the niche winning-pattern is full of "gelatin trick" (another offer). Anchoring on
        // the VSL ONLY (pattern=null) must flag gelatin as a crime on this retatrutide-drops VSL.
        $bridge = [
            'headline' => 'The Gelatin Trick And Jello Diet Women 40+ Are Searching',
            'kicker' => 'REPORT',
            'subheadline' => 'pink gelatin recipe explained',
            'body_sections' => [],
        ];

        $v = (new KeywordRelevanceGate)->evaluate($bridge, $this->asset(), null, []);

        $this->assertFalse($v['safe_to_publish']);
        $orphans = array_column($v['orphan_keywords'], 'keyword');
        $this->assertContains('gelatin', $orphans);
        $this->assertContains('jello', $orphans);
    }

    public function test_cross_niche_keyword_is_a_crime(): void
    {
        // The VSL is weight_loss; a "prostate" headline matches ANOTHER niche's keywords → crime.
        $bridge = [
            'headline' => 'The Prostate Shrinking Tinnitus Cure',
            'kicker' => '', 'subheadline' => '', 'body_sections' => [],
        ];
        $otherNiche = ['prostate health support', 'tinnitus ringing relief'];

        $v = (new KeywordRelevanceGate)->evaluate($bridge, $this->asset(), $this->pattern(), $otherNiche);

        $this->assertFalse($v['safe_to_publish']);
        $orphans = array_column($v['orphan_keywords'], 'keyword');
        $this->assertContains('prostate', $orphans);
        $this->assertContains('tinnitus', $orphans);
    }

    public function test_keyword_anchored_only_in_pattern_passes_even_if_absent_from_vsl(): void
    {
        // VSL never says "gelatin"; the winning pattern proves it converts → the bridge may use it.
        $asset = new AiMarketingVslAsset([
            'transcript' => 'triple-hormone drops with retatrutide',
            'big_idea' => 'retatrutide drops',
            'niche' => 'weight_loss',
        ]);
        $bridge = [
            'headline' => 'The Pink Gelatin Trick',
            'kicker' => '', 'subheadline' => '', 'body_sections' => [],
        ];

        $v = (new KeywordRelevanceGate)->evaluate($bridge, $asset, $this->pattern());

        $this->assertTrue($v['safe_to_publish']);
        $this->assertContains('gelatin', $v['anchored_keywords']);
    }
}
