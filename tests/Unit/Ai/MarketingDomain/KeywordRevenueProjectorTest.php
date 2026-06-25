<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordRevenueProjector;
use PHPUnit\Framework\TestCase;

/**
 * Locks the operator's north star: rank by REVENUE, not CVR. Revenue = volume × CVR × net_payout − cost.
 * A high-CVR-but-tiny-volume term can be beaten by a medium-CVR-high-volume one (gelatin trick: 1.95% CVR ×
 * 9171 clicks = 179 sales). Real volume dominates the morphological prior. Deterministic.
 */
class KeywordRevenueProjectorTest extends TestCase
{
    private KeywordRevenueProjector $p;

    protected function setUp(): void
    {
        $this->p = new KeywordRevenueProjector;
    }

    public function test_revenue_is_volume_times_cvr_times_payout(): void
    {
        $r = $this->p->project(
            ['keyword' => 'gelatin trick', 'score' => 50, 'suffix_regime' => 'information', 'family' => 'mechanism_trick'],
            ['payout' => 100, 'refund' => 0.0, 'cpc' => 0.0],
            ['gelatin trick' => 10000],
        );
        // cvr(score 50)=3% → 10000 × 0.03 = 300 vendas × 100 = 30000
        $this->assertSame(300.0, $r['expected_sales']);
        $this->assertSame(30000.0, $r['expected_revenue']);
        $this->assertSame('real_volume(blackink)', $r['basis']);
    }

    public function test_high_volume_mid_cvr_can_beat_low_volume_high_cvr(): void
    {
        $econ = ['payout' => 100, 'refund' => 0.0, 'cpc' => 0.0];
        $vol = ['gelatin trick' => 9171, 'orivelle anti fungal pen' => 933];
        $rank = $this->p->rank([
            ['keyword' => 'orivelle anti fungal pen', 'score' => 95, 'suffix_regime' => 'possession', 'family' => 'discovered_real'],
            ['keyword' => 'gelatin trick', 'score' => 55, 'suffix_regime' => 'information', 'family' => 'mechanism_trick'],
        ], $econ, $vol)['ranked'];

        // mesmo com CVR muito menor, o volume 10× faz o trick competir/ganhar em RECEITA
        $trick = collect($rank)->firstWhere('keyword', 'gelatin trick');
        $pen = collect($rank)->firstWhere('keyword', 'orivelle anti fungal pen');
        $this->assertGreaterThan($pen['expected_revenue'], $trick['expected_revenue'], 'volume alto vence CVR alta em receita');
    }

    public function test_arbitrage_coined_has_cheaper_clicks_and_higher_margin(): void
    {
        // a arbitragem está no clique barato: coined/posse (leilão sem concorrente) vs sintoma (todo mundo bida)
        $coined = $this->p->project(['keyword' => 'orivelle pen', 'score' => 80, 'suffix_regime' => 'possession', 'family' => 'discovered_real']);
        $symptom = $this->p->project(['keyword' => 'ed treatment', 'score' => 80, 'suffix_regime' => 'neutral', 'family' => '']);

        $this->assertLessThan($symptom['cpc'], $coined['cpc'], 'coined = CPC mínimo (leilão sem concorrente)');
        $this->assertSame('harvest', $coined['regime']);
        $this->assertSame('probe', $symptom['regime']);
        $this->assertArrayHasKey('expected_profit', $coined);
    }

    public function test_falls_back_to_morphology_volume_when_no_real_data(): void
    {
        $r = $this->p->project(['keyword' => 'unseen term', 'score' => 70, 'suffix_regime' => 'neutral', 'family' => '']);
        $this->assertSame('morphology_volume', $r['basis']);
        $this->assertGreaterThan(0, $r['volume']);
    }
}
