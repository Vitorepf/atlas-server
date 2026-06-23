<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\BridgeHeadlineForge;
use PHPUnit\Framework\TestCase;

/**
 * Locks the elite headline forge: it weaves the VSL's real ammunition (number + named authority +
 * mechanism + enemy + avatar) into proven headline formulas, with correct grammar and no empty slots —
 * deterministically, without the weak LLM. This is the conversion-critical line, so it must be clean.
 */
class BridgeHeadlineForgeTest extends TestCase
{
    private function asset(): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset([
            'niche' => 'weight_loss',
            'target_geo' => 'US / English',
            'mechanism_name' => 'Triple Hormone Drops Protocol',
            'trick' => 'at-home retatrutide protocol: turmeric, green tea, berberine, resveratrol',
            'transcript' => 'no injection, no needle, unlike Ozempic and Mounjaro',
            'metrics' => ['result_claims' => ['lost 90 lbs', 'lost 200 lbs']],
            'avatar' => ['quem' => 'women over 40', 'dores' => ['your body stores everything as fat']],
            'persuasion_devices' => [
                'authority' => ['Melania Trump', 'Dr. Peter Attia'],
                'conspiracy' => 'big pharma hides the cure',
            ],
        ]);
    }

    public function test_forges_clean_elite_headlines_from_real_ammunition(): void
    {
        $hs = (new BridgeHeadlineForge)->forge($this->asset());
        $joined = implode("\n", $hs);

        $this->assertNotEmpty($hs);
        // weaves the real number (in the believable band, not the absurd 200) + authority + mechanism + enemy
        $this->assertStringContainsString('90 Lbs', $joined);
        $this->assertStringContainsString('Melania Trump', $joined);
        $this->assertStringContainsString('Triple Hormone Drops Protocol', $joined);
        $this->assertStringContainsString('Big Pharma', $joined);
        $this->assertStringContainsString('Women Over 40', $joined);
        // no bugs: no empty age, no duplicated category, no plural "If You're Women"
        $this->assertStringNotContainsString('Women Over Found', $joined);
        $this->assertStringNotContainsString('Over  ', $joined);
        $this->assertStringNotContainsString('At-Home At-Home', $joined);
        $this->assertStringContainsString("If You're a Woman Over 40", $joined);
        $this->assertStringNotContainsString('200 Lbs', $joined); // absurd number rejected
    }

    public function test_top_headline_is_a_strong_news_hook(): void
    {
        $hs = (new BridgeHeadlineForge)->forge($this->asset());
        // #1 carries number + authority + mechanism + differentiator
        $this->assertStringContainsString('Melania Trump', $hs[0]);
        $this->assertStringContainsString('90 Lbs', $hs[0]);
        $this->assertStringContainsString('No Injections', $hs[0]);
    }
}
