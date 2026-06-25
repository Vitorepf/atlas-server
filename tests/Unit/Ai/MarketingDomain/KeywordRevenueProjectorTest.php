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

    public function test_revenue_is_volume_times_regime_cvr_times_payout(): void
    {
        // gelatin trick = mechanism_trick + sufixo info → regime seed → CVR-prior real 1.55%
        $r = $this->p->project(
            ['keyword' => 'gelatin trick', 'score' => 50, 'suffix_regime' => 'information', 'family' => 'mechanism_trick'],
            ['payout' => 100, 'refund' => 0.0, 'cpc' => 0.0],
            ['gelatin trick' => 10000],
        );
        // CVR seed 0.0155 → 10000 × 0.0155 = 155 vendas × 100 = 15500
        $this->assertSame('seed', $r['regime']);
        $this->assertSame(155.0, $r['expected_sales']);
        $this->assertSame(15500.0, $r['expected_revenue']);
        $this->assertSame('real_volume(blackink)', $r['basis']);
    }

    public function test_high_cvr_coined_beats_higher_volume_recall_when_cvr_gap_dominates(): void
    {
        // realidade: orivelle anti fungal pen 37% × 933 = 343 vendas REAIS > gelatin trick 1.95% × 9171 = 179.
        // o gap de CVR (harvest 30% vs seed 1.55% = 19×) supera o gap de volume (10×) → coined vence.
        $econ = ['payout' => 100, 'refund' => 0.0, 'cpc' => 0.0];
        $vol = ['gelatin trick' => 9171, 'orivelle anti fungal pen' => 933];
        $rank = $this->p->rank([
            ['keyword' => 'gelatin trick', 'score' => 55, 'suffix_regime' => 'information', 'family' => 'mechanism_trick'],
            ['keyword' => 'orivelle anti fungal pen', 'score' => 95, 'suffix_regime' => 'possession', 'family' => 'discovered_real'],
        ], $econ, $vol)['ranked'];
        $this->assertSame('orivelle anti fungal pen', $rank[0]['keyword'], 'o coined de 30% CVR vence o recall de 1.55% (bate a venda real)');
    }

    public function test_high_volume_wins_when_volume_gap_dominates(): void
    {
        // mas o volume VENCE quando o gap é grande o bastante: seed gigante vs harvest minúsculo
        $econ = ['payout' => 100, 'refund' => 0.0, 'cpc' => 0.0];
        $vol = ['gelatin trick' => 80000, 'tiny coined' => 100];
        $rank = $this->p->rank([
            ['keyword' => 'tiny coined', 'score' => 95, 'suffix_regime' => 'possession', 'family' => 'discovered_real'],
            ['keyword' => 'gelatin trick', 'score' => 55, 'suffix_regime' => 'information', 'family' => 'mechanism_trick'],
        ], $econ, $vol)['ranked'];
        // gelatin 80000×0.0155=1240 vendas vs tiny 100×0.30=30 → volume vence
        $this->assertSame('gelatin trick', $rank[0]['keyword'], 'volume gigante vence quando o gap de volume supera o de CVR');
    }

    public function test_safety_flags_money_losers_below_breakeven(): void
    {
        // CVR < breakeven (cpc/payout-líq) = PERDE dinheiro → profitable=false. payout-líq=108, probe cpc=1.60
        // → breakeven 1.48%. score 15 → cvr 1.25% < 1.48% → loser.
        $loser = $this->p->project(['keyword' => 'ed treatment', 'score' => 15, 'suffix_regime' => 'neutral', 'family' => ''], ['payout' => 120, 'refund' => 0.1]);
        $this->assertFalse($loser['profitable'], 'sub-breakeven NUNCA é lucrativo');
        $this->assertLessThan(0, $loser['expected_profit']);

        $winner = $this->p->project(['keyword' => 'orivelle pen', 'score' => 90, 'suffix_regime' => 'possession', 'family' => 'discovered_real'], ['payout' => 120, 'refund' => 0.1]);
        $this->assertTrue($winner['profitable']);
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

    public function test_real_cvr_dominates_the_score_prior(): void
    {
        // termo conhecido com CVR REAL alta → projeção usa a real, não o mapa cru do score (quase exata)
        $semCvr = $this->p->project(['keyword' => 'gelatin trick', 'score' => 50, 'suffix_regime' => 'information', 'family' => 'mechanism_trick'], ['payout' => 100, 'refund' => 0, 'cpc' => 0], ['gelatin trick' => 10000]);
        $comCvr = $this->p->project(['keyword' => 'gelatin trick', 'score' => 50, 'suffix_regime' => 'information', 'family' => 'mechanism_trick'], ['payout' => 100, 'refund' => 0, 'cpc' => 0], ['gelatin trick' => 10000], ['gelatin trick' => 0.08]);
        $this->assertSame(0.08, $comCvr['cvr_prior'], 'a CVR real (8%) domina o prior do score');
        $this->assertGreaterThan($semCvr['expected_revenue'], $comCvr['expected_revenue']);
    }

    public function test_adversarial_edge_cases_never_break_determinism(): void
    {
        // payout negativo (oferta que paga menos que o refund) → net_payout clampado em 0, NUNCA lucrativo
        $neg = $this->p->project(['keyword' => 'x', 'score' => 90, 'suffix_regime' => 'possession', 'family' => 'discovered_real'], ['payout' => -50, 'refund' => 0.1], ['x' => 1000]);
        $this->assertGreaterThanOrEqual(0.0, $neg['net_payout'], 'net_payout nunca negativo');
        $this->assertFalse($neg['profitable']);

        // refund > 100% → net_payout clampado em 0, não lucrativo
        $ref = $this->p->project(['keyword' => 'z', 'score' => 90, 'suffix_regime' => 'possession', 'family' => 'discovered_real'], ['payout' => 120, 'refund' => 1.5], ['z' => 1000]);
        $this->assertSame(0.0, $ref['net_payout']);
        $this->assertFalse($ref['profitable']);

        // volume 0 → zero vendas, lucro finito (sem NaN/divisão por zero)
        $zero = $this->p->project(['keyword' => 'y', 'score' => 50, 'suffix_regime' => 'neutral', 'family' => ''], ['payout' => 120], ['y' => 0]);
        $this->assertSame(0.0, $zero['expected_sales']);
        $this->assertFalse(is_nan((float) $zero['expected_profit']));
    }
}
