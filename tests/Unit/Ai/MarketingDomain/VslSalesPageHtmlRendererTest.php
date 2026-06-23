<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\VslSalesPageHtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Locks the VSL sales page: a video slot, an offer block that REVEALS after the pitch delay, the
 * guarantee/packages/FAQ assembled from the dissected offer, and a compliant checkout button (nofollow
 * sponsored) routed to the SUPPLIED checkout URL (never invented). Deterministic.
 */
class VslSalesPageHtmlRendererTest extends TestCase
{
    private function asset(): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset([
            'mechanism_name' => 'Triple-Hormone Drops',
            'core_promise' => 'Lose up to 2 lbs a day with a simple nightly ritual',
            'pitch_starts_at_seconds' => 2700,
            'metrics' => [
                'price' => '6 bottles for $49 each',
                'guarantee_days' => 60,
                'scarcity_numbers' => ['Only 84 bottles left in this batch'],
            ],
            'offer' => [
                'packages' => [
                    ['name' => '6 Bottles', 'price' => '$294', 'note' => 'Free shipping'],
                    ['name' => '3 Bottles', 'price' => '$216', 'note' => ''],
                ],
            ],
            'objection_rebuttals' => [
                ['objection' => 'Is this safe?', 'rebuttal' => 'All natural ingredients.'],
            ],
            'persuasion_devices' => ['social_proof' => ['Melissa lost 120 lbs in 3 months']],
        ]);
    }

    public function test_assembles_video_offer_guarantee_and_faq(): void
    {
        $html = (new VslSalesPageHtmlRenderer)->render($this->asset(), ['checkout_url' => 'https://producer.example/order']);

        $this->assertStringContainsString('id="vsl"', $html);
        $this->assertStringContainsString('data-reveal="2700"', $html);   // CTA reveals at the pitch moment
        $this->assertStringContainsString('60-Day Money-Back Guarantee', $html);
        $this->assertStringContainsString('$294', $html);                  // package from the dissected offer
        $this->assertStringContainsString('Only 84 bottles left', $html);  // scarcity from metrics
        $this->assertStringContainsString('Is this safe?', $html);         // objection → FAQ
    }

    public function test_checkout_button_uses_supplied_url_and_is_compliant(): void
    {
        $html = (new VslSalesPageHtmlRenderer)->render($this->asset(), ['checkout_url' => 'https://producer.example/order']);

        $this->assertStringContainsString('href="https://producer.example/order"', $html);
        $this->assertStringContainsString('rel="nofollow sponsored"', $html); // affiliate compliance
        $this->assertStringContainsString('data-goal="buy"', $html);
    }

    public function test_without_checkout_url_buttons_fall_back_to_anchor_not_invented_link(): void
    {
        $html = (new VslSalesPageHtmlRenderer)->render($this->asset());

        $this->assertStringContainsString('href="#order"', $html);          // never invents a hoplink
        $this->assertStringNotContainsString('hop.clickbank', $html);
    }
}
