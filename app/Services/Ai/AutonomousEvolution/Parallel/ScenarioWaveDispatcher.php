<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Parallel;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionScenarioExplorer;
use App\Services\Ai\AutonomousEvolution\AtlasLoopResourceGate;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Bounded PARALLEL scenario dispatcher — the scenario-level fan-out engine.
 *
 * It reuses the PROVEN {@see LoopWorkerPool}::tick PATTERN (Symfony Process::start +
 * non-blocking poll/harvest), NOT the worker-pool classes themselves: those are
 * task-level (they spawn `atlas:loop:grind-task`), and the explorer's in-process
 * driver fakes cannot cross a subprocess boundary. This dispatcher spawns one child
 * `atlas:loop:run-scenario` process per spec in a bounded wave so the ~14min/attempt
 * provider latency overlaps in-flight together.
 *
 * BACKPRESSURE, NEVER DROP: each spawn is gated by {@see AtlasLoopResourceGate}::
 * admitScenario (each child creates its own /tmp scenario workspace, so a wave of
 * width children = width live workspaces). When the gate refuses admission the spec
 * runs INLINE-serial in this process instead — a wave must never explore fewer
 * scenarios than the serial path would for the same min/max/patience.
 *
 * DETERMINISTIC FOLD: results are ksort'd by spec index before return, so the
 * explorer folds them in ascending scn order exactly as the serial loop does.
 *
 * PROVIDER-SAFE: only the attempt JSON (scenario_id/verdict/diff_size/diff_text/
 * provider/strategy_key/...) crosses the process boundary — the shape runScenario
 * returns. The concrete is `final`; the explorer + container bind against the
 * {@see ScenarioWaveDispatcherContract} so tests can inject a fake.
 */
final class ScenarioWaveDispatcher implements ScenarioWaveDispatcherContract
{
    public function __construct(private readonly AtlasLoopResourceGate $resourceGate) {}

    /**
     * Run a bounded wave of scenario specs in-flight together.
     *
     * @param  list<array{index:int,objective:string,strategy_text:string,strategy_key:string,base_workspace:string,acceptance:array<string,mixed>,surface_id:string,user_constraints:list<string>,surface_hints:array<string,mixed>,provider:string,keep_workspaces:bool,workspace_root:string,clone_mode:string}>  $specs
     * @return list<array<string,mixed>>
     */
    public function dispatch(array $specs): array
    {
        $timeout = max(30.0, (float) config('atlas.loop.scenario_fanout.timeout_seconds', 600));

        /** @var array<int,Process> $running index => child process */
        $running = [];
        /** @var array<int,array<string,mixed>> $results index => attempt array */
        $results = [];

        // 1. SPAWN the bounded wave. Each spawn is admitted by the resource gate; on a
        //    non-admit the spec runs inline-serial here (backpressure, never dropped).
        $maxLive = (int) config('atlas.loop.campaign.max_live_workspaces', 0);
        foreach ($specs as $spec) {
            $index = (int) $spec['index'];
            // ACDE T1 — WITHIN-WAVE workspace-cap accounting. The resource gate globs /tmp for live
            // workspaces, but children spawned earlier in THIS wave (async start) have not created theirs
            // yet, so the glob lags by the in-flight count and the cap cannot throttle within a wave.
            // Account for the in-flight wave children explicitly. max_live_workspaces=0 (the default) =>
            // unlimited => byte-identical to before (cap 0 handed to the gate, no inline throttle).
            $wave = self::withinWaveAdmission($maxLive, count($running));
            if ($wave['run_inline']) {
                $results[$index] = $this->runInline($spec); // this wave alone hit the cap -> backpressure inline

                continue;
            }
            $admit = $this->resourceGate->admitScenario(
                sys_get_temp_dir(),
                (int) config('atlas.loop.campaign.min_free_mb', 512),
                $wave['effective_cap'],
            );
            if (! $admit['admit']) {
                // INLINE fallback for this single spec — never reduce scenarios explored below serial.
                $results[$index] = $this->runInline($spec);

                continue;
            }

            $process = $this->spawnChild($spec, $timeout);
            $process->start();
            $running[$index] = $process;
        }

        // 2. HARVEST: non-blocking poll until the whole wave settles.
        while ($running !== []) {
            foreach ($running as $index => $process) {
                if ($process->isRunning()) {
                    continue;
                }
                $results[$index] = $this->harvestChild($process, $specs[$this->specOffsetForIndex($specs, $index)]);
                unset($running[$index]);
            }
            if ($running !== []) {
                usleep(50_000); // 50ms poll — tiny vs the provider latency each child hides
            }
        }

        // 3. DETERMINISTIC FOLD: ascending index so scn-ids/winner-selection match the serial contract.
        ksort($results);

        return array_values($results);
    }

