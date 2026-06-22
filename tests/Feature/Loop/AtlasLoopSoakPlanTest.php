<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopSoakPlanService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * THE SOAK LAUNCHER preflight — proven without launching anything. The brake math is exact; the arm-check is
 * fail-closed (master off / any Fibonacci seam off / a faxina work-type armed => NOT ready); requesting
 * self-merge without the flag blocks; the launch line carries the brake. The command preflights and launches
 * nothing without --confirm.
 */
final class AtlasLoopSoakPlanTest extends TestCase
{
    private function armAllFibonacci(): void
    {
        config([
            'atlas.loop.origination_on_starvation_enabled' => true,
            'atlas.loop.capability_trend_enabled' => true,
            'atlas.loop.capability_ambition_enabled' => true,
            'atlas.loop.compounding_frontier_enabled' => true,
            'atlas.loop.regression_sentinel_enabled' => true,
            'atlas.loop.territory_widened_roots_drive_refill' => true,
            'atlas.loop.deterministic_deadcode_supply_enabled' => false,
            'atlas.loop.unused_import_supply_enabled' => false,
            'atlas.loop.self_improvement_auto_merge_enabled' => false,
            'atlas.loop.obra_auto_merge_enabled' => false,
        ]);
    }

    private function planner(bool $masterOn): AtlasLoopSoakPlanService
    {
        return new AtlasLoopSoakPlanService(fn (): bool => $masterOn);
    }

    public function test_brake_math_is_exact_and_clamped(): void
    {
        $plan = $this->planner(true)->plan(2.0, 5.0, 10, false);
        $this->assertSame(7200, $plan['brake']['max_seconds'], '2h => 7200s');
        $this->assertSame(500, $plan['brake']['max_usd_cents'], '$5 => 500 cents');
        $this->assertSame(10, $plan['brake']['max_tasks']);

        $clamped = $this->planner(true)->plan(99.0, 0.0, 0, false);
        $this->assertSame(86400, $clamped['brake']['max_seconds'], 'TTL clamps to 24h');
        $this->assertSame(0, $clamped['brake']['max_usd_cents'], '$0 => no cap');
    }

    public function test_fully_armed_propose_only_is_ready(): void
    {
        $this->armAllFibonacci();
        $plan = $this->planner(true)->plan(2.0, 5.0, 0, false);

        $this->assertTrue($plan['arm_check']['ready'], 'master on + all seams on + proxy off + propose-only => ready; blocking='.json_encode($plan['arm_check']['blocking']));
        $this->assertTrue($plan['arm_check']['fibonacci_all_on']);
        $this->assertTrue($plan['arm_check']['proxy_worktypes_off']);
        $this->assertSame('propose_only', $plan['arm_check']['merge_mode']);
        $this->assertSame([], $plan['arm_check']['blocking']);
    }

    public function test_master_off_or_seam_off_is_not_ready(): void
    {
        $this->armAllFibonacci();
        $masterOff = $this->planner(false)->plan(2.0, 5.0, 0, false);
        $this->assertFalse($masterOff['arm_check']['ready']);
        $this->assertContains('master_switch_off (run: php artisan atlas:loop:on)', $masterOff['arm_check']['blocking']);

        $this->armAllFibonacci();
        config(['atlas.loop.capability_ambition_enabled' => false]); // one seam off
        $seamOff = $this->planner(true)->plan(2.0, 5.0, 0, false);
        $this->assertFalse($seamOff['arm_check']['ready']);
        $this->assertContains('fibonacci_flag_off:capability_ambition_enabled', $seamOff['arm_check']['blocking']);
    }

    public function test_armed_faxina_worktype_blocks_the_soak(): void
    {
        $this->armAllFibonacci();
        config(['atlas.loop.deterministic_deadcode_supply_enabled' => true]); // the proxy magnet
        $plan = $this->planner(true)->plan(2.0, 5.0, 0, false);

        $this->assertFalse($plan['arm_check']['proxy_worktypes_off']);
        $this->assertFalse($plan['arm_check']['ready']);
        $this->assertContains('proxy_worktype_armed:deterministic_deadcode_supply_enabled (must be OFF — it is the faxina magnet)', $plan['arm_check']['blocking']);
    }

    public function test_self_merge_requested_without_flag_blocks_and_auto_merge_mode_detected(): void
    {
        $this->armAllFibonacci();
        // asked for --with-self-merge but the flag is OFF
        $plan = $this->planner(true)->plan(2.0, 5.0, 0, true);
        $this->assertFalse($plan['arm_check']['ready']);
        $this->assertContains('self_merge_requested_but_flag_off (set ATLAS_LOOP_SELF_IMPROVEMENT_AUTO_MERGE_ENABLED=true)', $plan['arm_check']['blocking']);

        // flag ON => auto_merge mode, and a self-merge soak is ready
        config(['atlas.loop.self_improvement_auto_merge_enabled' => true]);
        $armed = $this->planner(true)->plan(2.0, 5.0, 0, true);
        $this->assertSame('auto_merge', $armed['arm_check']['merge_mode']);
        $this->assertTrue($armed['arm_check']['self_merge_armed']);
        $this->assertTrue($armed['arm_check']['ready']);
    }

    public function test_launch_line_carries_the_brake(): void
    {
        $plan = $this->planner(true)->plan(2.0, 5.0, 12, false);
        $this->assertStringContainsString('--max-seconds=7200', $plan['launch_command']);
        $this->assertStringContainsString('--max-usd-cents=500', $plan['launch_command']);
        $this->assertStringContainsString('--max-tasks=12', $plan['launch_command']);
        $this->assertStringContainsString('--no-shadow', $plan['launch_command']);
        $this->assertSame(7200, $plan['launch_args']['--max-seconds']);
    }

    public function test_command_preflights_and_launches_nothing_without_confirm(): void
    {
        $code = Artisan::call('atlas:loop:soak', ['--json' => true, '--hours' => 1]);
        $this->assertSame(0, $code);
        $out = Artisan::output();
        $this->assertStringContainsString('atlas.loop.soak_plan.v1', $out);
        $this->assertStringContainsString('arm_check', $out);
        $this->assertStringContainsString('"max_seconds": 3600', $out, 'the preflight reports the brake without launching');
    }
}
