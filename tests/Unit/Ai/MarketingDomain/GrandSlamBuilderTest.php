<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Decision\GrandSlamBuilder;
use PHPUnit\Framework\TestCase;

class GrandSlamBuilderTest extends TestCase
{
    private GrandSlamBuilder $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new GrandSlamBuilder;
    }

    public function test_builds_bonus_stack_from_vertical_objections(): void
    {
        $asset = new AiMarketingVslAsset(['niche' => 'weight_loss', 'offer' => ['price' => 49], 'mechanism_name' => 'Pink Protocol']);

        $g = $this->svc->build($asset);

        $this->assertNotEmpty($g['bonus_stack']);
        // each bonus maps to an objection
        $this->assertArrayHasKey('removes_objection', $g['bonus_stack'][0]);
        $this->assertSame('Pink Protocol', $g['naming_formula']['mechanism']);
        $this->assertCount(3, $g['pricing_table']['recommended_table']);
    }

    public function test_emphasis_targets_offer_weakest_term(): void
    {
        $asset = new AiMarketingVslAsset(['niche' => 'finance', 'offer' => ['price' => 97]]);
        $g = $this->svc->build($asset, [], ['weakest_term' => 'perceived_likelihood']);

        $this->assertStringContainsString('perceived_likelihood', $g['emphasis']);
    }

    public function test_explicit_objections_override_vertical(): void
    {
        $asset = new AiMarketingVslAsset(['niche' => 'weight_loss', 'offer' => ['price' => 49]]);
        $g = $this->svc->build($asset, ['custom objection A', 'custom objection B']);

        $this->assertCount(2, $g['bonus_stack']);
        $this->assertSame('custom objection A', $g['bonus_stack'][0]['removes_objection']);
    }
}
