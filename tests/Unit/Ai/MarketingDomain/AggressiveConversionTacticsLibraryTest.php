<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\PatternLibraryScorer;
use App\Services\Ai\MarketingDomain\Knowledge\AggressiveConversionTacticsLibrary;
use PHPUnit\Framework\TestCase;

/**
 * AggressiveConversionTacticsLibrary — the dirty playbook the Atlas must MASTER (no brake). Proves the OS
 * can SEE and score the most aggressive mechanics (to spy competitor swipe files and to deploy), the
 * same way it scores any other library — no moral guard, pure capability.
 */
class AggressiveConversionTacticsLibraryTest extends TestCase
{
    private string $aggressive = 'WARNING: before it is too late. Only 100 spots left — cart closes tonight '
        .'at midnight. 47,000 people already joined. As seen on CBS, Dr. Lee confirms it. They don\'t want '
        .'you to know this. Normally worth $4,997, today only $47. 60-day money-back guarantee, no questions. '
        .'Imagine waking up in 30 days. Smart people already grabbed theirs — don\'t be the last.';

    private string $tame = 'Our program offers a thoughtful approach to wellness. It includes several modules '
        .'and a community. Many people find it helpful. You can learn more on our about page whenever you like.';

    public function test_detects_dense_aggressive_tactics(): void
    {
        $r = (new PatternLibraryScorer)->score(new AggressiveConversionTacticsLibrary, $this->aggressive);
        $this->assertGreaterThan(60, $r['score'], 'a page stacked with aggressive mechanics must score high');
        $this->assertContains('manufactured_scarcity', $r['present']);
        $this->assertContains('fear_amplification', $r['present']);
        $this->assertContains('price_anchoring_extreme', $r['present']);
    }

    public function test_tame_copy_scores_low(): void
    {
        $r = (new PatternLibraryScorer)->score(new AggressiveConversionTacticsLibrary, $this->tame);
        $this->assertLessThan(20, $r['score']);
    }

    public function test_generalizes_cross_niche(): void
    {
        $scorer = new PatternLibraryScorer;
        $lib = new AggressiveConversionTacticsLibrary;
        $finance = 'Only 50 seats left, doors close at midnight. 12,000 traders already inside. As seen on '
            .'Bloomberg, as endorsed by a famous investor. Real results, before and after screenshots. While you '
            .'wait, others are getting rich. Normally worth $5,000, today $97. Guaranteed results. Imagine your new account.';
        $relationship = 'Last chance — before it is too late to get him back. 30,000 women already used this. '
            .'Don\'t be the last. They don\'t want you to know this trick the stars use. Real customer before and '
            .'after. Works for everyone. Imagine waking up next to him again.';
        $this->assertGreaterThan(40, $scorer->score($lib, $finance)['score']);
        $this->assertGreaterThan(40, $scorer->score($lib, $relationship)['score']);
    }

    public function test_library_contract_is_valid(): void
    {
        $lib = new AggressiveConversionTacticsLibrary;
        $cats = $lib->categories();
        foreach ($lib->all() as $p) {
            foreach (['key', 'name', 'category', 'weight', 'trigger', 'lever', 'markers'] as $f) {
                $this->assertArrayHasKey($f, $p, "pattern missing {$f}");
            }
            $this->assertContains($p['category'], $cats, "pattern {$p['key']} has unknown category {$p['category']}");
            $this->assertNotEmpty($p['markers']);
        }
    }
}
