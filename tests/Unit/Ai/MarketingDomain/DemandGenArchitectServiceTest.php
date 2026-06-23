<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Campaign\DemandGenArchitectService;
use PHPUnit\Framework\TestCase;

class DemandGenArchitectServiceTest extends TestCase
{
    private function asset(): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset([
            'big_idea' => 'the pink gelatin trick',
            'problem_mechanism' => 'slow metabolism',
            'power_phrases' => ['the pink trick'],
        ]);
    }

    public function test_builds_asset_group_with_five_videos_and_troas(): void
    {
        $dg = (new DemandGenArchitectService)->build($this->asset());

        $this->assertSame('youtube_demand_gen', $dg['channel']);
        $this->assertCount(5, $dg['asset_group']['video_concepts']);
        $this->assertStringContainsString('tROAS', $dg['asset_group']['bidding']['edge'] ?? $dg['asset_group']['bidding']['what']);
        $this->assertArrayHasKey('nca_goal', $dg['asset_group']);
    }

    public function test_best_practices_checklist_drives_expected_lift(): void
    {
        $dg = (new DemandGenArchitectService)->build($this->asset());

        $this->assertCount(4, $dg['best_practices_checklist']);
        // troas + consolidated + 5 videos = at least 3 adopted even without a pattern
        $this->assertGreaterThanOrEqual(3, $dg['best_practices_adopted']);
        $this->assertStringContainsString('+40%', $dg['expected_lift']);
    }
}
