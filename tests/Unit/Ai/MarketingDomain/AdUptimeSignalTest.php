<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\AdUptimeSignal;
use App\Services\Ai\MarketingDomain\Content\RsaAdForge;
use PHPUnit\Framework\TestCase;

/**
 * AdUptimeSignal surfaces (never blocks) the ad assets that carry a known Google disapproval risk — a
 * disapproved ad does not serve, so the spend it was meant to capture is lost (uptime = ROI). Grounded
 * in the real disapprovals the operator hit: restricted drug terms (Ozempic…) and bandwagon clickbait.
 */
class AdUptimeSignalTest extends TestCase
{
    public function test_restricted_drug_term_is_flagged_high(): void
    {
        $r = (new AdUptimeSignal)->assess(['Ditch Ozempic For This', 'Drops, Not Shots'], []);

        $this->assertSame('high', $r['risk']);
        $this->assertFalse($r['clean']);
        $cats = array_column($r['flagged'], 'category');
        $this->assertContains('restricted_drug_term', $cats);
    }

    public function test_bandwagon_clickbait_is_flagged_medium(): void
    {
        $r = (new AdUptimeSignal)->assess(['Women 40+ Are Switching', 'At-Home Fat Burning'], []);

        $this->assertSame('medium', $r['risk']);
        $this->assertContains('bandwagon_clickbait', array_column($r['flagged'], 'category'));
    }

    public function test_clean_assets_have_no_flags(): void
    {
        $r = (new AdUptimeSignal)->assess(
            ['Drops, Not Shots', 'No Needle, No Diet', 'At-Home Fat Burning'],
            ['Natural GLP-1 support in a simple daily drop. Watch the free presentation inside.'],
        );

        $this->assertTrue($r['clean']);
        $this->assertSame('low', $r['risk']);
        $this->assertSame([], $r['flagged']);
    }

    /**
     * Integration: the REAL RsaAdForge default output trips the signal — proving this catches a live
     * defect, not a hypothetical one (the forge ships "Ditch Ozempic For This" + "Women 40+ Are Switching").
     */
    public function test_real_forge_output_is_flagged(): void
    {
        $asset = new AiMarketingVslAsset(['target_geo' => 'US', 'language' => 'en']);
        $rsa = (new RsaAdForge)->forge($asset, ['lang' => 'en']);

        $r = (new AdUptimeSignal)->assess($rsa['headlines'], $rsa['descriptions']);

        $this->assertFalse($r['clean'], 'o forge gera gatilhos de reprovação conhecidos');
        $this->assertSame('high', $r['risk']); // Ozempic in the default candidates
        $this->assertGreaterThan(0, $r['counts']['restricted_drug_term']);
        $this->assertGreaterThan(0, $r['counts']['bandwagon_clickbait']);
    }
}
