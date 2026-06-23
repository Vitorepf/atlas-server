<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\CreativeVariationGeneratorService;
use App\Services\Ai\MarketingDomain\VideoAdScriptGeneratorService;
use PHPUnit\Framework\TestCase;

class VideoAdScriptGeneratorServiceTest extends TestCase
{
    private function asset(): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset([
            'big_idea' => 'the pink gelatin trick',
            'problem_mechanism' => 'slow metabolism after 40',
            'solution_mechanism' => 'a specific gelatin protocol',
            'mechanism_name' => 'Pink Protocol',
            'claims' => ['clinically studied', 'doctor approved'],
            'cta' => ['text' => 'watch the free presentation'],
            'power_phrases' => ['the pink trick', 'metabolism reset'],
            'top_terms' => ['gelatin', 'metabolism'],
        ]);
    }

    public function test_video_ad_script_has_four_grounded_blocks(): void
    {
        $s = (new VideoAdScriptGeneratorService)->generateVideoAdScript($this->asset());

        $blocks = array_column($s['blocks'], 'block');
        $this->assertSame(['hook', 'amplify', 'bridge', 'cta'], $blocks);
        // hook must enforce no-brand-first-2s
        $this->assertContains('no_brand_first_2s', $s['blocks'][0]['retention_rules_applied']);
        // bridge grounds in the extracted mechanism
        $this->assertStringContainsString('Pink Protocol', $s['blocks'][2]['text']);
    }

    public function test_generates_n_distinct_hook_variations(): void
    {
        $v = (new CreativeVariationGeneratorService)->generateHookVariations($this->asset(), 6);

        $this->assertCount(6, $v);
        // distinct pattern-interrupt archetypes
        $this->assertGreaterThanOrEqual(5, count(array_unique(array_column($v, 'pattern_interrupt_type'))));
        $this->assertTrue($v[0]['no_brand_first_2s']);
    }

    public function test_variation_generator_is_deterministic(): void
    {
        $a = $this->asset();
        $g = new CreativeVariationGeneratorService;
        $this->assertSame($g->generateHookVariations($a, 4), $g->generateHookVariations($a, 4));
    }
}
