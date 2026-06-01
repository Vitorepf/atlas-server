<?php

namespace Tests\Unit\Ai\Aaeos;

use App\Services\Ai\Aaeos\AtlasCrossDepartmentChoreographyService;
use App\Services\Ai\Aaeos\AtlasRepairLoopGuard;
use App\Services\Ai\Aaeos\AtlasVetoPropagationWatchdog;
use Tests\TestCase;

class AtlasChoreographyWatchdogsTest extends TestCase
{
    public function test_veto_watchdog_pauses_then_lifts(): void
    {
        $watchdog = new AtlasVetoPropagationWatchdog(new AtlasCrossDepartmentChoreographyService);

        $paused = $watchdog->watch([['department' => 'security']]);
        $this->assertSame(['dev', 'forge', 'delivery'], $paused['paused_departments']);
        $this->assertFalse($paused['final_override_active']);

        $lifted = $watchdog->watch([
            ['department' => 'security'],
            ['department' => 'security', 'lift' => true],
        ]);
        $this->assertSame([], $lifted['paused_departments']);
    }

    public function test_veto_watchdog_flags_operator_final_override(): void
    {
        $watchdog = new AtlasVetoPropagationWatchdog(new AtlasCrossDepartmentChoreographyService);

        $result = $watchdog->watch([['department' => 'operator']]);

        $this->assertTrue($result['final_override_active']);
    }

    public function test_repair_guard_admits_up_to_three_then_escalates(): void
    {
        $guard = new AtlasRepairLoopGuard(new AtlasCrossDepartmentChoreographyService);

        $first = $guard->guard(0);
        $this->assertSame(1, $first['attempt']);
        $this->assertTrue($first['admitted']);
        $this->assertFalse($first['escalated']);

        $fourth = $guard->guard(3);
        $this->assertSame(4, $fourth['attempt']);
        $this->assertFalse($fourth['admitted']);
        $this->assertTrue($fourth['escalated']);
        $this->assertSame(['architect', 'operator'], $fourth['escalate_to']);
    }
}
