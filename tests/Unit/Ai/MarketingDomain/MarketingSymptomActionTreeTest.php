<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Decision\MarketingSymptomActionTree;
use App\Services\Ai\MarketingDomain\Knowledge\MarketingPlaybook;
use PHPUnit\Framework\TestCase;

class MarketingSymptomActionTreeTest extends TestCase
{
    private MarketingSymptomActionTree $tree;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tree = new MarketingSymptomActionTree;
    }

    /** Anti-Goodhart: below the decision spend with 0 sales → HOLD, never act on noise. */
    public function test_holds_when_data_is_insufficient(): void
    {
        $d = $this->tree->diagnose(
            ['spend' => 100.0, 'sales' => 0, 'ad_ctr' => 0.001],
            ['test_decision_spend' => 378.0, 'max_cpa' => 126.0],
        );

        $this->assertFalse($d['data_sufficient']);
        $this->assertSame('hold', $d['primary']['action']);
    }

    public function test_low_ad_ctr_prescribes_bridge_headline_edit(): void
    {
        $d = $this->tree->diagnose(
            ['ad_ctr' => 0.012, 'sales' => 3, 'spend' => 400.0],
            ['test_decision_spend' => 378.0, 'max_cpa' => 126.0],
        );

        $this->assertTrue($d['data_sufficient']);
        $this->assertSame(MarketingPlaybook::ACTION_EDIT_BRIDGE_HEADLINE, $d['primary']['action']);
        $this->assertSame('ad_ctr', $d['primary']['stage']);
    }

    public function test_top_of_funnel_break_wins_over_lower_breaks(): void
    {
        // ad CTR AND watch-through both broken → the earliest leak (ad_ctr) is primary.
        $d = $this->tree->diagnose([
            'ad_ctr' => 0.01,
            'bridge_to_vsl' => 0.50,
            'vsl_watch_through' => 0.05,
            'checkout_rate' => 0.02,
            'sales' => 5,
            'spend' => 600.0,
        ], ['test_decision_spend' => 378.0, 'max_cpa' => 126.0]);

        $this->assertSame('ad_ctr', $d['primary']['stage']);
        $this->assertGreaterThanOrEqual(3, count($d['diagnoses']));
    }

    public function test_weak_hook_when_watch_through_is_the_first_break(): void
    {
        $d = $this->tree->diagnose([
            'ad_ctr' => 0.06,
            'bridge_to_vsl' => 0.55,
            'vsl_watch_through' => 0.10,
            'sales' => 4,
            'spend' => 500.0,
        ], ['test_decision_spend' => 378.0]);

        $this->assertSame(MarketingPlaybook::ACTION_EDIT_HOOK, $d['primary']['action']);
        $this->assertSame('hook', $d['primary']['vsl_block']);
    }

    public function test_weak_checkout_prescribes_strengthen_close(): void
    {
        $d = $this->tree->diagnose([
            'ad_ctr' => 0.06,
            'bridge_to_vsl' => 0.55,
            'vsl_watch_through' => 0.45,
            'checkout_rate' => 0.03,
            'sales' => 4,
            'spend' => 500.0,
        ], ['test_decision_spend' => 378.0]);

        $this->assertSame(MarketingPlaybook::ACTION_STRENGTHEN_CLOSE, $d['primary']['action']);
        $this->assertSame('offer', $d['primary']['vsl_block']);
    }

    public function test_healthy_funnel_above_max_cpa_lowers_the_bid(): void
    {
        // All stages pass; CPA 150 > Max CPA 126 → lower bid.
        $d = $this->tree->diagnose([
            'ad_ctr' => 0.06,
            'bridge_to_vsl' => 0.55,
            'vsl_watch_through' => 0.45,
            'checkout_rate' => 0.18,
            'sales' => 4,
            'spend' => 600.0, // cpa = 150
        ], ['test_decision_spend' => 378.0, 'max_cpa' => 126.0]);

        $this->assertSame(MarketingPlaybook::ACTION_LOWER_BID, $d['primary']['action']);
        $this->assertSame('unprofitable', $d['primary']['stage']);
    }

    public function test_healthy_profitable_funnel_scales(): void
    {
        // CPA 100 <= Max CPA 126, all stages pass → scale (raise bid + open audience).
        $d = $this->tree->diagnose([
            'ad_ctr' => 0.06,
            'bridge_to_vsl' => 0.55,
            'vsl_watch_through' => 0.45,
            'checkout_rate' => 0.18,
            'sales' => 6,
            'spend' => 600.0, // cpa = 100
        ], ['test_decision_spend' => 378.0, 'max_cpa' => 126.0]);

        $this->assertSame(MarketingPlaybook::ACTION_RAISE_BID, $d['primary']['action']);
        $this->assertSame(MarketingPlaybook::ACTION_OPEN_AUDIENCE, $d['primary']['alt_action']);
        $this->assertSame('scale', $d['primary']['stage']);
    }

    public function test_creative_fatigue_triggers_refresh(): void
    {
        $d = $this->tree->diagnose([
            'ad_ctr' => 0.06,
            'bridge_to_vsl' => 0.55,
            'vsl_watch_through' => 0.45,
            'checkout_rate' => 0.18,
            'sales' => 6,
            'spend' => 600.0,
            'ctr_declining' => true,
        ], ['test_decision_spend' => 378.0, 'max_cpa' => 126.0]);

        $this->assertSame(MarketingPlaybook::ACTION_REFRESH_VSL, $d['primary']['action']);
    }

    public function test_diagnosis_is_deterministic(): void
    {
        $funnel = ['ad_ctr' => 0.012, 'sales' => 3, 'spend' => 400.0];
        $econ = ['test_decision_spend' => 378.0, 'max_cpa' => 126.0];

        $this->assertSame($this->tree->diagnose($funnel, $econ), $this->tree->diagnose($funnel, $econ));
    }
}
