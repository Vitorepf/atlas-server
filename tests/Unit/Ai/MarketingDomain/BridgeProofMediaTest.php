<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\BridgePageHtmlRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Locks native photo support in the bridge proof block: a real profile photo renders as <img> only
 * when a safe https URL is supplied, a before/after grid renders only when BOTH release-approved image
 * URLs are present, and unsafe/non-https values are rejected (never echoed) — so the motor supports
 * real producer photos without ever fabricating or injecting a bad URL. Deterministic.
 */
class BridgeProofMediaTest extends TestCase
{
    private function render(array $proof): string
    {
        return (new BridgePageHtmlRenderer)->render([
            'meta' => ['lang' => 'en'],
            'headline' => 'H',
            'proof_block' => $proof,
        ]);
    }

    public function test_avatar_renders_only_with_a_safe_https_url(): void
    {
        $html = $this->render(['testimonials' => [
            ['name' => 'Amy R.', 'result' => '-41 lbs', 'quote' => 'Down 41 pounds.', 'avatar_url' => 'https://cdn.example.com/amy.jpg'],
            ['name' => 'Eve', 'result' => '-30 lbs', 'quote' => 'Great.', 'avatar_url' => 'javascript:alert(1)'],
        ]]);

        $this->assertStringContainsString('<img class="tav" src="https://cdn.example.com/amy.jpg"', $html);
        $this->assertStringNotContainsString('javascript:', $html);   // unsafe url rejected, not echoed
    }

    public function test_before_after_grid_renders_only_when_both_images_present(): void
    {
        $html = $this->render([
            'testimonials' => [['name' => 'Amy', 'quote' => 'x']],
            'transformations' => [
                ['name' => 'Amy R.', 'result' => '-41 lbs', 'before_url' => 'https://cdn.example.com/b.jpg', 'after_url' => 'https://cdn.example.com/a.jpg'],
                ['name' => 'NoAfter', 'before_url' => 'https://cdn.example.com/b2.jpg'], // missing after → skipped
            ],
        ]);

        $this->assertStringContainsString('class="bagrid"', $html);
        $this->assertStringContainsString('https://cdn.example.com/b.jpg', $html);
        $this->assertStringContainsString('https://cdn.example.com/a.jpg', $html);
        $this->assertStringContainsString('−41 lbs', str_replace('-41', '−41', $html));
        $this->assertStringNotContainsString('b2.jpg', $html);   // incomplete pair dropped
    }

    public function test_no_media_keys_keeps_the_classic_text_proof(): void
    {
        $html = $this->render(['testimonials' => [['name' => 'Amy', 'result' => '-41 lbs', 'quote' => 'Down 41.']]]);

        $this->assertStringContainsString('tcard', $html);
        $this->assertStringNotContainsString('<div class="bagrid">', $html);  // grid div not emitted (CSS class def may still exist)
        $this->assertStringNotContainsString('<img class="tav"', $html);
    }
}
