<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\BayesianKillScaleDecider;
use App\Services\Ai\MarketingDomain\Campaign\FewShotConfidenceMeter;
use PHPUnit\Framework\TestCase;

/**
 * BayesianKillScaleDecider + FewShotConfidenceMeter — pillar 3: decide KILL/SCALE with few data, without
 * killing on noise. CVR Beta posterior vs the min-viable CVR from the breakeven economics.
 */
class BayesianKillScaleDeciderTest extends TestCase
{
    private const ECON = ['payout' => 100, 'refund_rate' => 0.1, 'target_margin' => 0.3, 'cvr' => 0.02];

    public function test_kills_early_when_loss_is_near_certain(): void
    {
        $r = (new BayesianKillScaleDecider)->decide(['clicks' => 200, 'conversions' => 0, 'spend' => 400], self::ECON);
        $this->assertSame('KILL', $r['decision']);
        $this->assertGreaterThanOrEqual(0.85, $r['p_loss']);
    }

    public function test_scales_early_on_profit_evidence(): void
    {
        $r = (new BayesianKillScaleDecider)->decide(['clicks' => 60, 'conversions' => 3, 'spend' => 90], self::ECON);
        $this->assertSame('SCALE', $r['decision']);
        $this->assertLessThanOrEqual($r['max_cpa'], $r['observed_cpa']);
    }

    public function test_does_not_kill_on_noise_few_clicks_zero_sales(): void
    {
        // 15 clicks, 0 sales is expected even for a fine campaign — must NOT kill on the prior alone.
        $r = (new BayesianKillScaleDecider)->decide(['clicks' => 15, 'conversions' => 0, 'spend' => 30], self::ECON);
        $this->assertSame('HOLD', $r['decision']);
    }

    public function test_reason_is_present_and_economics_grounded(): void
    {
        $r = (new BayesianKillScaleDecider)->decide(['clicks' => 200, 'conversions' => 0, 'spend' => 400], self::ECON);
        $this->assertNotEmpty($r['reason']);
        $this->assertGreaterThan(0, $r['max_cpa']);
        $this->assertGreaterThan(0, $r['min_viable_cvr']);
    }

    public function test_confidence_meter_labels_noise_vs_conclusive(): void
    {
        $meter = new FewShotConfidenceMeter;
        $this->assertSame('no_data', $meter->assess(0, 0)['verdict']);
        // 0 sales in 300 clicks against a 2% floor → conclusively below the floor.
        $this->assertSame('conclusive', $meter->assess(0, 300, 0.02)['verdict']);
        // 1 sale in 10 clicks → too little to be sure.
        $wide = $meter->assess(1, 10, 0.02);
        $this->assertContains($wide['verdict'], ['noise', 'weak_signal']);
        $this->assertGreaterThan(0, $meter->assess(2, 30, 0.02)['n_needed']);
    }
}
