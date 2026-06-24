<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Campaign\KeywordQualityIndex;
use App\Services\Ai\MarketingDomain\Campaign\KeywordUniverseEnumerator;
use PHPUnit\Framework\TestCase;

/**
 * Locks the L2 discovery: the universe of candidates is ENUMERATED deterministically and WITHOUT HOLES
 * (every owned root × every modifier class), with provenance per keyword, and feeds the grading pipeline.
 */
class KeywordUniverseEnumeratorTest extends TestCase
{
    private KeywordUniverseEnumerator $e;

    protected function setUp(): void
    {
        $this->e = new KeywordUniverseEnumerator;
    }

    public function test_enumerates_complete_grid_no_holes(): void
    {
        $u = $this->e->enumerate(['blue salt trick', 'triple hormone drops']);
        $this->assertTrue($u['complete'], 'cobertura sem buracos: toda raiz × toda classe presente');
        foreach ($u['coverage'] as $root => $classes) {
            foreach ($classes as $class => $present) {
                $this->assertTrue($present, "buraco: {$root} × {$class}");
            }
        }
        $this->assertGreaterThan(20, $u['count']);
    }

    public function test_deterministic_same_roots_same_universe_regardless_of_order(): void
    {
        $a = array_column($this->e->enumerate(['a root', 'b root'])['keywords'], 'keyword');
        $b = array_column($this->e->enumerate(['b root', 'a root'])['keywords'], 'keyword');
        $this->assertSame($a, $b, 'mesmo conjunto de roots → mesmo universo, ordem-independente');
    }

    public function test_every_keyword_carries_provenance(): void
    {
        foreach ($this->e->enumerate(['blue salt trick'])['keywords'] as $row) {
            foreach (['keyword', 'root', 'modifier_class', 'tier_hint'] as $k) {
                $this->assertArrayHasKey($k, $row);
            }
            $this->assertMatchesRegularExpression('/^T\d$/', $row['tier_hint']);
        }
    }

    public function test_informational_is_excluded_from_the_universe(): void
    {
        $flat = array_column($this->e->enumerate(['blue salt trick'])['keywords'], 'keyword');
        foreach (['o que é', 'what is', 'significado', 'wikipedia'] as $info) {
            foreach ($flat as $kw) {
                $this->assertStringNotContainsString($info, $kw);
            }
        }
    }

    public function test_expands_short_re_finder_root_variant(): void
    {
        // "triple hormone drops protocol" → the re-finder also types "triple hormone drops".
        $u = $this->e->enumerate(['triple hormone drops protocol']);
        $this->assertContains('triple hormone drops', $u['roots']);
        $this->assertContains('triple hormone drops', array_column($u['keywords'], 'keyword'));
        // but the core device ("trick") is NOT stripped (stripping it loses the mechanism).
        $u2 = $this->e->enumerate(['blue salt trick']);
        $this->assertNotContains('blue salt', $u2['roots']);
    }

    public function test_universe_feeds_the_grading_pipeline_end_to_end(): void
    {
        $asset = new AiMarketingVslAsset(['mechanism_name' => 'Blue Salt Trick', 'niche' => 'weight loss']);
        $u = $this->e->enumerate(['blue salt trick', 'salt trick']);
        $engineShape = $this->e->asScorableTier($u);

        $scored = (new KeywordQualityIndex)->scoreEngineResult($engineShape, $asset, ['payout' => 120, 'cvr' => 0.012])['scored'];

        $this->assertGreaterThanOrEqual(5, count($scored));
        foreach ($scored as $s) {
            $this->assertArrayHasKey('intent', $s);
            $this->assertArrayHasKey('investment', $s);
        }
    }
}
