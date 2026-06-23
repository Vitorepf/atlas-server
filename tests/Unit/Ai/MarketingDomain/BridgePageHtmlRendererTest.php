<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\BridgePageHtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Locks the rendered bridge shape: a real, self-contained, conversion-structured page — H1, a VSL
 * slot, every CTA routed to #vsl (one goal), trust above the fold, numbered listicle items with
 * open-loops, and a disclosure. Deterministic.
 */
class BridgePageHtmlRendererTest extends TestCase
{
    private function bridge(): array
    {
        return [
            'meta' => ['lang' => 'en', 'seo_title' => 'The Gelatin Trick', 'seo_description' => 'desc'],
            'kicker' => 'SPECIAL HEALTH REPORT',
            'headline' => 'The At-Home GLP-1 Trick Women Over 40 Are Talking About',
            'subheadline' => 'A simple nightly ritual that many say changed everything.',
            'hero_cta' => ['label' => 'Watch the free presentation →', 'target' => '#vsl'],
            'trust_bar' => ['150,000+ women', '4.8/5 rating'],
            'lead_paragraph' => "If you are a woman over 40, this matters.\n\nA hidden hormone may be the reason.",
            'mechanism_tease' => 'Three hormones control fat-burning. The recipe is in the presentation.',
            'body_sections' => [
                ['heading' => 'It was never willpower', 'body' => 'Long original paragraph one.', 'open_loop' => 'See why in the video.'],
                ['heading' => 'The hormone switch', 'body' => 'Long original paragraph two.', 'open_loop' => 'She explains above.'],
            ],
            'proof_block' => [
                'testimonials' => [['name' => 'Amy', 'result' => '-34 lbs', 'quote' => 'It worked.']],
                'stat_callouts' => ['96% in an internal study'],
            ],
            'objection_flips' => [['objection' => 'Is it a scam?', 'flip' => 'Watch how it works.']],
            'cta_blocks' => [
                ['label' => 'Watch now', 'target' => '#vsl', 'subtext' => 'Free, no signup'],
                ['label' => 'See the video', 'target' => '#vsl'],
            ],
            'ps' => 'Stocks are limited this week.',
            'disclosure' => 'Advertising content. Individual results vary.',
        ];
    }

    public function test_renders_a_complete_conversion_page(): void
    {
        $html = (new BridgePageHtmlRenderer)->render($this->bridge(), ['brand' => 'Daily Wellness']);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringContainsString('<h1>', $html);
        $this->assertStringContainsString('The At-Home GLP-1 Trick', $html);
        $this->assertStringContainsString('id="vsl"', $html);
        $this->assertStringContainsString('Daily Wellness', $html);
        $this->assertStringContainsString('Advertising content', $html);
        // open-loop and numbered listicle present
        $this->assertStringContainsString('See why in the video', $html);
        $this->assertStringContainsString('class="num"', $html);
    }

    public function test_every_cta_routes_to_the_vsl_anchor(): void
    {
        $html = (new BridgePageHtmlRenderer)->render($this->bridge());

        // no CTA escapes to an external/checkout target
        preg_match_all('/<a class="cta[^"]*" href="([^"]+)"/', $html, $m);
        $this->assertNotEmpty($m[1]);
        foreach ($m[1] as $href) {
            $this->assertSame('#vsl', $href);
        }
    }

    public function test_fixed_labels_follow_the_page_language(): void
    {
        $bridge = $this->bridge();
        $bridge['meta']['lang'] = 'English (US)';
        $bridge['mechanism_tease'] = 'A mechanism';
        $html = (new BridgePageHtmlRenderer)->render($bridge);

        // an English page must not carry Portuguese chrome
        $this->assertStringNotContainsString('O mecanismo', $html);
        $this->assertStringNotContainsString('Mas e se', $html);
        $this->assertStringNotContainsString('Publieditorial', $html);
        $this->assertStringContainsString('The mechanism', $html);

        // a PT page gets PT labels
        $bridge['meta']['lang'] = 'Português (BR)';
        $ptHtml = (new BridgePageHtmlRenderer)->render($bridge);
        $this->assertStringContainsString('O mecanismo', $ptHtml);
    }

    public function test_ps_label_is_not_duplicated(): void
    {
        $bridge = $this->bridge();
        $bridge['ps'] = 'P.S. Do not wait — the stock is limited.';
        $html = (new BridgePageHtmlRenderer)->render($bridge);

        $this->assertStringNotContainsString('P.S.</strong> P.S.', $html);
        $this->assertStringContainsString('Do not wait', $html);
    }

    public function test_escapes_untrusted_text(): void
    {
        $bridge = $this->bridge();
        $bridge['headline'] = 'Trick <script>alert(1)</script> & more';
        $html = (new BridgePageHtmlRenderer)->render($bridge);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
