<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Finance\PolymarketExec;

use App\Services\Ai\Finance\PolymarketExec\PolyExecConfig;
use App\Services\Ai\Finance\PolymarketExec\PolyExecGate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesPolyExecTables;
use Tests\TestCase;

final class PolyExecGateTest extends TestCase
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
            liveEnabled: $o['liveEnabled'] ?? false,
            maxBasketUsd: $o['maxBasketUsd'] ?? 8.0,
            dailyCapUsd: $o['dailyCapUsd'] ?? 25.0,
            maxConcurrentBaskets: $o['maxConcurrentBaskets'] ?? 2,
            minDepthMultiple: $o['minDepthMultiple'] ?? 3.0,
            minPersistenceSeconds: $o['minPersistenceSeconds'] ?? 600,
            minNetEdgePerSet: $o['minNetEdgePerSet'] ?? 0.01,
            maxResolutionHours: $o['maxResolutionHours'] ?? 72.0,
            slippageBps: $o['slippageBps'] ?? 100,
            takerFeeRate: $o['takerFeeRate'] ?? 0.0,
            estGasUsdPerBasket: $o['estGasUsdPerBasket'] ?? 0.0,
            killSwitchPath: $o['killSwitchPath'] ?? sys_get_temp_dir().'/atlas-poly-kill-none-'.uniqid(),
        );
    }

    private function goodOpportunity(array $o = []): array
    {
        return array_merge([
            'net_edge_per_set' => 0.10,
            'executable_depth_shares' => 30.0,
            'target_sets' => 5.0,
            'target_cost_usd' => 4.50,
            'persistence_seconds' => 1200,
            'resolution_hours' => 24.0,
            'min_leg_price' => 0.20,
            'max_leg_price' => 0.40,
        ], $o);
    }

    public function test_runtime_caps_open_on_a_clean_slate(): void
    {
        $this->assertTrue($this->cfg()->killSwitchEngaged() === false);
        $gate = new PolyExecGate($this->cfg());
        $this->assertTrue($gate->checkRuntimeCaps('sim')->allowed);
    }

    public function test_kill_switch_blocks(): void
    {
        $kill = sys_get_temp_dir().'/atlas-poly-kill-'.uniqid();
        touch($kill);
        $gate = new PolyExecGate($this->cfg(['killSwitchPath' => $kill]));
        $decision = $gate->checkRuntimeCaps('sim');
        $this->assertFalse($decision->allowed);
        $this->assertContains('kill_switch', $decision->failedNames());
        @unlink($kill);
    }

    public function test_live_flag_off_blocks_live_but_not_sim(): void
    {
        $gate = new PolyExecGate($this->cfg(['liveEnabled' => false]));
        $this->assertFalse($gate->checkRuntimeCaps('live')->allowed);
        $this->assertContains('live_flag', $gate->checkRuntimeCaps('live')->failedNames());
        $this->assertTrue($gate->checkRuntimeCaps('sim')->allowed);
    }

    public function test_daily_halt_latch_and_budget_exhaustion_block(): void
    {
        DB::table('atlas_poly_exec_daily')->insert([
            'trade_date' => Carbon::now()->toDateString(), 'mode' => 'sim',
            'deployed_usd' => 25.0, 'realized_pnl_usd' => 0, 'baskets_attempted' => 1,
            'baskets_filled' => 1, 'baskets_aborted' => 0, 'halted' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $gate = new PolyExecGate($this->cfg(['dailyCapUsd' => 25.0]));
        $decision = $gate->checkRuntimeCaps('sim');
        $this->assertFalse($decision->allowed);
        $this->assertContains('daily_halt', $decision->failedNames());
        $this->assertContains('daily_budget', $decision->failedNames());
    }

    public function test_concurrency_cap_blocks_when_slots_full(): void
    {
        foreach (['a', 'b'] as $i) {
            DB::table('atlas_poly_exec_baskets')->insert($this->basketRow('basket-'.$i, 'filling'));
        }
        $gate = new PolyExecGate($this->cfg(['maxConcurrentBaskets' => 2]));
        $decision = $gate->checkRuntimeCaps('sim');
        $this->assertFalse($decision->allowed);
        $this->assertContains('concurrency', $decision->failedNames());
    }

    public function test_terminal_baskets_do_not_occupy_a_concurrency_slot(): void
    {
        DB::table('atlas_poly_exec_baskets')->insert($this->basketRow('done-1', 'filled'));
        DB::table('atlas_poly_exec_baskets')->insert($this->basketRow('done-2', 'unwound'));
        DB::table('atlas_poly_exec_baskets')->insert($this->basketRow('done-3', 'gated'));
        $gate = new PolyExecGate($this->cfg(['maxConcurrentBaskets' => 2]));
        $this->assertSame(0, $gate->activeBaskets('sim'));
        $this->assertTrue($gate->checkRuntimeCaps('sim')->allowed);
    }

    public function test_opportunity_quality_all_pass(): void
    {
        $gate = new PolyExecGate($this->cfg());
        $this->assertTrue($gate->checkOpportunity($this->goodOpportunity(), 'sim')->allowed);
    }

    public function test_each_quality_dimension_blocks_independently(): void
    {
        $gate = new PolyExecGate($this->cfg());

        $cases = [
            'net_edge' => ['net_edge_per_set' => 0.005],
            'depth_multiple' => ['executable_depth_shares' => 5.0], // need 5*3=15
            'persistence' => ['persistence_seconds' => 60],
            'resolution_horizon' => ['resolution_hours' => null],
            'basket_cap' => ['target_cost_usd' => 99.0],
            'sane_prices' => ['max_leg_price' => 1.01],
        ];
        foreach ($cases as $expectFail => $override) {
            $decision = $gate->checkOpportunity($this->goodOpportunity($override), 'sim');
            $this->assertFalse($decision->allowed, "$expectFail should block");
            $this->assertContains($expectFail, $decision->failedNames(), "expected $expectFail in failures");
        }
    }

    public function test_opportunity_blocked_when_cost_exceeds_remaining_daily_budget(): void
    {
        DB::table('atlas_poly_exec_daily')->insert([
            'trade_date' => Carbon::now()->toDateString(), 'mode' => 'sim',
            'deployed_usd' => 23.0, 'realized_pnl_usd' => 0, 'baskets_attempted' => 1,
            'baskets_filled' => 1, 'baskets_aborted' => 0, 'halted' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // remaining = 25 - 23 = 2; a $4.50 basket no longer fits.
        $gate = new PolyExecGate($this->cfg(['dailyCapUsd' => 25.0]));
        $decision = $gate->checkOpportunity($this->goodOpportunity(['target_cost_usd' => 4.50]), 'sim');
        $this->assertFalse($decision->allowed);
        $this->assertContains('fits_daily_budget', $decision->failedNames());
    }

    private function basketRow(string $id, string $status): array
    {
        return [
            'basket_id' => $id, 'session_id' => 's', 'mode' => 'sim',
            'event_slug' => 'evt', 'kind' => 'long_sum_under', 'execution_class' => 'simple_buy_all_legs',
            'status' => $status, 'n_legs' => 3, 'created_at' => now(), 'updated_at' => now(),
        ];
    }
}
