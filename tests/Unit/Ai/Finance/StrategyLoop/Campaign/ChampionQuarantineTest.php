<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Campaign\ChampionQuarantine;
use App\Services\Ai\Finance\StrategyLoop\Campaign\SecondEngineDivergenceGate;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyCampaignStore;
use PHPUnit\Framework\TestCase;

final class ChampionQuarantineTest extends TestCase
{
    public function test_round_pass_becomes_promoted_not_certified_when_second_engine_is_missing(): void
    {
        $result = (new ChampionQuarantine)->evaluate($this->passingInput([
            'second_engine' => (new SecondEngineDivergenceGate)->evaluate(['trade_count' => 10, 'ann_sharpe' => 1.0, 'max_dd' => 0.1], null),
        ]));

        $this->assertTrue($result['promoted']);
        $this->assertFalse($result['certified']);
        $this->assertSame('promoted_pending_quarantine', $result['status']);
        $this->assertContains('second_engine_required', $result['reasons']);
    }

    public function test_exhausted_holdout_blocks_certification_even_when_everything_else_passes(): void
    {
        $result = (new ChampionQuarantine)->evaluate($this->passingInput([
            'holdout_status' => StrategyCampaignStore::HOLDOUT_EXHAUSTED,
        ]));

        $this->assertTrue($result['promoted']);
        $this->assertFalse($result['certified']);
        $this->assertContains('fresh_holdout_required(EXHAUSTED)', $result['reasons']);
    }

    public function test_campaign_level_trial_penalty_blocks_a_round_level_pass(): void
    {
        $result = (new ChampionQuarantine)->evaluate($this->passingInput([
            'campaign_verdict' => ['certified' => false, 'reasons' => ['deflated_sharpe_too_low'], 'report' => ['n_trials' => 50_000]],
        ]));

        $this->assertTrue($result['promoted']);
        $this->assertFalse($result['certified']);
        $this->assertContains('campaign_penalty_failed', $result['reasons']);
    }

    public function test_scenario_level_trial_penalty_blocks_a_campaign_level_pass(): void
    {
        $result = (new ChampionQuarantine)->evaluate($this->passingInput([
            'scenario_verdict' => ['certified' => false, 'reasons' => ['deflated_sharpe_too_low'], 'report' => ['n_trials' => 500_000]],
        ]));

        $this->assertTrue($result['promoted']);
        $this->assertFalse($result['certified']);
        $this->assertContains('scenario_penalty_failed', $result['reasons']);
    }

    public function test_fresh_holdout_must_pass_before_certification(): void
    {
        $result = (new ChampionQuarantine)->evaluate($this->passingInput([
            'fresh_holdout' => ['ann_sharpe' => 0.2, 'n_trades' => 12],
        ]));

        $this->assertTrue($result['promoted']);
        $this->assertFalse($result['certified']);
        $this->assertContains('fresh_holdout_failed', $result['reasons']);
    }

    public function test_timeframe_policy_can_raise_fresh_holdout_trade_floor(): void
    {
        $result = (new ChampionQuarantine)->evaluate($this->passingInput([
            'fresh_holdout' => ['ann_sharpe' => 0.8, 'n_trades' => 15],
            'thresholds' => ['holdout_min_sharpe' => 0.5, 'holdout_min_trades' => 20],
        ]));

        $this->assertTrue($result['promoted']);
        $this->assertFalse($result['certified']);
        $this->assertContains('fresh_holdout_failed', $result['reasons']);
        $this->assertSame(20, $result['thresholds']['holdout_min_trades']);
    }

    public function test_cross_campaign_rediscovery_is_required_before_certification(): void
    {
        $result = (new ChampionQuarantine)->evaluate($this->passingInput([
            'cross_campaign' => ['passed' => false, 'reason' => 'cross_campaign_rediscovery_required'],
        ]));

        $this->assertTrue($result['promoted']);
        $this->assertFalse($result['certified']);
        $this->assertContains('cross_campaign_rediscovery_required', $result['reasons']);
    }

    public function test_certifies_only_after_all_quarantine_gates_pass(): void
    {
        $result = (new ChampionQuarantine)->evaluate($this->passingInput());

        $this->assertTrue($result['promoted']);
        $this->assertTrue($result['certified']);
        $this->assertSame('certified_for_review', $result['status']);
        $this->assertSame(['certified_for_review'], $result['reasons']);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function passingInput(array $overrides = []): array
    {
        return array_replace_recursive([
            'round_verdict' => ['certified' => true, 'reasons' => ['certified'], 'report' => ['n_trials' => 600]],
            'campaign_verdict' => ['certified' => true, 'reasons' => ['certified'], 'report' => ['n_trials' => 600]],
            'scenario_verdict' => ['certified' => true, 'reasons' => ['certified'], 'report' => ['n_trials' => 600]],
            'holdout_status' => StrategyCampaignStore::HOLDOUT_FRESH,
            'fresh_holdout' => ['ann_sharpe' => 0.7, 'n_trades' => 15],
            'cost_stress' => ['passed' => true, 'reason' => 'cost_stress_passed'],
            'neighborhood' => ['passed' => true, 'reason' => 'neighborhood_passed'],
            'second_engine' => ['passed' => true, 'status' => 'passed', 'reasons' => ['second_engine_passed']],
            'cross_campaign' => ['passed' => true, 'reason' => 'cross_campaign_rediscovery_passed'],
        ], $overrides);
    }
}
