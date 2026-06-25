<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordCampaignBlueprint;
use PHPUnit\Framework\TestCase;

/**
 * Locks the deployable bridge: regime partition + cross-negatives → 3 isolated campaigns (budget/tCPA apart),
 * STAG ad groups by root, regime-correct match types + bid strategy + the cross-negatives. Deterministic.
 */
class KeywordCampaignBlueprintTest extends TestCase
{
    private KeywordCampaignBlueprint $b;

    protected function setUp(): void
    {
        $this->b = new KeywordCampaignBlueprint;
    }

    public function test_builds_isolated_regime_campaigns_with_negatives_and_bids(): void
    {
        $partition = [
            // raiz "orivelle" tem 3 kw → ad group próprio; "ziverdo" tem 1 → consolida no long-tail
            'harvest' => [['keyword' => 'orivelle pen'], ['keyword' => 'orivelle nail'], ['keyword' => 'orivelle drops'], ['keyword' => 'ziverdo kit']],
            'seed' => [['keyword' => 'gelatin trick']],
            'probe' => [['keyword' => 'weight loss treatment']],
        ];
        $crossNeg = [
            'by_regime' => [
                'harvest' => ['negatives' => [['term' => 'gelatin trick', 'match' => 'exact']]],
                'seed' => ['negatives' => [['term' => 'orivelle pen', 'match' => 'exact']]],
                'probe' => ['negatives' => []],
            ],
        ];
        $r = $this->b->build($partition, $crossNeg);

        $this->assertCount(3, $r['campaigns']);
        $harvest = collect($r['campaigns'])->firstWhere('regime', 'harvest');
        $this->assertSame('ATLAS_harvest', $harvest['campaign']);
        $this->assertStringContainsString('agressivo', $harvest['bid_strategy']);
        // STAG + consolidação: "orivelle" (3 kw) = ad group próprio; "ziverdo" (1 kw) = long-tail consolidado
        $byName = collect($harvest['ad_groups'])->keyBy('ad_group');
        $this->assertCount(3, $byName['orivelle']['keywords'], 'raiz com ≥3 = tema próprio');
        $this->assertTrue($byName['harvest_long_tail']['consolidated'] ?? false, 'a cauda consolida num só ad group');
        $this->assertCount(1, $byName['harvest_long_tail']['keywords']);
        // a campanha harvest carrega o negativo cruzado do seed
        $this->assertSame('gelatin trick', $harvest['negatives'][0]['term']);

        $probe = collect($r['campaigns'])->firstWhere('regime', 'probe');
        $this->assertSame(['broad'], $probe['ad_groups'][0]['keywords'][0]['match'], 'probe = broad sensor');
    }

    public function test_skips_empty_regimes_and_is_deterministic(): void
    {
        $partition = ['harvest' => [['keyword' => 'orivelle']], 'seed' => [], 'probe' => []];
        $r = $this->b->build($partition, ['by_regime' => []]);
        $this->assertCount(1, $r['campaigns']);
        $this->assertEquals($r, $this->b->build($partition, ['by_regime' => []]));
    }
}
