<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\RsaAdForge;
use PHPUnit\Framework\TestCase;

/**
 * Locks the Google Search RSA forge: ≤30-char headlines and ≤90-char descriptions (never truncated),
 * a MIX of keyword-match + benefit/curiosity/CTA angles (not all-keyword robotic), health acronyms
 * fixed (GLP-1, GIP), and message-match to the VSL's own keywords. Deterministic.
 */
class RsaAdForgeTest extends TestCase
{
    private function asset(): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset([
            'target_geo' => 'US / English',
            'mechanism_name' => 'Triple Hormone Drops Protocol',
            'transcript' => 'unlike Ozempic',
            'metrics' => ['result_claims' => ['63 lbs em 2 meses']],
            'keywords' => ['clusters' => [
                ['terms' => ['triple hormone drops', 'glp 1 gip glucagon drops', 'retatrutide alternative drops', 'natural glp 1', 'weight loss drops no injection']],
            ]],
        ]);
    }

    public function test_headlines_and_descriptions_respect_google_limits(): void
    {
        $r = (new RsaAdForge)->forge($this->asset());

        $this->assertLessThanOrEqual(15, count($r['headlines']));
        $this->assertLessThanOrEqual(4, count($r['descriptions']));
        $this->assertGreaterThanOrEqual(10, count($r['headlines']));
        foreach ($r['headlines'] as $h) {
            $this->assertLessThanOrEqual(30, mb_strlen($h), "headline over 30: {$h}");
        }
        foreach ($r['descriptions'] as $d) {
            $this->assertLessThanOrEqual(90, mb_strlen($d), "description over 90: {$d}");
        }
    }

    public function test_mixes_keyword_match_with_angles_and_fixes_acronyms(): void
    {
        $r = (new RsaAdForge)->forge($this->asset());
        $joined = implode(' | ', $r['headlines']);

        $this->assertStringContainsString('Triple Hormone Drops', $joined);     // keyword-match
        $this->assertStringContainsString('63 Lbs, No Injections', $joined);     // number angle
        $this->assertStringContainsString('Ditch Ozempic For This', $joined);    // curiosity angle
        $this->assertStringContainsString('GLP-1', $joined);                     // acronym fixed
        $this->assertStringNotContainsString('Glp 1', $joined);
    }
}
