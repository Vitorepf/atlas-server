<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\PatternLibraryScorer;
use App\Services\Ai\MarketingDomain\Knowledge\VideoCreativeAnatomyLibrary;
use PHPUnit\Framework\TestCase;

/**
 * VideoCreativeAnatomyLibrary — the foundation for dissecting YT/Meta video creatives. Verifies it
 * conforms to the PatternLibrary contract (one shape → the single scorer measures it) and that it
 * actually detects the anatomy + malícias in a real creative transcript.
 */
class VideoCreativeAnatomyLibraryTest extends TestCase
{
    public function test_conforms_to_the_pattern_library_contract(): void
    {
        $lib = new VideoCreativeAnatomyLibrary;
        $this->assertSame('video_creative_anatomy', $lib->name());
        $cats = $lib->categories();
        $this->assertNotEmpty($cats);
        $this->assertGreaterThanOrEqual(20, count($lib->all()));
        foreach ($lib->all() as $p) {
            foreach (['key', 'name', 'category', 'weight', 'trigger', 'lever', 'markers'] as $field) {
                $this->assertArrayHasKey($field, $p);
            }
            $this->assertContains($p['category'], $cats, "category {$p['category']} must be declared");
            $this->assertIsArray($p['markers']);
            $this->assertNotEmpty($p['markers']);
            $this->assertGreaterThanOrEqual(1, $p['weight']);
        }
        // Keys are unique.
        $keys = array_column($lib->all(), 'key');
        $this->assertSame(count($keys), count(array_unique($keys)));
    }

    public function test_dissects_a_real_ugc_creative_transcript(): void
    {
        // A typical aggressive UGC weight-loss ad — hook + fake-organic + conspiracy + before/after + CTA.
        $creative = "Stop scrolling. I am just a mom who discovered the real reason the weight would not "
            ."come off — and it is not what they want you to know. Big pharma has been hiding this. "
            ."Before and after: I lost 34 lbs. Watch the free presentation before it is taken down.";
        $present = (new PatternLibraryScorer)->score(new VideoCreativeAnatomyLibrary, $creative)['present'];
        // It must catch the hook, the fake-organic + conspiracy malícias, the proof, and the CTA.
        $this->assertContains('vc_hook_pattern_interrupt', $present);
        $this->assertContains('vc_malicia_fake_organic', $present);
        $this->assertContains('vc_malicia_conspiracy_hook', $present);
        $this->assertContains('vc_proof_before_after', $present);
        $this->assertContains('vc_cta_soft_content', $present);
    }

    public function test_includes_the_malicias_as_knowledge_no_moral_brake(): void
    {
        // Per the operator's pétreo rule: the engine catalogs the aggressive devices fully.
        $malicias = array_filter((new VideoCreativeAnatomyLibrary)->all(), static fn ($p) => $p['category'] === 'malicia');
        $keys = array_column($malicias, 'key');
        foreach (['vc_malicia_fake_organic', 'vc_malicia_borrowed_celebrity', 'vc_malicia_manufactured_authority', 'vc_malicia_news_framing'] as $k) {
            $this->assertContains($k, $keys);
        }
    }
}
