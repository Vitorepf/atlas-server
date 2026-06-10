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

    public function test_command_monitor_sim_runs_bounded_cycle_without_candidates(): void
    {
        $exit = Artisan::call('atlas:finance:poly-exec', [
            'action' => 'monitor',
            '--mode' => 'sim',
            '--cycles' => 1,
            '--interval' => 0,
            '--max-candidates' => 2,
            '--slow-cycle-seconds' => 1,
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
        $this->assertStringContainsString('"slow_cycles": 0', $out);
    }

    public function test_command_monitor_live_is_refused(): void
    {
        $this->artisan('atlas:finance:poly-exec', ['action' => 'monitor', '--mode' => 'live'])
            ->expectsOutputToContain('MONITOR refused: monitor is sim-only')
            ->assertExitCode(1);
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
}
