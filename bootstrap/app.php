<?php

use App\Console\Commands\AtlasAiLocalRagBenchmarkCommand;
use App\Http\Middleware\AuthenticateAtlasToken;
use App\Http\Middleware\AuthenticateMobileDevice;
use App\Jobs\FlushBatchedMobilePushes;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementScheduleService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: '',
        then: function (): void {
            // Mobile clients call /api/* but the canonical api routes are served at the
            // ROOT (apiPrefix: '' above) — and the container healthcheck hits /health at
            // the root, so we must NOT relocate them (flipping apiPrefix would 404 the
            // healthcheck). Instead ALSO expose every api route under /api (same
            // controllers + 'api' middleware), with an 'api.' route-NAME prefix so the
            // named routes don't collide with their root twins. Root stays byte-identical
            // (healthcheck + existing clients keep working); /api/* now resolves too.
            Route::middleware('api')
                ->prefix('api')
                ->name('api.')
                ->group(__DIR__.'/../routes/api.php');
        },
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('atlas:semantic:index --changed')
            ->everyFiveMinutes()
            ->withoutOverlapping();

        $schedule->command('atlas:semantic:propose')
            ->hourly()
            ->withoutOverlapping();

        $schedule->command('atlas:semantic:activate --context=morning_briefing')
            ->dailyAt('06:00')
            ->withoutOverlapping();

        $schedule->command('atlas:semantic:govern')
            ->dailyAt('03:20')
            ->withoutOverlapping();

        $schedule->command('atlas:ai:health')
            ->hourly()
            ->withoutOverlapping();

        if (config('atlas_ai.ledger_projection.enabled', true)) {
            $projectionHours = max(1, min(8760, (int) config('atlas_ai.ledger_projection.hours', 24)));
            $projectionLimit = max(1, min(5000, (int) config('atlas_ai.ledger_projection.limit', 500)));

            $schedule->command("atlas:ai:ledger-project --hours={$projectionHours} --limit={$projectionLimit} --json")
                ->everyTenMinutes()
                ->withoutOverlapping();
        }

        // atlas:ai:autonomous-holding was scheduled daily here, defaulting to ON.
        // The command is quarantined — it lives at archive/app/Console/Commands/
        // AtlasAiAutonomousHoldingCommand.php — so the entry pointed at nothing and
        // would fail every day at 05:40 the moment the scheduler comes back up.
        // Reviving from archive/ is an AAEOS §0.4 hard ban, so the schedule goes
        // instead. AutonomousHoldingEnterpriseBuildoutService is untouched and still
        // reachable from its own callers; only the dead CLI schedule is removed.

        $schedule->command('atlas:ai:telemetry:rollup --hours=48')
            ->hourly()
            ->withoutOverlapping();

        $schedule->command('atlas:ai:telemetry:health --hours=48 --emit')
            ->hourly()
            ->withoutOverlapping();

        // The Sunday memory digest — every Sunday, report everything Atlas saved to
        // memory that week + every auto-applied learning, for after-the-fact pruning.
        if (config('atlas.ai.weekly_memory_digest.enabled', true)) {
            $schedule->command('atlas:ai:weekly-memory-digest --days=7 --json')
                ->weeklyOn(0, (string) config('atlas.ai.weekly_memory_digest.time', '18:00')) // 0 = Sunday
                ->timezone((string) config('app.timezone', 'UTC'))
                ->withoutOverlapping();
            $schedule->command('atlas:memory:growth-report --days=7 --json')
                ->weeklyOn(0, (string) config('atlas.ai.weekly_memory_digest.time', '18:00'))
                ->timezone((string) config('app.timezone', 'UTC'))
                ->withoutOverlapping();
        }

        // "Hermes mode" — daily autonomous apply of the SAFE reversible learnings, no
        // per-item approval. DEFAULT OFF; the consumer is fail-closed so even when on it
        // only applies non-critical/non-sensitive/reversible classes, queueing the rest.
        if (config('atlas.ai.autonomous_learning.enabled', false)) {
            $schedule->command('atlas:ai:auto-apply-safe --limit='.(int) config('atlas.ai.autonomous_learning.limit', 50).' --json')
                ->dailyAt((string) config('atlas.ai.autonomous_learning.time', '04:10'))
                ->timezone((string) config('app.timezone', 'UTC'))
                ->withoutOverlapping();
        }

        if (config('atlas.ai_metrics.snapshot_refresh_enabled', true)) {
            $schedule->command('atlas:ai:metrics:snapshot-refresh --days=2 --json')
                ->dailyAt((string) config('atlas.ai_metrics.snapshot_refresh_time', '06:50'))
                ->timezone((string) config('atlas.ai_metrics.performance_report_timezone', config('app.timezone', 'UTC')))
                ->withoutOverlapping();
        }

        if (config('atlas.report.recommendation_measure_enabled', true)) {
            $schedule->command('atlas:ai:recommendations:measure --json')
                ->dailyAt((string) config('atlas.report.recommendation_measure_time', '06:40'))
                ->timezone((string) config('atlas.ai_metrics.performance_report_timezone', config('app.timezone', 'UTC')))
                ->withoutOverlapping();
        }

        if (config('atlas.ai_metrics.performance_report_enabled', true)) {
            $reportWindows = implode(',', (array) config('atlas.ai_metrics.performance_report_windows', [3, 7, 15, 30]));
            $reportCommand = "atlas:ai:telemetry:performance-report --type=auto --windows={$reportWindows} --recompute --json";
            if (config('atlas.ai_metrics.performance_report_emit', true)) {
                $reportCommand .= ' --emit';
            }

            $schedule->command($reportCommand)
                ->dailyAt((string) config('atlas.ai_metrics.performance_report_time', '07:05'))
                ->timezone((string) config('atlas.ai_metrics.performance_report_timezone', config('app.timezone', 'UTC')))
                ->withoutOverlapping();
        }

        $schedule->command('atlas:health:repair --days=60')
            ->dailyAt('04:20')
            ->withoutOverlapping();

        if (config('atlas.ai.schedule_worker')) {
            $schedule->command('atlas:ai:work --once --limit=3')
                ->everyMinute()
                ->withoutOverlapping();
        }

        // Patamar 4 · Scheduler OS heartbeat — proves cron is alive 24/7.
        // Append-only JSONL probe; /atlas/patamar4/state.scheduler shows silent_alarm.
        if (config('atlas.patamar4.scheduler_heartbeat_enabled', true)) {
            $schedule->command('atlas:scheduler:heartbeat')
                ->everyMinute()
                ->withoutOverlapping();
        }

        // Patamar 4 · A3 · launchd self-healing — once per day verify the
        // launchd agent is still loaded; reinstall when missing. No-op on
        // non-Darwin platforms.
        if (config('atlas.patamar4.scheduler_ensure_launchd_enabled', true)) {
            $schedule->command('atlas:scheduler:ensure-launchd --json')
                ->dailyAt('04:05')
                ->withoutOverlapping();
        }

        // Patamar 4 — Autonomous Reconciliation Tick.
        // Each tick reads CognitiveFunctionAtlas → admits via Constitutional Kernel
        // → emits AURG-4D temporal tick → fires ASCB.propose() when allow_autonomous.
        // Tick is harmless when registry has no gaps (outcome=noop_no_gap).
        // Defaults: every 15 min. Disable via config('atlas.patamar4.reconciliation_enabled', true).
        if (config('atlas.patamar4.reconciliation_enabled', true)) {
            $cadence = (string) config('atlas.patamar4.reconciliation_cadence', 'fifteen');
            // Auto-tune: Kernel runtime invariant may override config when set.
            try {
                $tuned = app(AtlasConstitutionalKernelService::class)
                    ->currentRuntimeValue('reconciliation_cadence_window');
                if (is_string($tuned) && $tuned !== '') {
                    $cadence = $tuned;
                }
            } catch (Throwable $e) {
                // Defensive: scheduler bootstrap stays robust against container issues.
            }
            $cmd = $schedule->command('atlas:reconciliation --action=tick --privacy=normal --autonomy=execute_with_approval --json')
                ->withoutOverlapping();
            match ($cadence) {
                'minute' => $cmd->everyMinute(),
                'five' => $cmd->everyFiveMinutes(),
                'ten' => $cmd->everyTenMinutes(),
                'thirty' => $cmd->everyThirtyMinutes(),
                'hourly' => $cmd->hourly(),
                default => $cmd->everyFifteenMinutes(),
            };
        }

        // Patamar 4 · Nightly counterfactuals — background TEOS-I4 reprojection
        // sobre decisões majores do dia anterior. 03:00 UTC (madrugada operador).
        if (config('atlas.patamar4.nightly_counterfactuals_enabled', true)) {
            $schedule->command('atlas:nightly:counterfactuals --action=run --actor=cron_nightly --json')
                ->withoutOverlapping()
                ->dailyAt('03:00');
        }

        // Patamar 4 · ADML closed feedback loop hourly sweep.
        // Auto-deactivates active routes whose live success rate falls below
        // the degradation threshold. Cheap, idempotent, append-only receipt.
        if (config('atlas.patamar4.adml_sweep_enabled', true)) {
            $schedule->command('atlas:atlas-decide:live-feedback --action=sweep --actor=autonomous_feedback_loop --json')
                ->withoutOverlapping()
                ->hourly();
        }

        if (config('atlas_ai.self_improvement.enabled', false)) {
            foreach (app(AtlasSelfImprovementScheduleService::class)->scheduledCommands() as $selfImprovementCommand) {
                $scheduledEvent = $schedule->command($selfImprovementCommand['command']);

                if (($selfImprovementCommand['cadence'] ?? 'daily') === 'weekly') {
                    $scheduledEvent->weeklyOn((int) ($selfImprovementCommand['week_day'] ?? 1), $selfImprovementCommand['time']);
                } else {
                    $scheduledEvent->dailyAt($selfImprovementCommand['time']);
                }

                $scheduledEvent
                    ->timezone($selfImprovementCommand['timezone'])
                    ->withoutOverlapping();
            }
        }

        $localRagBenchmarkSchedule = AtlasAiLocalRagBenchmarkCommand::schedulePlan();
        if (($localRagBenchmarkSchedule['schedulable'] ?? false) === true) {
            $schedule->command((string) $localRagBenchmarkSchedule['command'])
                ->dailyAt((string) $localRagBenchmarkSchedule['time'])
                ->timezone((string) $localRagBenchmarkSchedule['timezone'])
                ->withoutOverlapping();
        }

        $schedule->command('atlas:worked-example extract scheduled --json')
            ->weeklyOn(1, '04:10')
            ->timezone((string) config('app.timezone', 'UTC'))
            ->withoutOverlapping();

        $schedule->command('atlas:scheduler:tick')
            ->everyMinute()
            ->withoutOverlapping();

        if (config('atlas.mobile.enabled') && config('atlas.mobile.batching.enabled')) {
            $schedule->job(new FlushBatchedMobilePushes)
                ->everyFifteenMinutes()
                ->withoutOverlapping();
        }

        if (config('atlas.mobile.enabled') && config('atlas.mobile.push_receipts.enabled')) {
            $schedule->command('atlas:cli:mobile receipts')
                ->everyFifteenMinutes()
                ->withoutOverlapping();
        }

        if (config('atlas.mobile.enabled') && config('atlas.mobile.maintenance.expire_stale_enabled', true)) {
            $schedule->command('atlas:cli:mobile expire-stale --apply')
                ->everyFiveMinutes()
                ->withoutOverlapping();
        }

        if (config('atlas.mobile.enabled') && config('atlas.mobile.maintenance.cleanup_enabled', true)) {
            $schedule->command('atlas:cli:mobile cleanup --apply')
                ->dailyAt((string) config('atlas.mobile.maintenance.cleanup_time', '03:30'))
                ->withoutOverlapping();
        }

        if (config('atlas.mobile.enabled') && config('atlas.mobile.alerts.enabled', true) && (config('atlas.mobile.alerts.webhook_url') || config('atlas.mobile.alerts.local_log_enabled', true))) {
            $schedule->command('atlas:cli:mobile alert-check --apply --json')
                ->everyFiveMinutes()
                ->withoutOverlapping();
        }

        if (config('atlas.mobile.enabled') && config('atlas.mobile.self_diagnostic.enabled')) {
            $schedule->command('atlas:self-diagnostic')
                ->dailyAt((string) config('atlas.mobile.self_diagnostic.time', '06:15'))
                ->withoutOverlapping();
        }

        if (config('atlas.mobile.enabled') && config('atlas.mobile.proposal_scan.enabled')) {
            $proposalWorkspace = (string) config('atlas.mobile.proposal_scan.workspace', dirname(base_path()));
            $proposalLimit = max(1, (int) config('atlas.mobile.proposal_scan.limit', 3));

            $schedule->command('atlas:proposal:scan --emit', [
                '--workspace' => $proposalWorkspace,
                '--limit' => $proposalLimit,
            ])
                ->dailyAt((string) config('atlas.mobile.proposal_scan.time', '06:30'))
                ->withoutOverlapping();
        }

        if (config('atlas.mobile.enabled') && config('atlas.mobile.insight_watch.enabled')) {
            $schedule->command('atlas:insight:watch')
                ->dailyAt((string) config('atlas.mobile.insight_watch.time', '06:45'))
                ->withoutOverlapping();
        }

        if (config('services.rize.sync_enabled') && config('services.rize.api_key')) {
            $lookbackDays = (int) config('services.rize.sync_lookback_days', 2);
            $schedule->command("atlas:rize:sync --days={$lookbackDays}")
                ->everyFifteenMinutes()
                ->withoutOverlapping();

            $schedule->command('atlas:rize:sync --days=30')
                ->dailyAt('03:10')
                ->withoutOverlapping();
        }
    })
    ->withMiddleware(function (Middleware $middleware): void {
        // Atlas Code MVP: enable CORS for atlas-desktop dev (Vite :5173) +
        // Tauri shell. Origins are governed by config/cors.php.
        $middleware->prepend(HandleCors::class);

        $middleware->alias([
            'atlas.token' => AuthenticateAtlasToken::class,
            'atlas.mobile.bearer' => AuthenticateMobileDevice::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn () => true);
    })->create();
