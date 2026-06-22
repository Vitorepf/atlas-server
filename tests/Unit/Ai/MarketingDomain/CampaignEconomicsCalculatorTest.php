<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\CampaignEconomicsCalculator;
use PHPUnit\Framework\TestCase;

class CampaignEconomicsCalculatorTest extends TestCase
{
    public function test_deterministic_economics_for_200_cpa_offer(): void
    {
        $calc = new CampaignEconomicsCalculator;

        $e = $calc->compute([
            'payout' => 200.0,
            'currency' => 'USD',
            'refund_rate' => 0.10,
            'target_margin' => 0.30,
            'cvr' => 0.01,
        ]);

        // net = 200 * 0.9 = 180 ; max_cpa = 180 * 0.7 = 126
        $this->assertSame(180.0, $e['net_payout']);
        $this->assertSame(126.0, $e['max_cpa']);
        $this->assertSame(180.0, $e['breakeven_cpa']);

        // CPC ceilings at cvr 1%
        $this->assertSame(1.26, $e['target_cpc']);
        $this->assertSame(1.8, $e['breakeven_cpc']);

        // ROAS on gross payout revenue
        $this->assertSame(1.59, $e['target_roas']);   // 200 / 126
        $this->assertSame(1.11, $e['breakeven_roas']); // 200 / 180

        // thresholds + samples
        $this->assertSame(189.0, $e['kill_spend_no_sale']); // 1.5 * 126
        $this->assertSame(378.0, $e['test_decision_spend']); // 3 * 126
        $this->assertSame(100, $e['clicks_per_sale']);       // ceil(1 / 0.01)
        $this->assertSame(300, $e['clicks_to_decision']);    // ceil(3 / 0.01)
        $this->assertSame(378.0, $e['daily_budget']);        // default 3 * max_cpa

        $this->assertSame(15, $e['conv_for_troas']);
        $this->assertSame(30, $e['conv_for_tcpa']);
    }

    public function test_explicit_budget_overrides_default(): void
    {
        $calc = new CampaignEconomicsCalculator;
        $e = $calc->compute(['payout' => 200.0, 'daily_budget' => 50.0]);

        $this->assertSame(50.0, $e['daily_budget']);
    }

    public function test_rates_are_clamped(): void
    {
        $calc = new CampaignEconomicsCalculator;
        $e = $calc->compute(['payout' => 100.0, 'refund_rate' => 1.5, 'target_margin' => -0.2]);

        // refund clamps to 1.0 → net payout 0 → max_cpa 0
        $this->assertSame(0.0, $e['net_payout']);
        $this->assertSame(0.0, $e['max_cpa']);
    }
}
