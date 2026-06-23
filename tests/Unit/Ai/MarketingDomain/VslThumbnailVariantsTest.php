<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\VslThumbnailGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Locks thumbnail creative-velocity: distinct split-test angles (number / leak / authority) with
 * distinct accent colors and copy, the authority angle only present when a real authority exists.
 * Deterministic; no image assets.
 */
class VslThumbnailVariantsTest extends TestCase
{
    private function asset(bool $withAuthority = true): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset([
            'target_geo' => 'US / English',
            'transcript' => 'no injection, unlike Ozempic',
            'metrics' => ['result_claims' => ['lost 90 lbs']],
            'persuasion_devices' => $withAuthority ? ['authority' => ['Melania Trump']] : [],
        ]);
    }

    public function test_generates_three_distinct_angles_with_distinct_accents(): void
    {
        $v = (new VslThumbnailGenerator)->variants($this->asset());

        $this->assertSame(['number', 'leak', 'authority'], array_keys($v));
        $this->assertStringContainsString('#ffd23f', $v['number']);   // yellow
        $this->assertStringContainsString('#ff5a3c', $v['leak']);     // orange
        $this->assertStringContainsString('#4ea1ff', $v['authority']); // blue
        $this->assertStringContainsString('Melania Trump', $v['authority']);
        $this->assertStringContainsString('They Tried to Ban', $v['leak']);
        // all three must be different SVGs
        $this->assertCount(3, array_unique($v));
    }

    public function test_authority_angle_omitted_when_no_real_authority(): void
    {
        $v = (new VslThumbnailGenerator)->variants($this->asset(withAuthority: false));

        $this->assertArrayNotHasKey('authority', $v);
        $this->assertArrayHasKey('number', $v);
        $this->assertArrayHasKey('leak', $v);
    }
}
