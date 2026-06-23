<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\LeadForge;
use PHPUnit\Framework\TestCase;

/**
 * Locks the forged bridge lead: it must address the reader directly (callout), name the failed
 * solution / common enemy, plant the named mechanism, paint a concrete scene, and read as a human —
 * never a journalistic phenomenon report. Market-language clean. Deterministic.
 */
class LeadForgeTest extends TestCase
{
    private function asset(): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset([
            'target_geo' => 'US / English',
            'niche' => 'weight loss for women',
            'mechanism_name' => 'Triple Hormone Drops Protocol',
            'transcript' => 'unlike Ozempic and Mounjaro injections',
            'avatar' => ['quem' => 'women over 40'],
            'persuasion_devices' => ['conspiracy' => ['big pharma hides it']],
        ]);
    }

    public function test_lead_is_human_calls_out_avatar_and_plants_mechanism(): void
    {
        $lead = (new LeadForge)->forge($this->asset());

        $this->assertStringContainsStringIgnoringCase('you', $lead);                  // direct address
        $this->assertStringContainsString('woman over 40', $lead);                    // avatar callout
        $this->assertStringContainsString('Ozempic', $lead);                          // failed solution
        $this->assertStringContainsString('Triple Hormone Drops Protocol', $lead);    // mechanism plant
        $this->assertStringContainsStringIgnoringCase('mirror', $lead);               // concrete scene
        $this->assertGreaterThanOrEqual(4, count(explode("\n\n", $lead)));            // multi-paragraph
        $this->assertGreaterThan(60, str_word_count($lead));
    }

    public function test_english_lead_has_no_portuguese_leak(): void
    {
        $lead = mb_strtolower((new LeadForge)->forge($this->asset()));
        foreach (['você', 'mulher', 'corpo', 'agulha', 'espelho'] as $pt) {
            $this->assertStringNotContainsString($pt, $lead);
        }
    }
}
