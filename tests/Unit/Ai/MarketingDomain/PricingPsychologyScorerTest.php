<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Scoring\PricingPsychologyScorer;
use PHPUnit\Framework\TestCase;

class PricingPsychologyScorerTest extends TestCase
{
    private PricingPsychologyScorer $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new PricingPsychologyScorer;
    }

    public function test_decoy_table_makes_middle_the_value_pick(): void
    {
        $table = $this->svc->decoyTable(49.0);

        $this->assertCount(3, $table);
        $this->assertSame('best_value', $table[1]['tier']);
        $this->assertTrue($table[1]['highlight']);
        // premium anchors above best; basic below
        $this->assertGreaterThan($table[1]['price'], $table[2]['price']);
        $this->assertLessThan($table[1]['price'], $table[0]['price']);
        // charm pricing
        $this->assertStringEndsWith('.99', number_format($table[0]['price'], 2, '.', ''));
    }

    public function test_strong_pricing_scores_high(): void
    {
        $asset = new AiMarketingVslAsset([
            'offer' => ['tiers' => [1, 2, 3], 'guarantee' => '60-day', 'anchor_price' => 199],
            'transcript' => 'normally retail value of 199, money-back guarantee',
        ]);

        $s = $this->svc->evaluate($asset, 49.99);
        $this->assertGreaterThanOrEqual(75, $s['pricing_score']);
    }

    public function test_bare_offer_flags_weakest_lever(): void
    {
        $asset = new AiMarketingVslAsset(['offer' => [], 'transcript' => 'buy now']);
        $s = $this->svc->evaluate($asset, 50.0);

        $this->assertLessThan(50, $s['pricing_score']);
        $this->assertNotSame('', $s['weakest_lever']);
    }
}
