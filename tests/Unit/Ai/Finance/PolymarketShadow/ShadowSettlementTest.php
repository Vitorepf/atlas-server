<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Finance\PolymarketShadow;

use App\Services\Ai\Finance\PolymarketShadow\ShadowCalibrationReport;
use App\Services\Ai\Finance\PolymarketShadow\ShadowRunState;
use App\Services\Ai\Finance\PolymarketShadow\ShadowSettlement;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ShadowSettlementTest extends TestCase
{
    private const WINDOW = 1781097600;

    protected function setUp(): void
    {
        parent::setUp();

        $migration = require database_path('migrations/2026_06_10_100000_create_atlas_poly_shadow_tables.php');
        $migration->up();
    }

    private function insertWindow(array $overrides = []): void
    {
        DB::table('atlas_poly_shadow_windows')->insert(array_merge([
            'window_start' => self::WINDOW,
            's_start' => 100000.0,
            's_start_ts_ms' => self::WINDOW * 1000,
            's_end' => 100050.0,
            's_end_ts_ms' => (self::WINDOW + 300) * 1000,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function insertTrade(array $overrides = []): int
    {
        return (int) DB::table('atlas_poly_shadow_trades')->insertGetId(array_merge([
            'window_start' => self::WINDOW,
            'side' => 'up',
            'leg' => 'latency',
            'entered_elapsed_sec' => 60.0,
            'fv' => 0.65,
            'ask' => 0.55,
            'fee_per_share' => 0.0,
            'edge' => 0.10,
            'stake' => 10.0,
            'shares' => 18.1818,
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_winning_trade_pnl_and_bankroll(): void
    {
        $this->insertWindow(); // end 100050 >= start 100000 => up
        $id = $this->insertTrade();

        $result = (new ShadowSettlement(new ShadowRunState(200.0)))
            ->settleDueWindows(self::WINDOW + 400);

        $this->assertSame(1, $result['settled_trades']);
        $trade = DB::table('atlas_poly_shadow_trades')->find($id);
        $this->assertSame('won', $trade->status);
        // shares * (1 - ask) = 18.1818 * 0.45
        $this->assertEqualsWithDelta(8.1818, (float) $trade->pnl, 0.001);

        $state = (new ShadowRunState(200.0))->snapshot();
        $this->assertEqualsWithDelta(208.1818, $state['bankroll'], 0.001);
        $this->assertEqualsWithDelta(8.1818, $state['day_pnl'], 0.001);
    }

    public function test_losing_trade_loses_stake_only(): void
    {
        $this->insertWindow();
        $id = $this->insertTrade(['side' => 'down']);

        (new ShadowSettlement(new ShadowRunState(200.0)))->settleDueWindows(self::WINDOW + 400);

        $trade = DB::table('atlas_poly_shadow_trades')->find($id);
        $this->assertSame('lost', $trade->status);
        $this->assertEqualsWithDelta(-10.0, (float) $trade->pnl, 0.001);
    }

    public function test_tie_resolves_up(): void
    {
        $this->insertWindow(['s_end' => 100000.0]);
        $id = $this->insertTrade();

        (new ShadowSettlement(new ShadowRunState(200.0)))->settleDueWindows(self::WINDOW + 400);

        $this->assertSame('up', DB::table('atlas_poly_shadow_windows')->where('window_start', self::WINDOW)->value('outcome'));
        $this->assertSame('won', DB::table('atlas_poly_shadow_trades')->find($id)->status);
    }

    public function test_missing_boundary_voids_instead_of_guessing(): void
    {
        $this->insertWindow(['s_end' => null]);
        $id = $this->insertTrade();

        $result = (new ShadowSettlement(new ShadowRunState(200.0)))->settleDueWindows(self::WINDOW + 400);

        $this->assertSame(1, $result['voided_trades']);
        $trade = DB::table('atlas_poly_shadow_trades')->find($id);
        $this->assertSame('void', $trade->status);
        $this->assertEqualsWithDelta(0.0, (float) $trade->pnl, 1e-9);
        $this->assertEqualsWithDelta(200.0, (new ShadowRunState(200.0))->snapshot()['bankroll'], 1e-6);
    }

    public function test_window_still_open_is_not_settled(): void
    {
        $this->insertWindow();
        $this->insertTrade();

        $result = (new ShadowSettlement(new ShadowRunState(200.0)))
            ->settleDueWindows(self::WINDOW + 100);

        $this->assertSame(0, $result['settled_windows']);
        $this->assertSame('open', DB::table('atlas_poly_shadow_trades')->where('window_start', self::WINDOW)->value('status'));
    }

    public function test_fee_reduces_win_and_increases_loss(): void
    {
        $this->insertWindow();
        $win = $this->insertTrade(['fee_per_share' => 0.01]);
        $loss = $this->insertTrade(['side' => 'down', 'fee_per_share' => 0.01]);

        (new ShadowSettlement(new ShadowRunState(200.0)))->settleDueWindows(self::WINDOW + 400);

        // win: shares*(1 - 0.55 - 0.01) = 18.1818*0.44 ; loss: -shares*(0.55+0.01)
        $this->assertEqualsWithDelta(8.0, (float) DB::table('atlas_poly_shadow_trades')->find($win)->pnl, 0.001);
        $this->assertEqualsWithDelta(-10.1818, (float) DB::table('atlas_poly_shadow_trades')->find($loss)->pnl, 0.001);
    }

    public function test_settlement_fills_quote_outcomes_and_report_scores_brier(): void
    {
        $this->insertWindow();
        DB::table('atlas_poly_shadow_quotes')->insert([
            ['window_start' => self::WINDOW, 'captured_elapsed_sec' => 65.0, 'fv_up' => 0.70, 'created_at' => now(), 'updated_at' => now()],
        ]);

        (new ShadowSettlement(new ShadowRunState(200.0)))->settleDueWindows(self::WINDOW + 400);

        $report = (new ShadowCalibrationReport)->build();
        $this->assertSame(1, $report['calibration']['quotes_scored']);
        // outcome up => (0.70 - 1)^2 = 0.09
        $this->assertEqualsWithDelta(0.09, $report['calibration']['brier'], 1e-6);
    }

    public function test_report_ev_capture_and_legs(): void
    {
        $this->insertWindow();
        $this->insertTrade(); // wins: predicted 0.10*18.1818=1.818, realized 8.1818
        $this->insertTrade(['side' => 'down', 'leg' => 'longshot_fade', 'edge' => 0.05, 'shares' => 10.0, 'stake' => 5.0, 'ask' => 0.5]);

        (new ShadowSettlement(new ShadowRunState(200.0)))->settleDueWindows(self::WINDOW + 400);
        $report = (new ShadowCalibrationReport)->build();

        $this->assertSame(2, $report['trades']['settled']);
        $this->assertEqualsWithDelta(1.8182 + 0.5, $report['trades']['predicted_ev'], 0.001);
        $this->assertEqualsWithDelta(8.1818 - 5.0, $report['trades']['realized_pnl'], 0.001);
        $this->assertArrayHasKey('latency', $report['trades']['by_leg']);
        $this->assertArrayHasKey('longshot_fade', $report['trades']['by_leg']);
        $this->assertArrayNotHasKey('win_rate', $report['trades']);
    }
}
