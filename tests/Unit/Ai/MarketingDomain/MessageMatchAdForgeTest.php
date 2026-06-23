<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\MessageMatchAdForge;
use PHPUnit\Framework\TestCase;

/**
 * Locks the Quality-Score engineering: the forge produces a policy-aware RSA whose keyword ↔ headline ↔
 * advertorial-H1 message match maximizes all three QS levers (expected CTR / ad relevance / landing
 * page experience), within Google's RSA mechanics (≤30-char headlines, ≤90-char descriptions, keyword
 * coverage, uniqueness, CTA), and screens the weight-loss compliance lexicon.
 */
class MessageMatchAdForgeTest extends TestCase
{
    private function forge(): array
    {
        return (new MessageMatchAdForge)->forge(
            ['family' => 'mechanism_trick', 'root' => 'triple hormone drops'],
            ['h1' => 'The Triple Hormone Drops Protocol Women Over 40 Are Using'],
        );
    }

    public function test_headlines_respect_30_char_limit(): void
    {
        foreach ($this->forge()['headlines'] as $h) {
            $this->assertLessThanOrEqual(30, mb_strlen($h), "headline over 30 chars: {$h}");
        }
    }

    public function test_descriptions_respect_90_char_limit_and_min_count(): void
    {
        $d = $this->forge()['descriptions'];
        $this->assertGreaterThanOrEqual(3, count($d));
        foreach ($d as $desc) {
            $this->assertLessThanOrEqual(90, mb_strlen($desc));
        }
    }

    public function test_keyword_appears_in_at_least_three_headlines(): void
    {
        $this->assertGreaterThanOrEqual(3, $this->forge()['keyword_in_headline_count']);
    }

    public function test_message_match_and_strength_are_high_for_owned_root(): void
    {
        $r = $this->forge();
        $this->assertGreaterThanOrEqual(0.5, $r['message_match']);
        $this->assertContains($r['ad_strength'], ['Good', 'Excellent']);
        $this->assertTrue($r['has_cta']);
    }

    public function test_headlines_are_unique(): void
    {
        $hl = $this->forge()['headlines'];
        $this->assertSame(count($hl), count(array_unique($hl)));
        $this->assertGreaterThan(0.5, $this->forge()['uniqueness']);
    }

    public function test_compliance_lexicon_blocks_risky_phrasing(): void
    {
        // "guaranteed" / "miracle" must never survive into generated copy.
        $r = (new MessageMatchAdForge)->forge(
            ['root' => 'triple hormone drops'],
            ['benefits' => ['Guaranteed Results', 'Miracle Fat Cure', 'No Injection No Needle']],
        );
        foreach ($r['headlines'] as $h) {
            $this->assertStringNotContainsStringIgnoringCase('guaranteed', $h);
            $this->assertStringNotContainsStringIgnoringCase('miracle', $h);
        }
        // the compliant benefit still made it in
        $this->assertContains('No Injection No Needle', $r['headlines']);
    }

    public function test_low_h1_match_raises_a_landing_page_warning(): void
    {
        $r = (new MessageMatchAdForge)->forge(
            ['root' => 'triple hormone drops'],
            ['h1' => 'Completely unrelated cooking recipes for autumn'],
        );
        $this->assertNotNull($r['h1_match']);
        $this->assertLessThan(0.5, $r['h1_match']);
        $this->assertNotEmpty(array_filter($r['warnings'], fn ($w) => str_contains($w, 'landing page')));
    }
}
