<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopTask;
use App\Services\Ai\AutonomousEvolution\AtlasLoopResourceGate;
use App\Services\Ai\AutonomousEvolution\Parallel\LoopWorkerCountPlanner;
use App\Services\Ai\AutonomousEvolution\Parallel\LoopWorkerHandle;
use App\Services\Ai\AutonomousEvolution\Parallel\LoopWorkerPool;
use App\Services\Ai\AutonomousEvolution\Parallel\LoopWorkerSpawnerContract;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * The bounded worker pool in isolation (no DB) — proves it never exceeds the slot
 * ceiling, harvests finished workers, and backpressures on the disk floor instead of
 * crashing. The spawner is faked with real but trivial subprocesses.
 */
final class AtlasLoopWorkerPoolTest extends TestCase
{
    private function fakeSpawner(int $sleepMicros = 150000): LoopWorkerSpawnerContract
    {
        return new class($sleepMicros) implements LoopWorkerSpawnerContract
        {
            public function __construct(private readonly int $sleepMicros) {}

            public function spawn(
                string $campaignId,
                string $taskId,
                string $workerId,
                int $leaseSeconds,
                string $workspaceRoot,
                int $timeoutSeconds,
                int $scenarios,
            ): LoopWorkerHandle {
                $p = new Process([PHP_BINARY, '-r', 'usleep('.$this->sleepMicros.');']);
                $p->start();

                return new LoopWorkerHandle($p, $taskId, $workerId);
            }
        };
    }

    private function task(string $id): AtlasLoopTask
    {
        return (new AtlasLoopTask)->forceFill(['id' => $id, 'claimed_by' => 'w-'.$id]);
    }

    public function test_tick_bounds_slots_and_harvests_finished_workers(): void
    {
        $pool = new LoopWorkerPool($this->fakeSpawner(), new AtlasLoopResourceGate);
        $queue = [$this->task('t1'), $this->task('t2'), $this->task('t3'), $this->task('t4'), $this->task('t5')];
        $claimNext = function () use (&$queue): ?AtlasLoopTask { return array_shift($queue); };

        // First tick: fill 2 of 2 slots, no more.
        $r1 = $pool->tick(2, 'c', $claimNext, 600, 60);
        $this->assertSame(2, $r1['spawned']);
        $this->assertSame(2, $r1['in_flight']);

        // Immediate second tick: still full, nothing harvested yet, nothing new spawned.
        $r2 = $pool->tick(2, 'c', $claimNext, 600, 60);
        $this->assertSame(0, $r2['spawned']);
        $this->assertLessThanOrEqual(2, $r2['in_flight']);

        // After the workers finish, a tick harvests them and refills the slots.
        usleep(350000);
        $r3 = $pool->tick(2, 'c', $claimNext, 600, 60);
        $this->assertGreaterThanOrEqual(1, count($r3['settled']));
        $this->assertLessThanOrEqual(2, $r3['in_flight']);

        // Drain the rest deterministically.
        $guard = 0;
        while (! empty($queue) || $pool->inFlight() > 0) {
            usleep(200000);
            $pool->tick(2, 'c', $claimNext, 600, 60);
            if (++$guard > 50) {
                break;
            }
        }
        $this->assertSame(0, $pool->inFlight());
    }

    public function test_tick_backpressures_below_disk_floor(): void
    {
        Config::set('atlas.loop.campaign.min_free_mb', 1_000_000_000); // 1 PB floor — unmeetable
        $pool = new LoopWorkerPool($this->fakeSpawner(), new AtlasLoopResourceGate);
        $claimNext = fn (): ?AtlasLoopTask => $this->task('t1');

        $r = $pool->tick(4, 'c', $claimNext, 600, 60);

        $this->assertTrue($r['backpressured']);
        $this->assertSame(0, $r['spawned']);
        $this->assertSame(0, $r['in_flight']);
    }

    public function test_worker_count_planner_clamps_to_cpu_and_ceiling(): void
    {
        Config::set('atlas.loop.parallel.max_workers', 4);
        $planner = new LoopWorkerCountPlanner(fn (): int => 8); // pretend 8 cores

        $this->assertSame(4, $planner->plan(100)); // ceiling 4
        $this->assertSame(2, $planner->plan(2));   // honor a smaller request
        $this->assertSame(1, $planner->plan(0));   // never below 1
    }

    public function test_four_worker_pool_beats_serial_sleep_baseline(): void
    {
        $sleepMicros = 250000;
        $pool = new LoopWorkerPool($this->fakeSpawner($sleepMicros), new AtlasLoopResourceGate);
        $queue = [$this->task('t1'), $this->task('t2'), $this->task('t3'), $this->task('t4')];
        $claimNext = function () use (&$queue): ?AtlasLoopTask { return array_shift($queue); };

        $started = microtime(true);
        $guard = 0;
        do {
            $pool->tick(4, 'c', $claimNext, 600, 60);
            usleep(50000);
            $this->assertLessThan(50, ++$guard);
        } while ($queue !== [] || $pool->inFlight() > 0);
        $durationMs = (int) round((microtime(true) - $started) * 1000);
        $serialBaselineMs = (int) round((count(['t1', 't2', 't3', 't4']) * $sleepMicros) / 1000);

        $this->assertLessThan(
            (int) floor($serialBaselineMs / 2),
            $durationMs,
            '4-worker pool should finish four equal waits in less than half the serial baseline.'
        );
    }

    /**
     * O-10 safety default: the parallel frota SHIPS gated OFF — the config fallback is
     * false, so enabling the fleet is a deliberate operator act (env), never the
     * out-of-the-box behavior. Asserted against the SHIPPED default in config source
     * (não o env vivo: o operador ligou a frota em 12/06, legitimamente).
     */
    public function test_parallel_fleet_ships_gated_off_by_default(): void
    {
        $this->assertStringContainsString(
            "env('ATLAS_LOOP_PARALLEL_ENABLED', false)",
            (string) file_get_contents(config_path('atlas.php')),
            'o fallback de fábrica da frota deve ser false (ligar = ato do operador via env)'
        );
    }
}
