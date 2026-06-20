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

    /**
     * A spawner whose subprocess does REAL, deterministic CPU-bound work — a fixed-count
     * busy hash loop, the faithful proxy for what a grind worker actually burns cores on
     * (compile/verify/hash), NOT an idle usleep. CPU work genuinely contends for cores,
     * so a measured speedup only materializes if (a) the host has real parallel cores AND
     * (b) the pool truly runs the workers concurrently. The subprocess prints a JSON
     * checksum so the harvest can prove the work executed (not skipped/short-circuited).
     */
    private function cpuWorkSpawner(int $iterations): LoopWorkerSpawnerContract
    {
        return new class($iterations) implements LoopWorkerSpawnerContract
        {
            public function __construct(private readonly int $iterations) {}

            public function spawn(
                string $campaignId,
                string $taskId,
                string $workerId,
                int $leaseSeconds,
                string $workspaceRoot,
                int $timeoutSeconds,
                int $scenarios,
            ): LoopWorkerHandle {
                // Deterministic CPU burn: a fixed-count hash chain seeded ONLY by the
                // iteration count, so every worker does identical work and the checksum
                // is byte-stable across runs/hosts. No I/O, no sleep, no randomness.
                $script = 'declare(strict_types=1);'
                    .'$n=(int)$argv[1];'
                    .'$h="atlas-loop-cpu-proof";'
                    .'for($i=0;$i<$n;$i++){$h=hash("sha256",$h.$i);}'
                    .'echo json_encode(["checksum"=>$h,"iters"=>$n]);';
                $p = new Process([PHP_BINARY, '-r', $script, (string) $this->iterations]);
                $p->start();

                return new LoopWorkerHandle($p, $taskId, $workerId);
            }
        };
    }

    private function task(string $id): AtlasLoopTask
    {
        return (new AtlasLoopTask)->forceFill(['id' => $id, 'claimed_by' => 'w-'.$id]);
    }

    /** Best-effort logical-core probe; mirrors LoopWorkerCountPlanner's detector. */
    private function detectCores(): int
    {
        $raw = @shell_exec(PHP_OS_FAMILY === 'Darwin' ? 'sysctl -n hw.ncpu 2>/dev/null' : 'nproc 2>/dev/null');

        return max(1, (int) trim((string) $raw) ?: 1);
    }

    /**
     * Calibrate the per-worker iteration count so ONE worker takes ~$targetMs of real CPU
     * time on THIS host. Keeps the proof hermetic (fixed wall-clock budget) regardless of
     * how fast the box is, while the work itself stays deterministic for a given count.
     */
    private function calibrateIterations(int $targetMs): int
    {
        $sample = 4000;
        $start = microtime(true);
        $h = 'atlas-loop-cpu-proof';
        for ($i = 0; $i < $sample; $i++) {
            $h = hash('sha256', $h.$i);
        }
        $elapsedMs = (microtime(true) - $start) * 1000;
        $perIterMs = $elapsedMs / $sample;

        if ($perIterMs <= 0.0) {
            return $sample * 8; // pathologically fast clock — fall back to a fixed floor
        }

        return max($sample, (int) round($targetMs / $perIterMs));
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

    public function test_tick_harvests_worker_timeout_without_waiting_for_process_exit(): void
    {
        $spawner = new class implements LoopWorkerSpawnerContract
        {
            public function spawn(
                string $campaignId,
                string $taskId,
                string $workerId,
                int $leaseSeconds,
                string $workspaceRoot,
                int $timeoutSeconds,
                int $scenarios,
            ): LoopWorkerHandle {
                $process = new Process([PHP_BINARY, '-r', 'usleep(500000);'], null, null, null, 0.05);
                $process->start();

                return new LoopWorkerHandle($process, $taskId, $workerId);
            }
        };
        $pool = new LoopWorkerPool($spawner, new AtlasLoopResourceGate);
        $queue = [$this->task('timeout-task')];
        $claimNext = function () use (&$queue): ?AtlasLoopTask { return array_shift($queue); };

        $first = $pool->tick(1, 'c', $claimNext, 600, 60);
        $this->assertSame(1, $first['spawned']);
        $this->assertSame(1, $first['in_flight']);

        usleep(120000);
        $second = $pool->tick(1, 'c', $claimNext, 600, 60);

        $this->assertSame(0, $second['in_flight']);
        $this->assertCount(1, $second['settled']);
        $this->assertTrue((bool) $second['settled'][0]['timed_out']);
        $this->assertSame('timeout-task', $second['settled'][0]['task_id']);
    }

    public function test_timeout_kills_worker_process_group_children(): void
    {
        if (! function_exists('posix_setsid') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('POSIX process groups are unavailable on this runtime.');
        }

        $childPidFile = tempnam(sys_get_temp_dir(), 'atlas-loop-child-');
        $this->assertIsString($childPidFile);

        $script = <<<'PHP'
if (function_exists('posix_setsid')) {
    @posix_setsid();
}
$child = trim((string) shell_exec('sleep 30 >/dev/null 2>&1 & echo $!'));
file_put_contents($argv[1], $child);
sleep(30);
PHP;
        $process = new Process([PHP_BINARY, '-r', $script, $childPidFile], null, null, null, 0.05);
        $process->start();
        $handle = new LoopWorkerHandle($process, 'timeout-tree-task', 'timeout-tree-worker');

        $deadline = microtime(true) + 2.0;
        do {
            $childPid = trim((string) @file_get_contents($childPidFile));
            if ($childPid !== '') {
                break;
            }
            usleep(20_000);
        } while (microtime(true) < $deadline);

        $this->assertNotSame('', $childPid, 'child process pid was not recorded');
        $this->assertTrue($this->processExists((int) $childPid), 'child process should be alive before timeout harvest');

        usleep(120_000);
        $this->assertTrue($handle->isFinished());
        $this->assertTrue($handle->timedOut());

        $deadline = microtime(true) + 2.0;
        do {
            if (! $this->processExists((int) $childPid)) {
                @unlink($childPidFile);
                $this->assertTrue(true);

                return;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        @unlink($childPidFile);
        $this->fail('timed-out worker left its child process alive');
    }

    public function test_worker_count_planner_clamps_to_cpu_and_ceiling(): void
    {
        Config::set('atlas.loop.parallel.max_workers', 4);
        $planner = new LoopWorkerCountPlanner(fn (): int => 8); // pretend 8 cores

        $this->assertSame(4, $planner->plan(100)); // ceiling 4
        $this->assertSame(2, $planner->plan(2));   // honor a smaller request
        $this->assertSame(1, $planner->plan(0));   // never below 1
    }

    /**
     * L5-8 DoD — "cenários/hora >= 2x o serial". The REAL throughput proof (replaces the
     * former usleep tautology, which proved only that idle waits overlap and would have
     * passed even on a single core).
     *
     * Method: run the SAME deterministic CPU-bound workload (a fixed-count hash chain)
     * (1) serially — one worker at a time through the pool with max 1 slot — and then
     * (2) in parallel through the REAL LoopWorkerPool + spawner with 4 slots; measure BOTH
     * wall-clocks (no computed baseline) and assert the parallel run finishes in < half
     * the measured serial baseline. Because the work genuinely competes for cores, the
     * speedup is only achievable when the pool truly runs workers concurrently on real
     * parallel hardware — a property the idle-sleep variant could never test.
     *
     * Hermetic + deterministic: iteration count is calibrated to a fixed ~per-worker CPU
     * budget on this host, the workload is seeded only by that count (byte-stable
     * checksum), and the test verifies every worker emitted the EXPECTED checksum so a
     * skipped/short-circuited "fast" run can't fake the speedup. Skips cleanly on hosts
     * without enough cores so it never flakes on a constrained box.
     */
    public function test_four_worker_pool_beats_serial_with_real_cpu_work(): void
    {
        $cores = $this->detectCores();
        if ($cores < 5) {
            $this->markTestSkipped(
                'Real-parallelism proof needs >=5 logical cores (4 workers + supervisor headroom); host reports '.$cores.'.'
            );
        }

        // ~120ms of real CPU per worker — long enough to dominate process-spawn jitter,
        // short enough to keep the whole proof well under a second.
        $iterations = $this->calibrateIterations(120);
        $workerCount = 4;

        // The expected checksum of the deterministic workload (the anti-skip lock).
        $expectedChecksum = 'atlas-loop-cpu-proof';
        for ($i = 0; $i < $iterations; $i++) {
            $expectedChecksum = hash('sha256', $expectedChecksum.$i);
        }

        $drain = function (LoopWorkerPool $pool, int $slots, array $tasks): array {
            $queue = $tasks;
            $claimNext = function () use (&$queue): ?AtlasLoopTask { return array_shift($queue); };
            $settled = [];
            $started = microtime(true);
            $guard = 0;
            do {
                $r = $pool->tick($slots, 'c', $claimNext, 600, 60);
                foreach ($r['settled'] as $s) {
                    $settled[] = $s;
                }
                usleep(2000); // 2ms poll — tiny vs the ~120ms/worker CPU burn
                $this->assertLessThan(2000, ++$guard, 'pool drain failed to make progress');
            } while ($queue !== [] || $pool->inFlight() > 0);

            return ['duration_ms' => (int) round((microtime(true) - $started) * 1000), 'settled' => $settled];
        };

        $tasksFor = fn (): array => array_map(fn (int $i): AtlasLoopTask => $this->task('t'.$i), range(1, $workerCount));

        // (1) Serial baseline: one slot, so the workers run strictly one-at-a-time.
        $serialPool = new LoopWorkerPool($this->cpuWorkSpawner($iterations), new AtlasLoopResourceGate);
        $serial = $drain($serialPool, 1, $tasksFor());

        // (2) Parallel: four slots through the SAME real pool + spawner.
        $parallelPool = new LoopWorkerPool($this->cpuWorkSpawner($iterations), new AtlasLoopResourceGate);
        $parallel = $drain($parallelPool, $workerCount, $tasksFor());

        // Anti-skip lock: EVERY worker (both runs) must have emitted the EXPECTED checksum,
        // proving the real CPU work executed and was not short-circuited.
        $this->assertCount($workerCount, $serial['settled']);
        $this->assertCount($workerCount, $parallel['settled']);
        foreach (array_merge($serial['settled'], $parallel['settled']) as $s) {
            $this->assertSame(0, $s['exit_code'], 'a worker exited non-zero — the CPU workload crashed');
            $this->assertIsArray($s['result'] ?? null, 'a worker produced no parseable checksum result');
            $this->assertSame($iterations, $s['result']['iters'] ?? null);
            $this->assertSame($expectedChecksum, $s['result']['checksum'] ?? null, 'worker checksum mismatch — work was not done deterministically');
        }

        // The DoD: parallel throughput >= 2x serial, i.e. parallel wall-clock < half serial.
        $this->assertGreaterThan(0, $serial['duration_ms']);
        $this->assertLessThan(
            $serial['duration_ms'] / 2,
            $parallel['duration_ms'],
            sprintf(
                '4-worker pool must finish four equal CPU-bound waits in < half the MEASURED serial baseline '
                .'(serial=%dms, parallel=%dms, speedup=%.2fx, iters/worker=%d, cores=%d).',
                $serial['duration_ms'],
                $parallel['duration_ms'],
                $serial['duration_ms'] / max(1, $parallel['duration_ms']),
                $iterations,
                $cores,
            )
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

    private function processExists(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        $probe = new Process(['ps', '-p', (string) $pid, '-o', 'pid=']);
        $probe->run();

        return trim($probe->getOutput()) !== '';
    }
}
