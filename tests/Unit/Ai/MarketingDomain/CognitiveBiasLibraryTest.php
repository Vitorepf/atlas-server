<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\PatternLibraryScorer;
use App\Services\Ai\MarketingDomain\Knowledge\CognitiveBiasLibrary;
use PHPUnit\Framework\TestCase;

/**
 * Locks the behavioral-economics layer: a strong offer fires the core biases (anchoring, loss
 * aversion, decoy, charm pricing via regex), a flat page scores zero, and the biases generalize across
 * niches — all through the one generic scorer.
 */
class CognitiveBiasLibraryTest extends TestCase
{
    private string $strong = 'Normally $297, today just $47. Your kit is reserved for you. Most popular: the 6-bottle kit, '
        .'best value per bottle. Don\'t miss out before you lose this. You\'ve already tried every diet — '
        .'imagine having this. Simple, easy, in 3 seconds. And the best part is the guarantee.';

    public function test_detects_core_biases_including_charm_pricing_regex(): void
    {
        $r = (new PatternLibraryScorer)->score(new CognitiveBiasLibrary, $this->strong);

        $this->assertSame('cognitive_bias', $r['library']);
        $this->assertGreaterThanOrEqual(50, $r['score']);
        $this->assertContains('anchoring', $r['present']);
        $this->assertContains('loss_aversion', $r['present']);
        $this->assertContains('decoy_effect', $r['present']);
        $this->assertContains('charm_pricing', $r['present']);   // regex marker on "$47"
    }

    public function test_flat_page_scores_zero(): void
    {
        $r = (new PatternLibraryScorer)->score(new CognitiveBiasLibrary, 'Our product is good. It helps people. Buy it on our website.');
        $this->assertSame(0, $r['score']);
        $this->assertSame('flat', $r['grade']);
    }

    public function test_generalizes_to_finance(): void
    {
        $finance = 'Worth $5,000, yours for $97. We recommend the pro plan, most choose it. Before you lose this '
            .'window. After all the years you invested. Just one decision, step by step.';
        $r = (new PatternLibraryScorer)->score(new CognitiveBiasLibrary, $finance);

        $this->assertGreaterThanOrEqual(4, count($r['present']));
        $this->assertContains('anchoring', $r['present']);
        $this->assertContains('default_bias', $r['present']);
    }
}
