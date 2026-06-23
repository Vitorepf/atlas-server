<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\CopySmellDetector;
use PHPUnit\Framework\TestCase;

/**
 * Locks the affiliate/VSL-specific smell detector. Goes deeper than AntiGoodhartGuard (which catches
 * generic hollowness) — these are smells that kill VSL/affiliate copy specifically: weak CTA, missing
 * or numberless guarantee, generic hook, vague proof, unbounded promise, premature buy CTA on bridge,
 * unanchored price, "just" overuse.
 */
class CopySmellDetectorTest extends TestCase
{
    public function test_bad_copy_triggers_multiple_smells(): void
    {
        $bad = 'Are you tired of yo-yo dieting? Many people just like you have lost 30 lbs '
            .'with our amazing $49 supplement. Just take it, just feel better. Learn more. Buy now.';
        $r = (new CopySmellDetector)->inspect($bad);
        $this->assertGreaterThanOrEqual(5, $r['n'], 'Bad copy must trigger 5+ smells.');
        $keys = array_column($r['smells'], 'key');
        $this->assertContains('weak_cta', $keys);
        $this->assertContains('generic_hook', $keys);
        $this->assertContains('premature_buy_cta', $keys);
    }

    public function test_good_copy_passes_clean(): void
    {
        $good = 'For women 47+: 12,847 women lost 30+ lbs in 90 days. Normally $97, today only $49. '
            .'Backed by our 60-day money-back guarantee. Watch the free presentation first.';
        $r = (new CopySmellDetector)->inspect($good);
        $this->assertSame(0, $r['n'], 'Good copy must produce zero smells.');
    }

    public function test_numberless_guarantee_flagged(): void
    {
        $r = (new CopySmellDetector)->inspect('Backed by our money-back guarantee.');
        $keys = array_column($r['smells'], 'key');
        $this->assertContains('numberless_guarantee', $keys);
    }

    public function test_every_smell_carries_concrete_fix(): void
    {
        $r = (new CopySmellDetector)->inspect('Are you tired of dieting? Learn more.');
        foreach ($r['smells'] as $s) {
            $this->assertNotEmpty($s['fix']);
            $this->assertNotEmpty($s['evidence']);
            $this->assertContains($s['severity'], ['critical', 'high', 'medium', 'low']);
        }
    }
}
