<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\PatternLibraryScorer;
use App\Services\Ai\MarketingDomain\Knowledge\PatternLibrary;
use App\Services\Ai\MarketingDomain\Knowledge\SalesMomentPatternIndex;
use PHPUnit\Framework\TestCase;

/**
 * SalesMomentPatternIndex — the substrate that indexes every pattern by SALES MOMENT. Tests the solid
 * deterministic deliverable (byMoment/index), the optional 'sales_moment' key, byte-identical backward
 * compatibility of the scorer, and the directional orphan/coverage prior.
 */
class SalesMomentPatternIndexTest extends TestCase
{
    public function test_byMoment_is_pure_sorted_and_phase_isolated(): void
    {
        $idx = new SalesMomentPatternIndex;
        $proof = $idx->byMoment('proof');
        $this->assertNotEmpty($proof);
        $prevWeight = PHP_INT_MAX;
        foreach ($proof as $p) {
            $this->assertSame('proof', $p['sales_moment'], 'byMoment(proof) must only return proof patterns');
            $this->assertLessThanOrEqual($prevWeight, $p['weight'], 'must be weight-desc');
            $prevWeight = $p['weight'];
        }
        // No hook pattern leaks into proof.
        $hookKeys = array_column($idx->byMoment('hook'), 'key');
        $proofKeys = array_column($proof, 'key');
        $this->assertSame([], array_intersect($hookKeys, $proofKeys));
    }

    public function test_index_is_deterministic(): void
    {
        $idx = new SalesMomentPatternIndex;
        $this->assertSame($idx->index(), $idx->index());
        // Every declared moment bucket exists.
        foreach (SalesMomentPatternIndex::MOMENTS as $m) {
            $this->assertArrayHasKey($m, $idx->index());
        }
    }

    public function test_respects_an_explicit_sales_moment_key_including_multi(): void
    {
        $lib = $this->stubLibrary();
        $idx = new SalesMomentPatternIndex([$lib]);
        $this->assertContains('stub_close', array_column($idx->byMoment('close'), 'key'));
        // Multi-moment pattern appears under BOTH declared moments.
        $this->assertContains('stub_multi', array_column($idx->byMoment('proof'), 'key'));
        $this->assertContains('stub_multi', array_column($idx->byMoment('offer'), 'key'));
    }

    public function test_scorer_is_byte_identical_with_the_optional_key(): void
    {
        // The optional 'sales_moment' key must not change PatternLibraryScorer output (anti-refragmentation).
        $scorer = new PatternLibraryScorer;
        $copy = 'order now, only 7 left — 60-day money-back guarantee, this is proven by 312 people.';
        $withKey = $scorer->score($this->stubLibrary(), $copy);
        $withoutKey = $scorer->score($this->stubLibrary(false), $copy);
        $this->assertSame($withoutKey['score'], $withKey['score']);
        $this->assertSame($withoutKey['present'], $withKey['present']);
    }

    public function test_orphan_moments_is_directional(): void
    {
        $idx = new SalesMomentPatternIndex;
        // A page never contains a follow-up email sequence → follow_up is reliably orphan.
        $page = 'If you are a woman over 40, here is how the 3-hormone reset works. Order now before this closes.';
        $this->assertContains('follow_up', $idx->orphanMoments($page));
        // A strong close present → close is NOT orphan.
        $closey = 'Order now — only 7 spots left, the price goes up at midnight, 60-day money-back guarantee, claim yours today.';
        $this->assertNotContains('close', $idx->orphanMoments($closey));
    }

    private function stubLibrary(bool $withKey = true): PatternLibrary
    {
        return new class($withKey) implements PatternLibrary
        {
            public function __construct(private bool $withKey) {}

            public function name(): string
            {
                return 'stub_lib';
            }

            public function categories(): array
            {
                return ['x'];
            }

            public function all(): array
            {
                $close = ['key' => 'stub_close', 'name' => 'Stub close', 'category' => 'x', 'weight' => 5,
                    'trigger' => 't', 'lever' => 'l', 'markers' => ['order now']];
                $multi = ['key' => 'stub_multi', 'name' => 'Stub multi', 'category' => 'x', 'weight' => 4,
                    'trigger' => 't', 'lever' => 'l', 'markers' => ['guarantee']];
                if ($this->withKey) {
                    $close['sales_moment'] = 'close';
                    $multi['sales_moment'] = ['proof', 'offer'];
                }

                return [$close, $multi];
            }
        };
    }
}
