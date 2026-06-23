<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Campaign\FunnelSequenceDecider;
use App\Services\Ai\MarketingDomain\Content\AdvertorialOutlineGenerator;
use PHPUnit\Framework\TestCase;

class MarketingFunnelContentTest extends TestCase
{
    public function test_advertorial_outline_is_deterministic_and_congruent(): void
    {
        $asset = new AiMarketingVslAsset([
            'niche' => 'weight_loss',
            'core_promise' => 'lose 30 pounds',
            'problem_mechanism' => 'slow metabolism',
            'mechanism_name' => 'Pink Protocol',
        ]);
        $gen = new AdvertorialOutlineGenerator;

        $a = $gen->generate($asset, 'the pink gelatin trick');
        $this->assertGreaterThanOrEqual(3, count($a['title_options']));
        $this->assertNotEmpty($a['bullet_outline']);
        $this->assertArrayHasKey('congruency_score', $a);
        // deterministic
        $this->assertSame($a, $gen->generate($asset, 'the pink gelatin trick'));
    }

    public function test_cold_traffic_leads_with_advertorial_high_ticket_adds_bump(): void
    {
        $d = new FunnelSequenceDecider;

        $cold = $d->recommendFunnelSequence(['price' => 97], 'cold', 'weight_loss');
        $stages = array_column($cold['stages'], 'stage');
        $this->assertSame('advertorial', $stages[0]);
        $this->assertContains('order_bump', $stages); // price >= 60

        $warm = $d->recommendFunnelSequence(['price' => 27], 'warm');
        $warmStages = array_column($warm['stages'], 'stage');
        $this->assertSame('squeeze', $warmStages[0]);
        $this->assertNotContains('order_bump', $warmStages); // price < 60
    }
}
