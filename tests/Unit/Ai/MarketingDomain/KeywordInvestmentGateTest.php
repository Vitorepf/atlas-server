<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordInvestmentGate;
use PHPUnit\Framework\TestCase;

/**
 * Locks the investimento-vs-gasto decision math (report §5): breakeven CVR = CPC/net, rule-of-three cut
 * = ceil(3/breakeven), EPC>CPC = investimento. Proven (live data) vs forecast-prior (dormant) are honestly
 * separated — the gate never fakes proof.
 */
class KeywordInvestmentGateTest extends TestCase
{
    private KeywordInvestmentGate $g;

    protected function setUp(): void
    {
        $this->g = new KeywordInvestmentGate;
    }

    public function test_breakeven_and_rule_of_three_cut(): void
    {
        // report's worked example: payout $40, CPC $1.20 → breakeven CVR 3% → cut after 100 zero-sale clicks.
        $r = $this->g->decide(['payout' => 40, 'refund' => 0.0, 'cpc' => 1.20]);
        $this->assertEqualsWithDelta(0.03, $r['breakeven_cvr'], 0.001);
        $this->assertSame(100, $r['cut_after_clicks']);
    }

    public function test_epc_above_cpc_is_proven_investimento(): void
    {
        $r = $this->g->decide(['payout' => 40, 'refund' => 0.0, 'cpc' => 1.0], ['clicks' => 100, 'conversions' => 3, 'revenue' => 140]);
        $this->assertSame('investimento', $r['verdict']);
        $this->assertSame('proven', $r['basis']);
        $this->assertSame(1.4, $r['epc']);
    }

    public function test_rule_of_three_zero_sales_is_proven_gasto(): void
    {
        $r = $this->g->decide(['payout' => 40, 'refund' => 0.0, 'cpc' => 1.20], ['clicks' => 100, 'conversions' => 0, 'revenue' => 0]);
        $this->assertSame('gasto', $r['verdict']);
        $this->assertSame('proven', $r['basis']);
        $this->assertStringContainsString('rule-of-three', $r['reason']);
    }

    public function test_below_cut_zero_sales_is_teste(): void
    {
        $r = $this->g->decide(['payout' => 40, 'refund' => 0.0, 'cpc' => 1.20], ['clicks' => 50, 'conversions' => 0, 'revenue' => 0]);
        $this->assertSame('teste', $r['verdict']);
        $this->assertSame('proven', $r['basis']);
    }

    public function test_forecast_prior_is_labeled_not_proven(): void
    {
        $invest = $this->g->decide(['payout' => 40, 'refund' => 0.0, 'cpc' => 1.0, 'cvr' => 0.05]);
        $this->assertSame('investimento', $invest['verdict']);
        $this->assertSame('forecast_prior', $invest['basis']);

        $gasto = $this->g->decide(['payout' => 40, 'refund' => 0.0, 'cpc' => 1.0, 'cvr' => 0.01]);
        $this->assertSame('gasto', $gasto['verdict']);
        $this->assertSame('forecast_prior', $gasto['basis']);
    }

    public function test_incomplete_economics_is_unknown(): void
    {
        $this->assertSame('unknown', $this->g->decide([])['verdict']);
        $this->assertSame('unknown', $this->g->decide(['payout' => 40])['verdict']); // no cpc
    }

    public function test_generalizes_cross_niche(): void
    {
        // finance offer: payout $200, CPC $5 → breakeven 2.5%, cut 120; high-CVR proven → investimento.
        $fin = $this->g->decide(['payout' => 200, 'refund' => 0.05, 'cpc' => 5.0], ['clicks' => 80, 'conversions' => 4, 'revenue' => 800]);
        $this->assertSame('investimento', $fin['verdict']);
        $this->assertGreaterThan($fin['cpc'], $fin['epc']);
    }
}
