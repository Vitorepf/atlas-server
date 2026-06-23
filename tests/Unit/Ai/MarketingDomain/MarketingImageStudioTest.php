<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\ImageGenerationProvider;
use App\Services\Ai\MarketingDomain\Content\MarketingImageStudio;
use PHPUnit\Framework\TestCase;

/**
 * Locks the legitimate creative generator: grounded prompts per kind (mockup/mechanism/lifestyle/
 * thumbnail), generation delegated to the pluggable provider (null when unavailable), and an explicit
 * refusal surface — there is no "result proof"/before-after kind, because synthetic proof is the
 * fabricated-evidence path. Deterministic prompts.
 */
class MarketingImageStudioTest extends TestCase
{
    private function asset(): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset([
            'offer' => ['product_name' => 'Lipo Bliss'],
            'mechanism_name' => 'Triple Hormone Drops Protocol',
            'niche' => 'weight loss for women',
        ]);
    }

    public function test_prompts_are_grounded_per_kind_and_avoid_people_in_product_shots(): void
    {
        $s = new MarketingImageStudio;
        $this->assertStringContainsString('Lipo Bliss', $s->prompt($this->asset(), 'product_mockup'));
        $this->assertStringContainsString('dropper bottle', $s->prompt($this->asset(), 'product_mockup'));
        $this->assertStringContainsStringIgnoringCase('no people', $s->prompt($this->asset(), 'product_mockup'));
        $this->assertStringContainsString('GLP-1', $s->prompt($this->asset(), 'mechanism_illustration'));
        $this->assertSame('', $s->prompt($this->asset(), 'before_after'));   // no such kind by design
        $this->assertSame('', $s->prompt($this->asset(), 'result_proof'));
    }

    public function test_generation_delegates_to_provider(): void
    {
        $ok = new MarketingImageStudio(new class implements ImageGenerationProvider
        {
            public function generate(string $prompt, array $opts = []): ?string
            {
                return 'https://cdn.example.com/'.md5($prompt).'.png';
            }
        });
        $this->assertStringStartsWith('https://cdn.example.com/', (string) $ok->generate($this->asset(), 'product_mockup'));
    }

    public function test_degrades_to_null_when_provider_unavailable_or_kind_invalid(): void
    {
        $off = new MarketingImageStudio(new class implements ImageGenerationProvider
        {
            public function generate(string $prompt, array $opts = []): ?string
            {
                return null;
            }
        });
        $this->assertNull($off->generate($this->asset(), 'product_mockup'));   // provider off
        $this->assertNull((new MarketingImageStudio)->generate($this->asset(), 'before_after')); // invalid kind
    }
}
