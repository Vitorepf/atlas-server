<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\PatternLibraryScorer;
use App\Services\Ai\MarketingDomain\Knowledge\CloseTacticsLibrary;
use App\Services\Ai\MarketingDomain\Knowledge\SalesMomentPatternIndex;
use PHPUnit\Framework\TestCase;

/**
 * CloseTacticsLibrary — the close-phase moves. Conforms to PatternLibrary, lives at sales_moment=close,
 * is measured by the single scorer, and catalogs the dark closers too (no moral brake).
 */
class CloseTacticsLibraryTest extends TestCase
{
    public function test_conforms_and_is_all_close_moment(): void
    {
        $lib = new CloseTacticsLibrary;
        $this->assertSame('close_tactics', $lib->name());
        $this->assertGreaterThanOrEqual(10, count($lib->all()));
        foreach ($lib->all() as $p) {
            foreach (['key', 'name', 'category', 'weight', 'trigger', 'lever', 'markers', 'sales_moment'] as $f) {
                $this->assertArrayHasKey($f, $p);
            }
            $this->assertSame('close', $p['sales_moment']);
            $this->assertContains($p['category'], $lib->categories());
        }
        $this->assertSame(count($lib->all()), count(array_unique(array_column($lib->all(), 'key'))));
    }

    public function test_scorer_detects_close_moves(): void
    {
        $copy = 'This is not for everyone. The real cost is doing nothing — another year of this. '
            .'You risk nothing: full money-back. Does that make sense? Then the next step is to order now.';
        $present = (new PatternLibraryScorer)->score(new CloseTacticsLibrary, $copy)['present'];
        $this->assertContains('takeaway_qualification', $present);
        $this->assertContains('cost_of_inaction', $present);
        $this->assertContains('risk_reversal_close', $present);
    }

    public function test_indexed_under_the_close_moment(): void
    {
        $idx = new SalesMomentPatternIndex([new CloseTacticsLibrary]);
        $closeKeys = array_column($idx->byMoment('close'), 'key');
        $this->assertContains('certainty_axis_reclose', $closeKeys);
        $this->assertSame([], $idx->byMoment('hook')); // nothing leaks to other moments
    }

    public function test_catalogs_the_dark_closers_no_brake(): void
    {
        $dark = array_filter((new CloseTacticsLibrary)->all(), static fn ($p) => ($p['aggression'] ?? '') === 'dark');
        $keys = array_column($dark, 'key');
        $this->assertContains('takeaway_qualification', $keys);
        $this->assertContains('certainty_axis_reclose', $keys);
    }
}
