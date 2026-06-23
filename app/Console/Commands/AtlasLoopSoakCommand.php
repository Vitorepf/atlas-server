<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopSoakPlanService;
use App\Support\AtlasPhpBinary;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * THE 1-COMMAND SOAK LAUNCHER. The operator runs `atlas:loop:soak --hours=2 --budget-usd=5` to PREFLIGHT a
 * self-evolution soak: it computes the BRAKE (TTL / spend ceiling / grind cap), runs the ARM-CHECK (master on?
 * the Fibonacci seams on? the faxina work-types OFF? propose-only vs auto-merge?), and prints the exact launch
 * line. By DEFAULT it launches NOTHING (preflight only). The operator adds --confirm to dispatch — and even
 * then it refuses unless the arm-check is green. Nothing here ever runs a provider; --confirm delegates to
 * atlas:loop:campaign with the brake args.
 */
class AtlasLoopSoakCommand extends Command
{
    protected $signature = 'atlas:loop:soak
        {--hours=2 : Soak duration in hours (TTL brake; clamped to 24h)}
        {--budget-usd=5 : Provider spend ceiling in USD (0 = no cap)}
        {--grind-cap=0 : Max tasks processed (0 = unbounded)}
        {--with-self-merge : Plan an AUTO-MERGE soak (L7). Omit for the recommended risk-free PROPOSE-ONLY soak}
        {--confirm : Actually launch (default: preflight only — prints the plan, launches nothing)}
        {--foreground : Run the campaign INLINE (debug only; dies with this shell). Default DETACHES so the soak survives}
        {--json : Canonical JSON output}';

    protected $description = 'Preflight + 1-command launcher for a self-evolution soak (brake + arm-check + exact launch line). Launches nothing without --confirm.';

    public function handle(AtlasLoopSoakPlanService $planner): int
    {
        $plan = $planner->plan(
            (float) $this->option('hours'),
            (float) $this->option('budget-usd'),
            (int) $this->option('grind-cap'),
            (bool) $this->option('with-self-merge'),
        );
        $arm = $plan['arm_check'];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderHuman($plan, $arm);
        }

        if (! (bool) $this->option('confirm')) {
            if (! (bool) $this->option('json')) {
                $this->newLine();
                $this->components->info('Preflight only — nothing launched. Re-run with --confirm to start the soak.');
            }

            return self::SUCCESS;
        }

        // --confirm: refuse unless the arm-check is green (fail-closed; never launch a mis-armed soak).
        if (($arm['ready'] ?? false) !== true) {
            $this->components->error('Soak NOT launched — arm-check failed: '.implode('; ', (array) $arm['blocking']));

            return self::FAILURE;
        }

        // DEBUG inline path — tethered to THIS process (dies with the shell/session). NOT for a real soak.
        if ((bool) $this->option('foreground')) {
            $this->components->info('Arm-check green — launching INLINE (foreground; dies with this shell).');

            return $this->call('atlas:loop:campaign', $plan['launch_args']) === 0 ? self::SUCCESS : self::FAILURE;
        }

        // A real soak MUST outlive the launching shell/session — DETACH it (nohup), exactly like the keepalive
        // respawn. The old inline `$this->call(...)` tethered the multi-hour supervisor to the launching
        // process, so when that process was reaped the whole soak died mid-run and orphaned its in-flight
        // grinds (they surfaced as `parallel_worker_timeout`). Detach + log to a file; follow with soak-report.
        $log = $this->dispatchDetached((array) $plan['launch_args']);
        $this->components->info('Soak launched DETACHED — survives this shell. Log: '.$log);
        $this->components->info('Follow it hourly: php artisan atlas:loop:soak-report --hours=1');

        return self::SUCCESS;
    }

    /** Spawn the campaign as a detached nohup process so the soak outlives the launching shell. Returns the log path. */
    protected function dispatchDetached(array $launchArgs): string
    {
        $log = storage_path('logs/loop-soak-'.date('Ymd-His').'.log');
        (new Process(['bash', '-lc', $this->detachedCommandLine($launchArgs, $log)], base_path(), null, null, 30.0))->run();

        return $log;
    }

    /**
     * PURE builder for the detached launch command (testable without spawning): a nohup'd, memory-bounded,
     * backgrounded `atlas:loop:campaign` carrying the brake args, logging to $log.
     *
     * @param  array<string,mixed>  $launchArgs
     */
    public function detachedCommandLine(array $launchArgs, string $log): string
    {
        $args = [];
        foreach ($launchArgs as $key => $value) {
            if ($value === true) {
                $args[] = $key;
            } elseif ($value !== false && $value !== null && $value !== '') {
                $args[] = $key.'='.escapeshellarg((string) $value);
            }
        }

        return sprintf(
            'nohup %s -d memory_limit=4096M %s atlas:loop:campaign %s >> %s 2>&1 &',
            escapeshellarg(AtlasPhpBinary::path()),
            escapeshellarg(base_path('artisan')),
            implode(' ', $args),
            escapeshellarg($log),
        );
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $arm */
    private function renderHuman(array $plan, array $arm): void
    {
        $b = $plan['brake'];
        $this->components->info('Soak plan — '.($plan['requested_mode'] === 'auto_merge' ? 'AUTO-MERGE (L7)' : 'PROPOSE-ONLY (risk-free)'));
        $this->components->twoColumnDetail('<options=bold>Brake</> TTL', sprintf('%.1fh (%ds)', $b['max_seconds'] / 3600, $b['max_seconds']));
        $this->components->twoColumnDetail('  spend ceiling', $b['max_usd_cents'] > 0 ? '$'.number_format($b['max_usd_cents'] / 100, 2) : 'none');
        $this->components->twoColumnDetail('  grind cap (tasks)', $b['max_tasks'] > 0 ? (string) $b['max_tasks'] : 'unbounded');
        $this->components->twoColumnDetail('<options=bold>Scope</> discovery roots', implode(', ', (array) $plan['scope']['discovery_roots']));

        $this->components->twoColumnDetail('<options=bold>Arm-check</> master switch', ($arm['master_enabled'] ?? false) ? '<fg=green>ON</>' : '<fg=red>OFF (atlas:loop:on)</>');
        foreach ((array) $arm['fibonacci_flags'] as $flag => $on) {
            $this->components->twoColumnDetail('  '.$flag, $on ? '<fg=green>ON</>' : '<fg=red>OFF</>');
        }
        $this->components->twoColumnDetail('  faxina work-types OFF', ($arm['proxy_worktypes_off'] ?? false) ? '<fg=green>yes</>' : '<fg=red>NO — proxy magnet armed</>');
        $this->components->twoColumnDetail('  proxy supply lanes OFF', ($arm['proxy_supply_lanes_off'] ?? false) ? '<fg=green>yes</>' : '<fg=red>NO — cyclomatic farm: '.implode(', ', (array) ($arm['proxy_supply_armed'] ?? [])).'</>');
        $this->components->twoColumnDetail('  merge mode', $arm['merge_mode'] === 'auto_merge' ? '<fg=yellow>AUTO-MERGE</>' : 'propose-only');
        $this->components->twoColumnDetail('<options=bold>READY</>', ($arm['ready'] ?? false) ? '<fg=green;options=bold>YES</>' : '<fg=red;options=bold>NO: '.implode('; ', (array) $arm['blocking']).'</>');

        $this->newLine();
        $this->components->twoColumnDetail('Launch line', $plan['launch_command']);
        $this->components->twoColumnDetail('Watch in atlas:loop:soak-report', 'rung climbing ↑ · proxy% low · 0 regressions');
    }
}
