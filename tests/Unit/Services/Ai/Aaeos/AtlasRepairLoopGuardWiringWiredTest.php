<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Aaeos;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves AtlasRepairLoopGuard is wired into a real call path: the
 * atlas:aeos:department-status command now guards an optional repair-loop iteration. It is no
 * longer an orphan.
 */
final class AtlasRepairLoopGuardWiringWiredTest extends TestCase
{
    public function test_repair_iteration_option_surfaces_guard_decision(): void
    {
        Artisan::call('atlas:aeos:department-status', [
            '--repair-iteration' => '0',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('repair_loop_guard', $decoded);
        $this->assertSame(1, $decoded['repair_loop_guard']['attempt']);
        $this->assertArrayHasKey('admitted', $decoded['repair_loop_guard']);
        $this->assertArrayHasKey('escalated', $decoded['repair_loop_guard']);
    }

    public function test_fourth_iteration_escalates(): void
    {
        Artisan::call('atlas:aeos:department-status', [
            '--repair-iteration' => '3',
            '--json' => true,
        ]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertTrue($decoded['repair_loop_guard']['escalated']);
        $this->assertNotEmpty($decoded['repair_loop_guard']['escalate_to']);
    }

    public function test_no_repair_iteration_option_omits_guard_section(): void
    {
        Artisan::call('atlas:aeos:department-status', ['--json' => true]);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertIsArray($decoded);
        $this->assertArrayNotHasKey('repair_loop_guard', $decoded);
    }
}
