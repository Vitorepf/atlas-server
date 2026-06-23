<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\HybridPatternScorer;
use App\Services\Ai\MarketingDomain\Knowledge\PersuasionPatternLibrary;
use PHPUnit\Framework\TestCase;

/**
 * Locks the bridge to the flywheel: HybridPatternScorer accepts the same library + copy contract
 * and additionally exposes the craft/learned blend ratio. With niche='' (the default), it's pure
 * craft (no DB hit). The actual blending with real learned weights is exercised through the
 * LearnedWeightLedger math test; this one focuses on the contract + the no-niche fallback.
 */
class HybridPatternScorerTest extends TestCase
{
    public function test_with_no_niche_behaves_like_craft_scorer(): void
    {
        $scorer = new HybridPatternScorer;
        $r = $scorer->score(new PersuasionPatternLibrary,
            'mechanism big pharma guarantee scarcity Melania Trump unlike Ozempic');

        $this->assertSame('persuasion', $r['library']);
        $this->assertGreaterThan(0, $r['score']);
        $this->assertSame(['outcomes' => 0, 'craft_share' => 1.0, 'learned_share' => 0.0], $r['blend']);
        $this->assertNotEmpty($r['present']);
    }

    public function test_blend_curve_outputs_expected_shape(): void
    {
        // Internal contract: 0 outcomes -> 100% craft, the curve will shift as data lands.
        // We only verify the no-data path here; the actual blend with data is integration-grade.
        $r = (new HybridPatternScorer)->score(new PersuasionPatternLibrary, 'guarantee scarcity');

        $this->assertSame(1.0, $r['blend']['craft_share']);
        $this->assertSame(0.0, $r['blend']['learned_share']);
        $this->assertSame(0, $r['blend']['outcomes']);
    }
}
