<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Finance\PolymarketExec;

use App\Services\Ai\Finance\PolymarketExec\BasketPlan;
use App\Services\Ai\Finance\PolymarketExec\BasketStateMachine;
use App\Services\Ai\Finance\PolymarketExec\LivePolyExecClient;
use App\Services\Ai\Finance\PolymarketExec\OnChain\LivePolyOnChainClient;
use App\Services\Ai\Finance\PolymarketExec\PolyAccountIdentity;
use App\Services\Ai\Finance\PolymarketExec\PolyExecConfig;
use App\Services\Ai\Finance\PolymarketExec\PolyExecGate;
use App\Services\Ai\Finance\PolymarketExec\SimulatedPolyExecClient;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesPolyExecTables;
use Tests\TestCase;

/**
 * Proves shadow-sim — the default mode — runs the WHOLE state machine against a
 * book and signs nothing, including the realistic case where liquidity vanishes
 * between verification and the fill (which must abort + unwind).
 */
final class PolyExecShadowSimTest extends TestCase
{
    use CreatesPolyExecTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPolyExecTables();
    }

    private function cfg(array $o = []): PolyExecConfig
    {
        return new PolyExecConfig(
            liveEnabled: $o['liveEnabled'] ?? false, maxBasketUsd: $o['maxBasketUsd'] ?? 8.0, dailyCapUsd: 25.0,
            maxConcurrentBaskets: 2, minDepthMultiple: $o['minDepthMultiple'] ?? 3.0, minPersistenceSeconds: 600,
            minNetEdgePerSet: 0.01, maxResolutionHours: 72.0, slippageBps: 100, takerFeeRate: 0.0,
            estGasUsdPerBasket: 0.0, killSwitchPath: sys_get_temp_dir().'/atlas-poly-kill-none-'.uniqid(),
        );
    }

    private function plan(float $targetSets = 2.0): BasketPlan
    {
        $legs = [];
        foreach (['A', 'B', 'C'] as $i => $t) {
            $legs[] = ['token' => $t, 'question' => 'Q'.$t, 'position' => $i,
                'plan_depth' => 1000.0, 'target_price' => 0.30, 'limit_price' => 0.303, 'target_size' => $targetSets];
        }

        return new BasketPlan('evt', 'long_sum_under', 'simple_buy_all_legs', $legs, $targetSets, 0.90,
            round(0.90 * $targetSets, 4), round(0.10 * $targetSets, 4), 0.10, 0.0, 0.0, 8.0, 100, 1000.0, 1200,
            '2999-01-01T00:00:00+00:00', 24.0, 0.30, 0.30);
    }

    private function deepBook(): callable
    {
        return fn (string $t): ?array => ['asks' => [['price' => 0.30, 'size' => 1000]], 'bids' => [['price' => 0.28, 'size' => 1000]]];
    }

    public function test_sim_end_to_end_fills_and_reconciles_against_the_book(): void
    {
        $cfg = $this->cfg();
        $book = $this->deepBook();
        $client = new SimulatedPolyExecClient($book);          // sim fills off the book
        $machine = new BasketStateMachine($cfg, $client, new PolyExecGate($cfg), $book);

        $summary = $machine->execute($this->plan(2.0), 'sim-1', 's1');

        $this->assertSame('filled', $summary['status']);
        $this->assertSame(3, $summary['legs_filled']);
        // Reconciliation: the simulated position equals what we believe we filled.
        foreach (['A', 'B', 'C'] as $t) {
            $this->assertEqualsWithDelta(2.0, $client->positionSize($t), 1e-6);
        }
        $reconcile = DB::table('atlas_poly_exec_events')->where('basket_id', 'sim-1')->where('kind', 'reconcile')->first();
        $this->assertNotNull($reconcile);
        $detail = json_decode((string) $reconcile->detail, true);
        $this->assertSame([], $detail['discrepancies'], 'sim position must reconcile exactly');
    }

    public function test_sim_aborts_and_unwinds_when_liquidity_vanishes_after_verify(): void
    {
        $cfg = $this->cfg();
        // Verify sees deep books; the fill book is empty on leg B (liquidity gone).
        $verifyBook = $this->deepBook();
        $fillBook = function (string $t): ?array {
            if ($t === 'B') {
                return ['asks' => [], 'bids' => [['price' => 0.28, 'size' => 1000]]]; // no asks to buy
            }

            return ['asks' => [['price' => 0.30, 'size' => 1000]], 'bids' => [['price' => 0.28, 'size' => 1000]]];
        };
        $client = new SimulatedPolyExecClient($fillBook);
        $machine = new BasketStateMachine($cfg, $client, new PolyExecGate($cfg), $verifyBook);

        $summary = $machine->execute($this->plan(2.0), 'sim-2', 's1');

        $this->assertSame('unwound', $summary['status']);
        // A filled, B unfillable -> abort; A unwound back to flat.
        $this->assertEqualsWithDelta(0.0, $client->positionSize('A'), 1e-6, 'A must be unwound to flat');
        $this->assertEqualsWithDelta(0.0, $client->positionSize('C'), 1e-6, 'C never bought');
        $this->assertLessThanOrEqual(0.0, $summary['realized_pnl_usd']);
    }

    public function test_command_preflight_emits_json_without_touching_money(): void
    {
        $this->artisan('atlas:finance:poly-exec', ['action' => 'preflight', '--mode' => 'sim', '--json'])
            ->assertExitCode(0);
    }

    public function test_command_preflight_uses_sim_scope_for_runtime_budget(): void
    {
        DB::table('atlas_poly_exec_daily')->insert([
            'trade_date' => now()->toDateString(),
            'mode' => 'sim',
            'deployed_usd' => 25.0,
            'realized_pnl_usd' => 0.0,
            'halted' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $scope = 'fresh preflight';
        $ledgerMode = 's'.substr(hash('sha256', 'fresh-preflight'), 0, 7);

        $exit = Artisan::call('atlas:finance:poly-exec', [
            'action' => 'preflight',
            '--mode' => 'sim',
            '--daily-cap' => 16.66,
            '--sim-scope' => $scope,
            '--json' => true,
        ]);

        $out = Artisan::output();
        $payload = json_decode($out, true);

        $this->assertSame(0, $exit);
        $this->assertSame('fresh-preflight', $payload['sim_scope']);
        $this->assertSame($ledgerMode, $payload['sim_ledger_mode']);
        $this->assertSame(0.0, (float) $payload['deployed_today_usd']);
        $this->assertTrue((bool) $payload['runtime_caps']['allowed']);
        $dailyBudget = collect($payload['runtime_caps']['checks'])->firstWhere('name', 'daily_budget');
        $this->assertSame('deployed=$0.00 cap=$16.66 remaining=$16.66', $dailyBudget['reason']);
    }

    public function test_command_run_sim_is_a_clean_noop_with_no_candidates(): void
    {
        // Empty lifecycle -> nothing to execute; must exit cleanly, not error.
        $this->artisan('atlas:finance:poly-exec', ['action' => 'run', '--mode' => 'sim', '--json'])
            ->assertExitCode(0);
        $this->assertSame(0, DB::table('atlas_poly_exec_baskets')->count());
    }

    public function test_command_plan_skips_legacy_opportunities_without_measured_activity(): void
    {
        $this->createLegacyArbOpportunityTable();
        DB::table('atlas_poly_arb_opportunities')->insert([
            'event_slug' => 'legacy-short-without-volume',
            'kind' => 'short_sum_over',
            'event_title' => 'Legacy Short Without Volume',
            'execution_class' => 'requires_minting_full_set',
            'first_seen_at' => now()->subHour(),
            'last_seen_at' => now(),
            'observations' => 10,
            'last_sum' => 1.02,
            'last_profit_per_set' => 0.02,
            'last_sets' => 100,
            'last_profit_usd' => 2,
            'max_sets' => 100,
            'max_profit_usd' => 2,
            'volume_24hr' => null,
            'liquidity' => null,
            'dead_book' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exit = Artisan::call('atlas:finance:poly-exec', [
            'action' => 'plan',
            '--mode' => 'sim',
            '--kind' => 'short',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('"candidates": 0', Artisan::output());
    }

    public function test_command_plan_skips_stale_lifecycle_opportunities(): void
    {
        $this->createLegacyArbOpportunityTable();
        DB::table('atlas_poly_arb_opportunities')->insert([
            'event_slug' => 'stale-but-large',
            'kind' => 'long_sum_under',
            'event_title' => 'Stale But Large',
            'execution_class' => 'simple_buy_all_legs',
            'first_seen_at' => now()->subHours(2),
            'last_seen_at' => now()->subHour(),
            'observations' => 10,
            'last_sum' => 0.95,
            'last_profit_per_set' => 0.05,
            'last_sets' => 100,
            'last_profit_usd' => 5,
            'max_sets' => 100,
            'max_profit_usd' => 5,
            'volume_24hr' => 500,
            'liquidity' => 5000,
            'dead_book' => false,
            'created_at' => now()->subHours(2),
            'updated_at' => now()->subHour(),
        ]);

        $exit = Artisan::call('atlas:finance:poly-exec', [
            'action' => 'plan',
            '--mode' => 'sim',
            '--kind' => 'long',
            '--max-signal-age-seconds' => 60,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('"candidates": 0', Artisan::output());
    }

    public function test_command_plan_uses_short_resolution_ceiling_for_short_baskets(): void
    {
        $this->createLegacyArbLifecycleTables();
        $legs = [
            ['token' => 'A', 'question' => 'Outcome A'],
            ['token' => 'B', 'question' => 'Outcome B'],
            ['token' => 'C', 'question' => 'Outcome C'],
        ];

        DB::table('atlas_poly_arb_opportunities')->insert([
            'event_slug' => 'short-far-but-bank-now',
            'kind' => 'short_sum_over',
            'event_title' => 'Short Far But Bank Now',
            'execution_class' => 'requires_minting_full_set',
            'first_seen_at' => now()->subMinutes(20),
            'last_seen_at' => now(),
            'observations' => 3,
            'last_sum' => 1.20,
            'last_profit_per_set' => 0.20,
            'last_sets' => 100,
            'last_profit_usd' => 20,
            'max_sets' => 100,
            'max_profit_usd' => 20,
            'volume_24hr' => 500,
            'liquidity' => 5000,
            'dead_book' => false,
            'created_at' => now()->subMinutes(20),
            'updated_at' => now(),
        ]);
        DB::table('atlas_poly_arb_signals')->insert([
            'session_id' => 'test',
            'event_slug' => 'short-far-but-bank-now',
            'event_title' => 'Short Far But Bank Now',
            'kind' => 'short_sum_over',
            'execution_class' => 'requires_minting_full_set',
            'n_legs' => 3,
            'sum' => 1.20,
            'profit_per_set' => 0.20,
            'sets' => 100,
            'profit_usd' => 20,
            'cost_usd' => 100,
            'legs' => json_encode($legs),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Http::fake([
            'https://1.1.1.1/*' => Http::response([
                'Answer' => [['data' => '104.18.34.205']],
            ], 200),
            'https://gamma-api.polymarket.com/events*' => Http::response([
                [
                    'negRisk' => true,
                    'negRiskMarketID' => '0xcondition',
                    'slug' => 'short-far-but-bank-now',
                    'title' => 'Short Far But Bank Now',
                    'endDate' => now()->addHours(200)->toIso8601String(),
                    'markets' => [],
                ],
            ], 200),
            'https://clob.polymarket.com/book*' => Http::response([
                'asks' => [['price' => '0.42', 'size' => '1000']],
                'bids' => [['price' => '0.40', 'size' => '1000']],
            ], 200),
        ]);

        $exit = Artisan::call('atlas:finance:poly-exec', [
            'action' => 'plan',
            '--mode' => 'sim',
            '--kind' => 'short',
            '--event-slug' => 'short-far-but-bank-now',
            '--json' => true,
        ]);

        $out = Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('"gate_allowed": true', $out);
        $this->assertStringNotContainsString('"resolution_horizon"', $out);
    }

    public function test_command_monitor_sim_runs_bounded_cycle_without_candidates(): void
    {
        $logDir = sys_get_temp_dir().'/atlas-poly-monitor-log-'.uniqid();
        $exit = Artisan::call('atlas:finance:poly-exec', [
            'action' => 'monitor',
            '--mode' => 'sim',
            '--cycles' => 1,
            '--interval' => 0,
            '--max-candidates' => 2,
            '--slow-cycle-seconds' => 1,
            '--market-read-timeout' => 2,
            '--candidate-time-budget' => 3,
            '--monitor-log-dir' => $logDir,
            '--sim-scope' => 'fresh test!',
            '--json' => true,
        ]);

        $out = Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('"action": "monitor"', $out);
        $this->assertStringContainsString('"cycles_requested": 1', $out);
        $this->assertStringContainsString('"executed_total": 0', $out);
        $this->assertStringContainsString('"real_money_touched": false', $out);
        $this->assertStringContainsString('"live_policy_blocked": true', $out);
        $this->assertStringContainsString('"slow_cycle_seconds": 1', $out);
        $this->assertStringContainsString('"market_read_timeout_seconds": 2', $out);
        $this->assertStringContainsString('"candidate_time_budget_seconds": 3', $out);
        $this->assertStringContainsString('"sim_scope": "fresh-test"', $out);
        $this->assertStringContainsString('"sim_ledger_mode": "s'.substr(hash('sha256', 'fresh-test'), 0, 7).'"', $out);
        $this->assertStringContainsString('"max_signal_age_seconds": 900', $out);
        $this->assertStringContainsString('"slow_cycles": 0', $out);
        $this->assertStringContainsString('"monitor_log_path"', $out);
        $this->assertStringContainsString('"reason_counts": []', $out);
        $this->assertStringContainsString('"result_samples": []', $out);

        $logs = glob($logDir.'/*.jsonl') ?: [];
        $this->assertCount(1, $logs);
        $lines = file($logs[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $this->assertCount(3, $lines);
        $events = array_map(fn (string $line): ?string => json_decode($line, true)['event'] ?? null, $lines);
        $this->assertSame(['start', 'cycle', 'summary'], $events);
        $start = json_decode($lines[0], true);
        $summary = json_decode($lines[2], true);
        $this->assertSame('fresh-test', $start['sim_scope']);
        $this->assertSame('fresh-test', $summary['sim_scope']);
        $this->assertSame('s'.substr(hash('sha256', 'fresh-test'), 0, 7), $start['sim_ledger_mode']);
        $this->assertSame('s'.substr(hash('sha256', 'fresh-test'), 0, 7), $summary['sim_ledger_mode']);
    }

    public function test_command_monitor_preserves_broad_scan_page_request(): void
    {
        $this->createLegacyArbLifecycleTables();

        Http::fake([
            'https://1.1.1.1/*' => Http::response([
                'Answer' => [['data' => '104.18.34.205']],
            ], 200),
            'https://gamma-api.polymarket.com/events*' => Http::response([], 200),
            'https://clob.polymarket.com/book*' => Http::response([
                'asks' => [],
                'bids' => [],
            ], 200),
        ]);

        $logDir = sys_get_temp_dir().'/atlas-poly-monitor-broad-scan-log-'.uniqid();
        $exit = Artisan::call('atlas:finance:poly-exec', [
            'action' => 'monitor',
            '--mode' => 'sim',
            '--cycles' => 1,
            '--interval' => 0,
            '--max-candidates' => 1,
            '--market-read-timeout' => 2,
            '--candidate-time-budget' => 1,
            '--scan-before-cycle' => true,
            '--scan-pages' => 40,
            '--scan-per-page' => 50,
            '--scan-time-budget' => 1,
            '--scan-max-clob-verifications' => 1,
            '--monitor-log-dir' => $logDir,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);

        $logs = glob($logDir.'/*.jsonl') ?: [];
        $this->assertCount(1, $logs);
        $lines = file($logs[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $start = json_decode($lines[0], true);

        $this->assertSame(40, $start['scan_options']['pages']);
        $this->assertSame(50, $start['scan_options']['per_page']);
    }

    public function test_command_monitor_can_refresh_lifecycle_with_bounded_shadow_scan(): void
    {
        $this->createLegacyArbLifecycleTables();

        $gammaCalls = 0;
        Http::fake([
            'https://1.1.1.1/*' => Http::response([
                'Answer' => [['data' => '104.18.34.205']],
            ], 200),
            'https://gamma-api.polymarket.com/events*' => function () use (&$gammaCalls) {
                if ($gammaCalls++ === 0) {
                    usleep(1_200_000);
                }

                return Http::response([
                    [
                        'negRisk' => true,
                        'negRiskMarketID' => '0xcondition',
                        'slug' => 'scan-cycle-short',
                        'title' => 'Scan Cycle Short',
                        'endDate' => now()->addHours(24)->toIso8601String(),
                        'volume24hr' => 1234.56,
                        'liquidity' => 9876.54,
                        'markets' => [
                            $this->arbMarket('A', 'Will A win?', 0.40),
                            $this->arbMarket('B', 'Will B win?', 0.40),
                            $this->arbMarket('C', 'Will C win?', 0.40),
                        ],
                    ],
                ], 200);
            },
            'https://clob.polymarket.com/book*' => Http::response([
                'asks' => [['price' => '0.45', 'size' => '100']],
                'bids' => [['price' => '0.40', 'size' => '100']],
            ], 200),
        ]);

        $logDir = sys_get_temp_dir().'/atlas-poly-monitor-scan-log-'.uniqid();
        $exit = Artisan::call('atlas:finance:poly-exec', [
            'action' => 'monitor',
            '--mode' => 'sim',
            '--cycles' => 1,
            '--interval' => 0,
            '--max-candidates' => 2,
            '--market-read-timeout' => 2,
            '--candidate-time-budget' => 1,
            '--scan-before-cycle' => true,
            '--scan-pages' => 1,
            '--scan-per-page' => 10,
            '--scan-time-budget' => 10,
            '--scan-max-clob-verifications' => 1,
            '--event-slug' => 'scan-cycle-short',
            '--monitor-log-dir' => $logDir,
            '--json' => true,
        ]);

        $out = Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('"scan_before_cycle": true', $out);
        $this->assertStringContainsString('"signals": 1', $out);
        $this->assertStringContainsString('"processed": 1', $out);
        $this->assertStringNotContainsString('"blocked": "candidate_time_budget_exceeded"', $out);
        $this->assertSame(1, DB::table('atlas_poly_arb_scans')->count());
        $this->assertSame(1, DB::table('atlas_poly_arb_signals')->count());
        $this->assertSame(1, DB::table('atlas_poly_arb_opportunities')->count());

        $logs = glob($logDir.'/*.jsonl') ?: [];
        $this->assertCount(1, $logs);
        $lines = file($logs[0], FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $events = array_map(fn (string $line): ?string => json_decode($line, true)['event'] ?? null, $lines);
        $this->assertSame(['start', 'scan', 'cycle', 'summary'], $events);
    }

    public function test_command_monitor_live_is_refused(): void
    {
        $this->artisan('atlas:finance:poly-exec', ['action' => 'monitor', '--mode' => 'live'])
            ->expectsOutputToContain('MONITOR refused: monitor is sim-only')
            ->assertExitCode(1);
    }

    public function test_command_status_reports_latest_monitor_heartbeat_without_touching_money(): void
    {
        $logDir = sys_get_temp_dir().'/atlas-poly-monitor-status-'.uniqid();

        $monitorExit = Artisan::call('atlas:finance:poly-exec', [
            'action' => 'monitor',
            '--mode' => 'sim',
            '--cycles' => 1,
            '--interval' => 0,
            '--max-candidates' => 2,
            '--monitor-log-dir' => $logDir,
            '--json' => true,
        ]);
        $this->assertSame(0, $monitorExit);

        $statusExit = Artisan::call('atlas:finance:poly-exec', [
            'action' => 'status',
            '--mode' => 'sim',
            '--monitor-log-dir' => $logDir,
            '--json' => true,
        ]);

        $out = Artisan::output();
        $this->assertSame(0, $statusExit);
        $this->assertStringContainsString('"action": "status"', $out);
        $this->assertStringContainsString('"latest_log_path"', $out);
        $this->assertStringContainsString('"cycles_recorded": 1', $out);
        $this->assertStringContainsString('"latest_cycle"', $out);
        $this->assertStringContainsString('"latest_summary"', $out);
        $this->assertStringContainsString('"reason_counts": []', $out);
        $this->assertStringContainsString('"result_samples": []', $out);
        $this->assertStringContainsString('"real_money_touched": false', $out);
        $this->assertStringContainsString('"live_policy_blocked": true', $out);
    }

    public function test_command_qualify_reports_not_ready_from_shadow_logs_without_touching_money(): void
    {
        $logDir = sys_get_temp_dir().'/atlas-poly-monitor-qualify-'.uniqid();

        $monitorExit = Artisan::call('atlas:finance:poly-exec', [
            'action' => 'monitor',
            '--mode' => 'sim',
            '--cycles' => 1,
            '--interval' => 0,
            '--max-candidates' => 2,
            '--monitor-log-dir' => $logDir,
            '--json' => true,
        ]);
        $this->assertSame(0, $monitorExit);

        $qualifyExit = Artisan::call('atlas:finance:poly-exec', [
            'action' => 'qualify',
            '--mode' => 'sim',
            '--monitor-log-dir' => $logDir,
            '--qualification-min-cycles' => 2,
            '--qualification-min-executed' => 1,
            '--json' => true,
        ]);

        $out = Artisan::output();
        $this->assertSame(0, $qualifyExit);
        $this->assertStringContainsString('"action": "qualify"', $out);
        $this->assertStringContainsString('"decision": "not_qualified"', $out);
        $this->assertStringContainsString('"qualified_for_real_money": false', $out);
        $this->assertStringContainsString('"real_money_test_possible_now": false', $out);
        $this->assertStringContainsString('"real_money_touched": false', $out);
        $this->assertStringContainsString('"live_policy_blocked": true', $out);
        $this->assertStringContainsString('"logs_considered": 1', $out);
        $this->assertStringContainsString('"cycles_observed": 1', $out);
        $this->assertStringContainsString('"cycle_duration_seconds"', $out);
        $this->assertStringContainsString('"shadow_cycles_min"', $out);
        $this->assertStringContainsString('"shadow_executed_min"', $out);
        $this->assertStringContainsString('"scan_before_cycle_evidence"', $out);
        $this->assertStringContainsString('"finance_policy_allows_live"', $out);
    }

    public function test_command_qualify_fails_when_monitor_scan_budget_is_exhausted(): void
    {
        $logDir = sys_get_temp_dir().'/atlas-poly-monitor-budget-'.uniqid();
        mkdir($logDir, 0775, true);
        $path = $logDir.'/budget-exhausted.jsonl';

        $rows = [
            [
                'event' => 'start',
                'session_id' => 'budget-exhausted',
                'created_at' => now()->toIso8601String(),
                'schema_version' => 'atlas.finance.poly_exec.monitor_log.v1',
            ],
            [
                'event' => 'cycle',
                'session_id' => 'budget-exhausted',
                'cycle' => 1,
                'candidates' => 1,
                'processed' => 1,
                'dispatched' => 1,
                'executed' => 0,
                'blocked' => null,
                'statuses' => ['gated' => 1],
                'reason_counts' => ['net_edge' => 1],
                'scan' => [
                    'skipped' => false,
                    'scanned' => 200,
                    'eligible' => 35,
                    'shortlisted' => 30,
                    'verified' => 23,
                    'signals' => 5,
                    'budget_exhausted' => true,
                    'skipped_too_many_legs' => 25,
                    'duration_seconds' => 180.87,
                ],
                'duration_seconds' => 228.0,
                'slow' => false,
                'created_at' => now()->toIso8601String(),
                'schema_version' => 'atlas.finance.poly_exec.monitor_log.v1',
            ],
            [
                'event' => 'summary',
                'session_id' => 'budget-exhausted',
                'executed_total' => 0,
                'created_at' => now()->toIso8601String(),
                'schema_version' => 'atlas.finance.poly_exec.monitor_log.v1',
            ],
        ];

        foreach ($rows as $row) {
            file_put_contents($path, json_encode($row, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
        }

        $exit = Artisan::call('atlas:finance:poly-exec', [
            'action' => 'qualify',
            '--mode' => 'sim',
            '--monitor-log-dir' => $logDir,
            '--qualification-min-cycles' => 1,
            '--qualification-min-executed' => 0,
            '--qualification-max-slow-ratio' => 1,
            '--json' => true,
        ]);

        $out = Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('"decision": "not_qualified"', $out);
        $this->assertStringContainsString('"scan_cycles": 1', $out);
        $this->assertStringContainsString('"scan_budget_exhausted_cycles": 1', $out);
        $this->assertStringContainsString('"scan_duration_seconds"', $out);
        $this->assertStringContainsString('"scan_budget_exhaustion"', $out);
        $this->assertStringContainsString('budget_exhausted=0', $out);
    }

    public function test_command_qualify_fails_when_monitor_scan_coverage_is_partial(): void
    {
        $logDir = sys_get_temp_dir().'/atlas-poly-monitor-coverage-'.uniqid();
        mkdir($logDir, 0775, true);
        $path = $logDir.'/partial-scan.jsonl';

        $rows = [
            [
                'event' => 'start',
                'session_id' => 'partial-scan',
                'scan_before_cycle' => true,
                'scan_options' => [
                    'pages' => 6,
                    'per_page' => 50,
                ],
                'created_at' => now()->toIso8601String(),
                'schema_version' => 'atlas.finance.poly_exec.monitor_log.v1',
            ],
            [
                'event' => 'cycle',
                'session_id' => 'partial-scan',
                'cycle' => 1,
                'candidates' => 1,
                'processed' => 1,
                'dispatched' => 1,
                'executed' => 1,
                'blocked' => null,
                'statuses' => ['settled' => 1],
                'reason_counts' => ['settled' => 1],
                'scan' => [
                    'skipped' => false,
                    'scanned' => 50,
                    'eligible' => 4,
                    'shortlisted' => 3,
                    'verified' => 3,
                    'signals' => 0,
                    'budget_exhausted' => false,
                    'duration_seconds' => 8.23,
                ],
                'duration_seconds' => 27.17,
                'slow' => false,
                'created_at' => now()->toIso8601String(),
                'schema_version' => 'atlas.finance.poly_exec.monitor_log.v1',
            ],
            [
                'event' => 'summary',
                'session_id' => 'partial-scan',
                'executed_total' => 1,
                'created_at' => now()->toIso8601String(),
                'schema_version' => 'atlas.finance.poly_exec.monitor_log.v1',
            ],
        ];

        foreach ($rows as $row) {
            file_put_contents($path, json_encode($row, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
        }

        $exit = Artisan::call('atlas:finance:poly-exec', [
            'action' => 'qualify',
            '--mode' => 'sim',
            '--monitor-log-dir' => $logDir,
            '--qualification-min-cycles' => 1,
            '--qualification-min-executed' => 1,
            '--qualification-max-slow-ratio' => 1,
            '--json' => true,
        ]);

        $out = Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('"decision": "not_qualified"', $out);
        $this->assertStringContainsString('"scan_expected_events": 300', $out);
        $this->assertStringContainsString('"scan_undercovered_cycles": 1', $out);
        $this->assertStringContainsString('"scan_coverage_floor"', $out);
        $this->assertStringContainsString('undercovered=1', $out);
    }

    public function test_command_qualify_fails_when_shadow_execution_unwinds(): void
    {
        $logDir = sys_get_temp_dir().'/atlas-poly-monitor-unwound-'.uniqid();
        mkdir($logDir, 0775, true);
        $path = $logDir.'/unwound.jsonl';

        $rows = [
            [
                'event' => 'start',
                'session_id' => 'unwound',
                'scan_before_cycle' => true,
                'scan_options' => [
                    'pages' => 6,
                    'per_page' => 50,
                ],
                'created_at' => now()->toIso8601String(),
                'schema_version' => 'atlas.finance.poly_exec.monitor_log.v1',
            ],
            [
                'event' => 'cycle',
                'session_id' => 'unwound',
                'cycle' => 1,
                'candidates' => 1,
                'processed' => 1,
                'dispatched' => 1,
                'executed' => 1,
                'blocked' => null,
                'statuses' => ['unwound' => 1],
                'reason_counts' => ['unwound' => 1],
                'result_samples' => [
                    [
                        'event_slug' => 'thin-long-basket',
                        'kind' => 'long_sum_under',
                        'status' => 'unwound',
                        'realized_pnl_usd' => -0.01,
                        'est_profit_usd' => 0.03,
                    ],
                ],
                'scan' => [
                    'skipped' => false,
                    'scanned' => 300,
                    'eligible' => 40,
                    'shortlisted' => 30,
                    'verified' => 30,
                    'signals' => 1,
                    'budget_exhausted' => false,
                    'duration_seconds' => 60.0,
                ],
                'duration_seconds' => 80.0,
                'slow' => false,
                'created_at' => now()->toIso8601String(),
                'schema_version' => 'atlas.finance.poly_exec.monitor_log.v1',
            ],
            [
                'event' => 'summary',
                'session_id' => 'unwound',
                'executed_total' => 1,
                'created_at' => now()->toIso8601String(),
                'schema_version' => 'atlas.finance.poly_exec.monitor_log.v1',
            ],
        ];

        foreach ($rows as $row) {
            file_put_contents($path, json_encode($row, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
        }

        $exit = Artisan::call('atlas:finance:poly-exec', [
            'action' => 'qualify',
            '--mode' => 'sim',
            '--monitor-log-dir' => $logDir,
            '--qualification-min-cycles' => 1,
            '--qualification-min-executed' => 1,
            '--qualification-max-slow-ratio' => 1,
            '--json' => true,
        ]);

        $out = Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('"decision": "not_qualified"', $out);
        $this->assertStringContainsString('"settled_total": 0', $out);
        $this->assertStringContainsString('"unsafe_terminal_total": 1', $out);
        $this->assertStringContainsString('"shadow_settled_min"', $out);
        $this->assertStringContainsString('"shadow_unsafe_terminal_absent"', $out);
    }

    public function test_command_qualify_keeps_idempotent_replays_out_of_execution_counts(): void
    {
        $logDir = sys_get_temp_dir().'/atlas-poly-monitor-replay-'.uniqid();
        mkdir($logDir, 0775, true);
        $path = $logDir.'/replay.jsonl';

        $baseScan = [
            'skipped' => false,
            'scanned' => 300,
            'eligible' => 40,
            'shortlisted' => 30,
            'verified' => 30,
            'signals' => 1,
            'budget_exhausted' => false,
            'duration_seconds' => 60.0,
        ];
        $rows = [
            [
                'event' => 'start',
                'session_id' => 'replay',
                'sim_scope' => 'window-a',
                'sim_ledger_mode' => 's'.substr(hash('sha256', 'window-a'), 0, 7),
                'scan_before_cycle' => true,
                'scan_options' => ['pages' => 6, 'per_page' => 50],
                'created_at' => now()->toIso8601String(),
                'schema_version' => 'atlas.finance.poly_exec.monitor_log.v1',
            ],
            [
                'event' => 'cycle',
                'session_id' => 'replay',
                'cycle' => 1,
                'candidates' => 1,
                'processed' => 1,
                'dispatched' => 1,
                'executed' => 1,
                'idempotent_replays' => 0,
                'idempotent_replay_statuses' => [],
                'blocked' => null,
                'statuses' => ['unwound' => 1],
                'reason_counts' => ['unwound' => 1],
                'result_samples' => [['event_slug' => 'thin-long-basket', 'kind' => 'long_sum_under', 'status' => 'unwound']],
                'scan' => $baseScan,
                'duration_seconds' => 80.0,
                'slow' => false,
                'created_at' => now()->toIso8601String(),
                'schema_version' => 'atlas.finance.poly_exec.monitor_log.v1',
            ],
            [
                'event' => 'cycle',
                'session_id' => 'replay',
                'cycle' => 2,
                'candidates' => 1,
                'processed' => 1,
                'dispatched' => 1,
                'executed' => 0,
                'idempotent_replays' => 1,
                'idempotent_replay_statuses' => ['unwound' => 1],
                'blocked' => null,
                'statuses' => [],
                'reason_counts' => ['terminal_basket_already_recorded' => 1],
                'result_samples' => [[
                    'event_slug' => 'thin-long-basket',
                    'kind' => 'long_sum_under',
                    'status' => 'unwound',
                    'status_reason' => 'terminal_basket_already_recorded',
                    'idempotent_replay' => true,
                ]],
                'scan' => $baseScan,
                'duration_seconds' => 80.0,
                'slow' => false,
                'created_at' => now()->toIso8601String(),
                'schema_version' => 'atlas.finance.poly_exec.monitor_log.v1',
            ],
            [
                'event' => 'summary',
                'session_id' => 'replay',
                'executed_total' => 1,
                'idempotent_replays_total' => 1,
                'sim_scope' => 'window-a',
                'sim_ledger_mode' => 's'.substr(hash('sha256', 'window-a'), 0, 7),
                'created_at' => now()->toIso8601String(),
                'schema_version' => 'atlas.finance.poly_exec.monitor_log.v1',
            ],
        ];

        foreach ($rows as $row) {
            file_put_contents($path, json_encode($row, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
        }

        $exit = Artisan::call('atlas:finance:poly-exec', [
            'action' => 'qualify',
            '--mode' => 'sim',
            '--monitor-log-dir' => $logDir,
            '--qualification-min-cycles' => 2,
            '--qualification-min-executed' => 1,
            '--qualification-max-slow-ratio' => 1,
            '--json' => true,
        ]);

        $out = Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('"executed_total": 1', $out);
        $this->assertStringContainsString('"idempotent_replays": 1', $out);
        $this->assertStringContainsString('"sim_scopes": [', $out);
        $this->assertStringContainsString('"window-a"', $out);
        $this->assertStringContainsString('"sim_ledger_modes": [', $out);
        $this->assertStringContainsString('"s'.substr(hash('sha256', 'window-a'), 0, 7).'"', $out);
        $this->assertStringContainsString('"unsafe_terminal_total": 1', $out);
        $this->assertStringContainsString('"idempotent_replay_statuses": {', $out);
    }

    public function test_command_run_live_is_refused_by_finance_policy(): void
    {
        config()->set('atlas.finance_poly_exec.live_enabled', true);
        config()->set('atlas.finance.live_trading_allowed', false);

        $this->artisan('atlas:finance:poly-exec', [
            'action' => 'run',
            '--mode' => 'live',
            '--confirm' => true,
        ])
            ->expectsOutputToContain('LIVE refused: Atlas Finance policy blocks live market execution')
            ->assertExitCode(1);
    }

    public function test_command_preflight_live_short_reports_onchain_not_ready(): void
    {
        config()->set('atlas.finance_poly_exec.live_enabled', true);
        config()->set('atlas.finance_poly_exec.live.account_kind', 'proxy');
        config()->set('atlas.finance.live_trading_allowed', false);

        $keys = [
            'ATLAS_POLY_PRIVATE_KEY' => '0x'.str_repeat('1', 64),
            'ATLAS_POLY_FUNDER_ADDRESS' => '0x'.str_repeat('2', 40),
            'ATLAS_POLY_CLOB_API_KEY' => 'key',
            'ATLAS_POLY_CLOB_API_SECRET' => 'secret',
            'ATLAS_POLY_CLOB_API_PASSPHRASE' => 'passphrase',
        ];

        $this->setEnvVars($keys);
        try {
            $exit = Artisan::call('atlas:finance:poly-exec', [
                'action' => 'preflight',
                '--mode' => 'live',
                '--kind' => 'short',
                '--json' => true,
            ]);

            $out = Artisan::output();
            $this->assertSame(0, $exit);
            $this->assertStringContainsString('"live_trading_blocked": true', $out);
            $this->assertStringContainsString('"finance_policy"', $out);
            $this->assertStringContainsString('"live_ready": false', $out);
            $this->assertStringContainsString('"finance_policy_blocked"', $out);
            $this->assertStringContainsString('"onchain_requires_eoa"', $out);
        } finally {
            $this->clearEnvVars(array_keys($keys));
        }
    }

    public function test_live_clients_fail_closed_when_finance_policy_blocks(): void
    {
        config()->set('atlas.finance.live_trading_allowed', false);

        $cfg = $this->cfg(['liveEnabled' => true]);
        $identity = new PolyAccountIdentity(
            hasPrivateKey: true,
            hasFunderAddress: false,
            hasApiKey: true,
            hasApiSecret: true,
            hasApiPassphrase: true,
            configuredKind: PolyAccountIdentity::KIND_EOA,
        );

        $exec = new LivePolyExecClient($cfg, $identity, sys_get_temp_dir().'/missing-poly-runtime');
        $fill = $exec->buyLimit('token-a', 0.25, 1.0);
        $this->assertFalse($fill->ok);
        $this->assertSame('finance_policy_live_blocked', $fill->error);
        $this->assertNull($exec->positionSize('token-a'));

        $onChain = new LivePolyOnChainClient($cfg, $identity, sys_get_temp_dir().'/missing-poly-runtime');
        $tx = $onChain->splitFullSet('condition-a', ['token-a', 'token-b'], 1.0, true);
        $this->assertFalse($tx->ok);
        $this->assertSame('finance_policy_live_blocked', $tx->error);
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function setEnvVars(array $vars): void
    {
        foreach ($vars as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /**
     * @param  list<string>  $keys
     */
    private function clearEnvVars(array $keys): void
    {
        foreach ($keys as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
    }

    private function createLegacyArbOpportunityTable(): void
    {
        Schema::dropIfExists('atlas_poly_arb_opportunities');
        Schema::create('atlas_poly_arb_opportunities', function (Blueprint $table): void {
            $table->id();
            $table->string('event_slug', 180);
            $table->string('kind', 30);
            $table->string('event_title', 300)->nullable();
            $table->string('execution_class', 40);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->unsignedInteger('observations')->default(1);
            $table->decimal('last_sum', 10, 6);
            $table->decimal('last_profit_per_set', 10, 6);
            $table->decimal('last_sets', 14, 4);
            $table->decimal('last_profit_usd', 12, 4);
            $table->decimal('max_sets', 14, 4);
            $table->decimal('max_profit_usd', 12, 4);
            $table->decimal('volume_24hr', 14, 2)->nullable();
            $table->decimal('liquidity', 14, 2)->nullable();
            $table->boolean('dead_book')->default(false);
            $table->timestamps();
        });
    }

    private function createLegacyArbLifecycleTables(): void
    {
        Schema::dropIfExists('atlas_poly_arb_signals');
        Schema::dropIfExists('atlas_poly_arb_scans');
        $this->createLegacyArbOpportunityTable();

        Schema::create('atlas_poly_arb_signals', function (Blueprint $table): void {
            $table->id();
            $table->string('session_id', 40)->nullable()->index();
            $table->string('event_slug', 180)->index();
            $table->string('event_title', 300)->nullable();
            $table->string('kind', 30)->index();
            $table->string('execution_class', 40);
            $table->unsignedInteger('n_legs');
            $table->decimal('sum', 10, 6);
            $table->decimal('profit_per_set', 10, 6);
            $table->decimal('sets', 14, 4);
            $table->decimal('profit_usd', 12, 4);
            $table->decimal('cost_usd', 14, 4);
            $table->json('legs');
            $table->timestamps();
        });

        Schema::create('atlas_poly_arb_scans', function (Blueprint $table): void {
            $table->id();
            $table->string('session_id', 40)->nullable()->index();
            $table->unsignedInteger('scanned_events');
            $table->unsignedInteger('eligible_events');
            $table->unsignedInteger('shortlisted');
            $table->unsignedInteger('verified');
            $table->unsignedInteger('signals_found');
            $table->decimal('best_long_sum', 10, 6)->nullable();
            $table->decimal('best_short_sum', 10, 6)->nullable();
            $table->timestamps();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function arbMarket(string $token, string $question, float $bid): array
    {
        return [
            'closed' => false,
            'active' => true,
            'clobTokenIds' => [$token, $token.'-no'],
            'outcomes' => ['Yes', 'No'],
            'bestAsk' => 0.45,
            'bestBid' => $bid,
            'question' => $question,
        ];
    }
}
