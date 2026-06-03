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

    public function test_reports_strong_and_weak_nulls_by_registered_budget_depth(): void
    {
        $strongRows = [];
        for ($i = 1; $i <= 100; $i++) {
            $strongRows[] = $this->row($i);
        }
        $this->writeRows($strongRows);

        $strong = (new StrategyCampaignReporter)->summarizeLedger($this->ledger, $this->context());
        $this->assertSame('NULL_STRONG', $strong['verdict']);

        @unlink($this->ledger);
        $this->writeRows([$this->row(1)]);
        $weak = (new StrategyCampaignReporter)->summarizeLedger($this->ledger, $this->context());
        $this->assertSame('NULL_WEAK', $weak['verdict']);
    }

    public function test_preserves_failure_distribution_and_best_holdout(): void
    {
        $this->writeRows([
            $this->row(1, ['reasons' => ['deflated_sharpe_too_low'], 'holdout_sharpe' => 0.1]),
            $this->row(2, [
                'reasons' => ['deflated_sharpe_too_low', 'pbo_too_high'],
                'holdout_sharpe' => 0.4,
                'holdout_regime_metrics' => ['bull' => ['ann_sharpe' => 0.5]],
            ]),
        ]);

        $report = (new StrategyCampaignReporter)->summarizeLedger($this->ledger, $this->context());

        $this->assertSame(2, $report['failure_distribution']['deflated_sharpe_too_low']);
        $this->assertSame(1, $report['failure_distribution']['pbo_too_high']);
        $this->assertSame(0.4, $report['summary']['best_holdout_sharpe']);
        $this->assertSame(2, $report['summary']['best_holdout_round']);
        $this->assertSame(0.5, $report['regime_summary']['best_holdout_validation_holdout']['bull']['ann_sharpe']);
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
