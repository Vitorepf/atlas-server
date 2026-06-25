<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordScalingDiagnostic;
use PHPUnit\Framework\TestCase;

/**
 * Locks the 2026 scaling brain ("escalar MILHÕES"): scale by SIGNAL, not fixed %. ≥14-day window; ROAS<target
 * → pull back; ROAS≥target + high IS-lost-to-budget + healthy → aggressive; ceiling (no IS lost to budget) →
 * hold + reduce tROAS for volume. Deterministic; activates with live signals.
 */
class KeywordScalingDiagnosticTest extends TestCase
{
    private KeywordScalingDiagnostic $d;

    protected function setUp(): void
    {
        $this->d = new KeywordScalingDiagnostic;
    }

    public function test_short_window_holds(): void
    {
        $this->assertSame('hold', $this->d->diagnose(['roas' => 5, 'target_roas' => 3, 'days_in_window' => 7, 'impr_share_lost_to_budget' => 0.5])['action']);
    }

    public function test_below_target_pulls_back(): void
    {
        $r = $this->d->diagnose(['roas' => 2, 'target_roas' => 3, 'days_in_window' => 30, 'impr_share_lost_to_budget' => 0.5]);
        $this->assertSame('pull_back', $r['action']);
        $this->assertLessThan(1.0, $r['budget_multiplier']);
    }

    public function test_high_headroom_healthy_scales_aggressive(): void
    {
        $r = $this->d->diagnose(['roas' => 5, 'target_roas' => 3, 'days_in_window' => 30, 'impr_share_lost_to_budget' => 0.4, 'cvr_trend' => 'rising', 'cpc_inflation' => 0.05]);
        $this->assertSame('scale_aggressive', $r['action']);
        $this->assertGreaterThan(1.0, $r['budget_multiplier']);
    }

    public function test_ceiling_holds_and_suggests_reducing_troas_for_volume(): void
    {
        $r = $this->d->diagnose(['roas' => 5, 'target_roas' => 3, 'days_in_window' => 30, 'impr_share_lost_to_budget' => 0.01]);
        $this->assertSame('hold', $r['action']);
        $this->assertSame('reduce_troas', $r['troas_action'], 'no teto, pra volume reduz o tROAS, não o budget');
    }

    public function test_falling_cvr_holds_despite_headroom(): void
    {
        $r = $this->d->diagnose(['roas' => 5, 'target_roas' => 3, 'days_in_window' => 30, 'impr_share_lost_to_budget' => 0.4, 'cvr_trend' => 'falling', 'cpc_inflation' => 0.3]);
        $this->assertSame('hold', $r['action'], 'CVR caindo → não comprar tráfego pior');
    }
}
