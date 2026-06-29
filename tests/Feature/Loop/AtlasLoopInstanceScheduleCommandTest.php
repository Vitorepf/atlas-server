<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\MultiProject\AtlasProjectLaneRuntimeInstanceScheduler;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the project-lane runtime instance scheduler is live at the operator surface: clean instances within
 * the parallelism budget all tick now (every instance referenced); a tighter budget blocks the overflow; an
 * instance missing isolation evidence is held.
 */
final class AtlasLoopInstanceScheduleCommandTest extends TestCase
{
    private function inst(string $laneId, string $projectId, array $overrides = []): array
    {
        return array_merge([
            'lane_id' => $laneId,
            'project_id' => $projectId,
            'queue_namespace' => 'ns-'.$laneId,
            'allowed_roots' => ['/repo/'.$projectId],
            'isolation_evidence_refs' => ['ref-'.$laneId],
        ], $overrides);
    }

    private function plan(array $instances, array $facts): array
    {
        $exit = Artisan::call('atlas:loop:instance-schedule', [
            '--instances' => (string) json_encode($instances),
            '--facts' => (string) json_encode($facts),
            '--json' => true,
        ]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_clean_instances_within_budget_all_tick(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->plan(
            [$this->inst('l1', 'p1'), $this->inst('l2', 'p2')],
            ['max_parallel_lanes' => 2],
        );

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasProjectLaneRuntimeInstanceScheduler::SCHEMA, $d['schema_version']);
        $this->assertCount(2, $d['tick_now'], (string) json_encode($d));
        $tickLanes = array_column($d['tick_now'], 'lane_id');
        $this->assertContains('l1', $tickLanes);
        $this->assertContains('l2', $tickLanes);
    }

    public function test_budget_overflow_is_blocked(): void
    {
        ['d' => $d] = $this->plan(
            [$this->inst('l1', 'p1'), $this->inst('l2', 'p2')],
            ['max_parallel_lanes' => 1],
        );

        $this->assertCount(1, $d['tick_now']);
        $this->assertCount(1, $d['blocked_lanes']);
        $this->assertContains(
            AtlasProjectLaneRuntimeInstanceScheduler::HOLD_MAX_PARALLEL_REACHED,
            $d['blocked_lanes'][0]['reasons'],
        );
    }

    public function test_missing_isolation_evidence_is_held(): void
    {
        ['d' => $d] = $this->plan(
            [$this->inst('l1', 'p1', ['isolation_evidence_refs' => []])],
            ['max_parallel_lanes' => 2],
        );

        $this->assertCount(0, $d['tick_now']);
        $this->assertCount(1, $d['held_lanes']);
        $this->assertContains(
            AtlasProjectLaneRuntimeInstanceScheduler::HOLD_MISSING_ISOLATION_EVIDENCE,
            $d['held_lanes'][0]['reasons'],
        );
    }

    public function test_missing_instances_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:instance-schedule', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
