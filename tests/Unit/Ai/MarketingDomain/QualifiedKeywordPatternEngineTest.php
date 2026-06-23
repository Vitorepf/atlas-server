<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Campaign\QualifiedKeywordPatternEngine;
use PHPUnit\Framework\TestCase;

/**
 * Locks the memory-recall-arbitrage keyword pattern: the qualified Google Search traffic for a VSL
 * offer comes from POST-EXPOSURE re-finders typing the OWNED root (mechanism/trick/slogan/celebrity),
 * never the product name. keyword = {owned_root} × {intent_modifier}; keep IFF the root is offer-owned.
 */
class QualifiedKeywordPatternEngineTest extends TestCase
{
    private function asset(): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset([
            'mechanism_name' => 'Triple Hormone Drops Protocol',
            'trick' => 'at-home retatrutide protocol',
            'niche' => 'weight loss',
            'angle' => 'a home-made retatrutide that activates GLP-1, GIP and glucagon',
            'hook' => 'Melania Trump lost 63 lbs on a free government at-home protocol',
            'power_phrases' => ['four simple ingredients', 'activates three fat-burning hormones', 'Make America Skinny Again', 'weight loss'],
            'persuasion_devices' => ['authority' => ['Melania Trump', 'Dr. Mehmet Oz', 'Harvard University']],
            'offer' => ['product_name' => 'Lipo Bliss'],
        ]);
    }

    public function test_mechanism_trick_tier_is_hyper_and_present(): void
    {
        $r = (new QualifiedKeywordPatternEngine)->build($this->asset());
        $mech = collect($r['tiers'])->firstWhere('family', 'mechanism_trick');
        $this->assertNotNull($mech);
        $this->assertSame('hyper', $mech['qualification']);
        $this->assertContains('triple hormone drops protocol', $mech['keywords']);
        // The short variant (suffix stripped) is generated.
        $this->assertContains('triple hormone drops', $mech['keywords']);
    }

    public function test_product_name_never_appears_in_any_keyword(): void
    {
        $r = (new QualifiedKeywordPatternEngine)->build($this->asset());
        foreach ($r['flat'] as $kw) {
            $this->assertStringNotContainsString('lipo bliss', $kw);
        }
        $this->assertContains('lipo bliss', $r['negatives']);
    }

    public function test_no_keyword_has_a_duplicated_word(): void
    {
        $r = (new QualifiedKeywordPatternEngine)->build($this->asset());
        foreach ($r['flat'] as $kw) {
            $this->assertSame(0, preg_match('/\b(\w+)\b\s+\1\b/', $kw), "duplicated word in: {$kw}");
        }
    }

    public function test_slogan_is_detected_and_becomes_hyper_tier(): void
    {
        $r = (new QualifiedKeywordPatternEngine)->build($this->asset());
        $this->assertSame('make america skinny again', $r['artifacts']['slogan']);
        $slogan = collect($r['tiers'])->firstWhere('family', 'slogan');
        $this->assertNotNull($slogan);
        $this->assertContains('make america skinny again where to buy', $slogan['keywords']);
    }

    public function test_celebrity_is_always_compounded_never_bare(): void
    {
        $r = (new QualifiedKeywordPatternEngine)->build($this->asset());
        $cel = collect($r['tiers'])->firstWhere('family', 'celebrity');
        $this->assertNotNull($cel);
        // 'melania trump' on its own (bare gossip) must never be a keyword.
        $this->assertNotContains('melania trump', $cel['keywords']);
        $this->assertContains('melania trump weight loss protocol', $cel['keywords']);
        // An institution (Harvard) must NOT be treated as a celebrity.
        foreach ($r['flat'] as $kw) {
            $this->assertStringNotContainsString('harvard', $kw);
        }
    }

    public function test_keep_kill_drops_bare_generic_and_bare_drug(): void
    {
        $e = new QualifiedKeywordPatternEngine;
        $r = $e->build($this->asset());
        // bare generic head terms are negatives, never keywords
        $this->assertContains('weight loss', $r['negatives']);
        $this->assertNotContains('weight loss', $r['flat']);
        // every kept keyword carries an owned root or an anchored category term (none is a bare drug)
        $this->assertNotContains('retatrutide', $r['flat']);
        $this->assertNotContains('ozempic', $r['flat']);
    }

    public function test_pattern_rules_are_exposed_for_reuse(): void
    {
        $r = (new QualifiedKeywordPatternEngine)->build($this->asset());
        $this->assertNotEmpty($r['pattern']);
        $this->assertStringContainsString('owned_root', implode(' ', $r['pattern']));
    }
}
