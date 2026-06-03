<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\StrategyLoop\Campaign;

use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyCampaignReporter;
use App\Services\Ai\Finance\StrategyLoop\Campaign\StrategyCampaignStore;
use PHPUnit\Framework\TestCase;

final class StrategyCampaignReporterTest extends TestCase
{
    private string $ledger;

    protected function setUp(): void
    {
        $this->ledger = sys_get_temp_dir().'/atlas-strategy-report-'.bin2hex(random_bytes(4)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->ledger);
    }

    public function test_reports_holdout_exhaustion_as_research_verdict(): void
    {
        $this->writeRows([
            $this->row(1, ['deflated_sharpe' => 0.946, 'holdout_status' => StrategyCampaignStore::HOLDOUT_EXHAUSTED]),
        ]);

        $report = (new StrategyCampaignReporter)->summarizeLedger($this->ledger, $this->context([
            'holdout_status' => StrategyCampaignStore::HOLDOUT_EXHAUSTED,
        ]));

        $this->assertSame('NULL_HOLDOUT_EXHAUSTED', $report['verdict']);
        $this->assertSame(1, $report['summary']['rounds']);
        $this->assertSame(600, $report['summary']['total_candidates']);
        $this->assertSame(0.946, $report['summary']['best_dsr']);
        $this->assertStringContainsString('holdout was exhausted', $report['negative_conclusion']);
    }

    public function test_zero_round_holdout_exhaustion_is_inconclusive_not_null(): void
    {
        $report = (new StrategyCampaignReporter)->summarizeLedger($this->ledger, $this->context([
            'holdout_status' => StrategyCampaignStore::HOLDOUT_EXHAUSTED,
            'holdout_generation' => 1,
            'stop_reason' => 'holdout_exhausted',
        ]));

        $this->assertSame('INCONCLUSIVE', $report['verdict']);
        $this->assertSame(0, $report['summary']['rounds']);
        $this->assertSame(0, $report['summary']['total_candidates']);
        $this->assertSame(1, $report['summary']['holdout_generation']);
        $this->assertStringContainsString('inconclusive', strtolower($report['negative_conclusion']));
    }

    public function test_reports_strong_and_weak_nulls_by_registered_budget_depth(): void
    {
        $strongRows = [];
        for ($i = 1; $i <= 100; $i++) {
            $strongRows[] = $this->row($i);
        }
        $this->writeRows($strongRows);

        $strong = (new StrategyCampaignReporter)->summarizeLedger($this->ledger, $this->context([
            'pre_registered_budget_complete' => true,
        ]));
        $this->assertSame('NULL_STRONG', $strong['verdict']);

        @unlink($this->ledger);
        $this->writeRows([$this->row(1)]);
        $weak = (new StrategyCampaignReporter)->summarizeLedger($this->ledger, $this->context([
            'pre_registered_budget_complete' => true,
        ]));
        $this->assertSame('NULL_WEAK', $weak['verdict']);
    }

    public function test_reports_inconclusive_for_paused_campaign_before_budget_completion(): void
    {
        $this->writeRows([$this->row(1)]);

        $report = (new StrategyCampaignReporter)->summarizeLedger($this->ledger, $this->context([
            'stop_reason' => 'invocation_round_limit',
            'pre_registered_budget_complete' => false,
        ]));

        $this->assertSame('INCONCLUSIVE', $report['verdict']);
        $this->assertSame('invocation_round_limit', $report['summary']['stop_reason']);
    }

    public function test_reports_family_exhausted_when_pre_registered_budget_completes_without_champion(): void
    {
        $rows = [];
        for ($i = 1; $i <= 100; $i++) {
            $rows[] = $this->row($i);
        }
        $this->writeRows($rows);

        $report = (new StrategyCampaignReporter)->summarizeLedger($this->ledger, $this->context([
            'pre_registered_budget_complete' => true,
            'max_rounds' => 100,
            'stop_reason' => 'campaign_round_budget_complete',
        ]));

        $this->assertSame('NULL_FAMILY_EXHAUSTED', $report['verdict']);
        $this->assertStringContainsString('is exhausted for this campaign scope', $report['negative_conclusion']);
        $this->assertStringContainsString('family-level negative finding', $report['next_decision']);
    }