    /**
     * ACDE T1 — pure within-wave workspace-cap accounting. The resource gate's /tmp glob lags the children
     * already started in THIS wave by $inFlight, so the cap cannot throttle within a wave on its own. This
     * decides, per spawn: run inline (this wave alone has reached the cap), else the cap to hand the gate,
     * shrunk by the in-flight count. maxLive<=0 means "unlimited" (the config default) => no within-wave
     * throttle and cap 0 (byte-identical to before this lever).
     *
     * @return array{run_inline:bool, effective_cap:int}
     */
    public static function withinWaveAdmission(int $maxLive, int $inFlight): array
    {
        if ($maxLive <= 0) {
            return ['run_inline' => false, 'effective_cap' => 0]; // 0 = unlimited (gate's own behaviour)
        }
        if ($inFlight >= $maxLive) {
            return ['run_inline' => true, 'effective_cap' => 0]; // this wave alone hit the cap
        }

        return ['run_inline' => false, 'effective_cap' => $maxLive - max(0, $inFlight)];
    }

    /**
     * Build the per-scenario child process. base64-encode the spec so the JSON survives
     * the shell intact; base_path() cwd so the child boots the same app + container bindings.
     *
     * @param  array<string,mixed>  $spec
     */
    private function spawnChild(array $spec, float $timeout): Process
    {
        $argv = [
            PHP_BINARY, 'artisan', 'atlas:loop:run-scenario',
            '--spec='.base64_encode((string) json_encode($spec)),
            '--json',
        ];

        return new Process($argv, base_path(), null, null, $timeout);
    }

    /**
     * Parse a finished child's JSON attempt. A non-zero exit or unparseable output yields the
     * errored-attempt shell — the same shape runScenario returns from its catch block.
     *
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    private function harvestChild(Process $process, array $spec): array
    {
        if ($process->getExitCode() === 0) {
            $decoded = $this->decodeAttempt($process->getOutput());
            if ($decoded !== null) {
                return $decoded;
            }
        }

        return $this->erroredAttempt($spec, trim($process->getErrorOutput()) ?: 'wave_child_failed');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeAttempt(string $output): ?array
    {
        $output = trim($output);
        if ($output === '') {
            return null;
        }
        $decoded = json_decode($output, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // Tolerate leading/trailing process chatter by extracting the JSON object span.
        $start = strpos($output, '{');
        $end = strrpos($output, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $decoded = json_decode(substr($output, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * INLINE-serial fallback: run the spec in THIS process via the same tested code path the
     * child would (runScenarioForWave -> the unchanged private runScenario). Resolved lazily
     * from the container to avoid a dispatcher<->explorer construction cycle.
     *
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    private function runInline(array $spec): array
    {
        try {
            /** @var AtlasEvolutionScenarioExplorer $explorer */
            $explorer = app(AtlasEvolutionScenarioExplorer::class);

            return $explorer->runScenarioForWave($spec);
        } catch (Throwable $e) {
            return $this->erroredAttempt($spec, mb_substr($e->getMessage(), 0, 300));
        }
    }

    /**
     * The errored-attempt shell — mirrors AtlasEvolutionScenarioExplorer::runScenario's catch shape
     * so a failed child never breaks the explorer's fold (improvesBest reads verdict.passed).
     *
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    private function erroredAttempt(array $spec, string $error): array
    {
        $index = (int) ($spec['index'] ?? 0);

        return [
            'scenario_id' => 'scn-'.($index + 1),
            'strategy_key' => (string) ($spec['strategy_key'] ?? ''),
            'strategy' => (string) ($spec['strategy_text'] ?? ''),
            'provider' => (string) ($spec['provider'] ?? ''),
            'loop_status' => 'errored',
            'cost_estimate_usd' => null,
            'tokens_used' => null,
            'verdict' => ['passed' => false, 'metric' => 0.0, 'details' => ['reason' => 'wave_child_failed']],
            'diff_size' => ['files' => 0, 'lines' => 0],
            'diff_text' => '',
            'workspace' => null,
            'error' => mb_substr($error, 0, 300),
        ];
    }

    /**
     * Locate the offset of the spec carrying $index (specs are index-keyed by value, not array key).
     *
     * @param  list<array<string,mixed>>  $specs
     */
    private function specOffsetForIndex(array $specs, int $index): int
    {
        foreach ($specs as $offset => $spec) {
            if ((int) ($spec['index'] ?? -1) === $index) {
                return $offset;
            }
        }

        return 0;
    }
}