    public function test_preserves_failure_distribution_and_best_holdout(): void
    {
        $this->writeRows([
            $this->row(1, ['reasons' => ['deflated_sharpe_too_low'], 'best_ann_sharpe' => 0.6, 'holdout_sharpe' => 0.1]),
            $this->row(2, [
                'reasons' => ['deflated_sharpe_too_low', 'pbo_too_high'],
                'best_ann_sharpe' => 0.8,
                'holdout_sharpe' => 0.4,
                'holdout_regime_metrics' => ['bull' => ['ann_sharpe' => 0.5]],
                'holdout_total_return' => 0.12,
                'holdout_exposure' => 0.25,
                'holdout_equity_curve_sample' => [1.0, 1.05, 1.12],
                'winner_signature' => ['signature' => 'sig-2'],
                'winner_strategy' => ['entry_lookback' => 20, 'risk_pct' => 0.1],
                'data_sha' => 'row-data-sha',
                'cost_profile_hash' => 'row-cost-hash',
            ]),
        ]);

        $report = (new StrategyCampaignReporter)->summarizeLedger($this->ledger, $this->context([
            'data_sha' => 'context-data-sha',
            'cost_profile_hash' => 'context-cost-hash',
            'cost_profile' => ['fee_bps' => 10.0, 'slippage_bps' => 5.0, 'cost_profile_hash' => 'context-cost-hash'],
        ]));

        $this->assertSame(2, $report['failure_distribution']['deflated_sharpe_too_low']);
        $this->assertSame(1, $report['failure_distribution']['pbo_too_high']);
        $this->assertSame(0.8, $report['summary']['best_ann_sharpe']);
        $this->assertSame(2, $report['summary']['best_ann_sharpe_round']);
        $this->assertSame(0.4, $report['summary']['best_holdout_sharpe']);
        $this->assertSame(2, $report['summary']['best_holdout_round']);
        $this->assertSame('context-data-sha', $report['summary']['data_sha']);
        $this->assertSame('context-cost-hash', $report['summary']['cost_profile_hash']);
        $this->assertSame(10.0, $report['cost_profile']['fee_bps']);
        $this->assertSame('sig-2', $report['scenario_profile']['best_observed']['ann_sharpe']['winner_signature']['signature']);
        $this->assertSame(['entry_lookback' => 20, 'risk_pct' => 0.1], $report['scenario_profile']['best_observed']['ann_sharpe']['winner_strategy']);
        $this->assertSame(0.12, $report['scenario_profile']['best_observed']['ann_sharpe']['holdout_total_return']);
        $this->assertSame(0.25, $report['scenario_profile']['best_observed']['ann_sharpe']['holdout_exposure']);
        $this->assertSame([1.0, 1.05, 1.12], $report['scenario_profile']['best_observed']['ann_sharpe']['holdout_equity_curve_sample']);
        $this->assertSame(0.5, $report['regime_summary']['best_holdout_validation_holdout']['bull']['ann_sharpe']);
        $this->assertSame(0.5, $report['regime_summary']['best_ann_validation_holdout']['bull']['ann_sharpe']);
    }

    public function test_reports_best_campaign_level_dsr_separately_from_round_dsr(): void
    {
        $this->writeRows([
            $this->row(1, [
                'deflated_sharpe' => 0.99,
                'campaign_deflated_sharpe' => 0.99,
                'reasons' => ['legacy_round_only_reason'],
            ]),
            $this->row(2, [
                'deflated_sharpe' => 0.97,
                'campaign_deflated_sharpe' => 0.42,
                'campaign_reasons' => ['deflated_sharpe_too_low(0.42<0.95, N=60000)'],
                'reasons' => ['deflated_sharpe_too_low(0.42<0.95, N=60000)'],
            ]),
            $this->row(3, [
                'deflated_sharpe' => 0.9,
                'campaign_deflated_sharpe' => 0.55,
                'campaign_reasons' => ['deflated_sharpe_too_low(0.55<0.95, N=120000)'],
                'reasons' => ['deflated_sharpe_too_low(0.55<0.95, N=120000)'],
            ]),
        ]);

        $report = (new StrategyCampaignReporter)->summarizeLedger($this->ledger, $this->context());

        $this->assertSame(0.99, $report['summary']['best_dsr']);
        $this->assertSame(1, $report['summary']['best_dsr_round']);
        $this->assertSame(0.55, $report['summary']['best_campaign_dsr']);
        $this->assertSame(3, $report['summary']['best_campaign_dsr_round']);
        $this->assertSame(3, $report['scenario_profile']['best_observed']['campaign_deflated_sharpe']['round']);
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     */
    private function writeRows(array $rows): void
    {
        foreach ($rows as $row) {
            file_put_contents($this->ledger, json_encode($row, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
        }
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function row(int $round, array $overrides = []): array
    {
        return array_replace([
            'round' => $round,
            'candidates' => 600,
            'certified' => false,
            'promoted' => false,
            'deflated_sharpe' => 0.1,
            'holdout_sharpe' => 0.2,
            'holdout_status' => StrategyCampaignStore::HOLDOUT_ACTIVE,
            'reasons' => ['deflated_sharpe_too_low'],
        ], $overrides);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function context(array $overrides = []): array
    {
        return array_replace([
            'campaign_id' => 'BTCUSDT-1d-trend-breakout-v1-test',
            'symbol' => 'BTCUSDT',
            'interval' => '1d',
            'strategy_family' => 'trend-breakout-v1',
            'candidates_per_round' => 600,
            'holdout_status' => StrategyCampaignStore::HOLDOUT_ACTIVE,
        ], $overrides);
    }
}
