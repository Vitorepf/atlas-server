<?php

use App\Console\Commands\AiBootstrapSkillsCommand;
use App\Console\Commands\AiChatCommand;
use App\Console\Commands\AiDoctorCommand;
use App\Console\Commands\AiEnqueueCommand;
use App\Console\Commands\AiHealthCommand;
use App\Console\Commands\AiMetricSnapshotRefreshCommand;
use App\Console\Commands\AiPerformanceSmokeCommand;
use App\Console\Commands\AiRecommendationMeasureCommand;
use App\Console\Commands\AiReportEngineBackfillCommand;
use App\Console\Commands\AiReportEngineRunCommand;
use App\Console\Commands\AiTelemetryCostRatesCommand;
use App\Console\Commands\AiTelemetryHealthCommand;
use App\Console\Commands\AiTelemetryPerformanceReportCommand;
use App\Console\Commands\AiTelemetryRollupCommand;
use App\Console\Commands\AiWorkCommand;
use App\Console\Commands\AtlasAiDecideCommand;
use App\Console\Commands\AtlasCliBootstrapCommand;
use App\Console\Commands\AtlasCliCheckpointCommand;
use App\Console\Commands\AtlasCliCompareCommand;
use App\Console\Commands\AtlasCliDashboardCommand;
use App\Console\Commands\AtlasCliDevCommand;
use App\Console\Commands\AtlasCliDoctorCommand;
use App\Console\Commands\AtlasCliDogfoodCommand;
use App\Console\Commands\AtlasCliFinalCommand;
use App\Console\Commands\AtlasCliFixCommand;
use App\Console\Commands\AtlasCliHelpCommand;
use App\Console\Commands\AtlasCliInboxCommand;
use App\Console\Commands\AtlasCliInstallCommand;
use App\Console\Commands\AtlasCliMemoryCommand;
use App\Console\Commands\AtlasCliMobileCommand;
use App\Console\Commands\AtlasCliPermissionsCommand;
use App\Console\Commands\AtlasCliProvidersCommand;
use App\Console\Commands\AtlasCliQualityCommand;
use App\Console\Commands\AtlasCliReleaseCommand;
use App\Console\Commands\AtlasCliRollbackCommand;
use App\Console\Commands\AtlasCliScheduleCommand;
use App\Console\Commands\AtlasCliSetupCommand;
use App\Console\Commands\AtlasCliSkillsCommand;
use App\Console\Commands\AtlasCliStateCommand;
use App\Console\Commands\AtlasCliTraceCommand;
use App\Console\Commands\AtlasCliTuiCommand;
use App\Console\Commands\AtlasCliUpdateCommand;
use App\Console\Commands\AtlasCliVersionCommand;
use App\Console\Commands\AtlasEngineeringApiContractCommand;
use App\Console\Commands\AtlasEngineeringBenchmarkCalibrateCommand;
use App\Console\Commands\AtlasEngineeringBenchmarkCommand;
use App\Console\Commands\AtlasEngineeringBenchmarkSeedCommand;
use App\Console\Commands\AtlasEngineeringDockerCleanupCommand;
use App\Console\Commands\AtlasEngineeringHarnessabilityCalibrateCommand;
use App\Console\Commands\AtlasEngineeringKnowledgeCommand;
use App\Console\Commands\AtlasEngineeringQualityScanCommand;
use App\Console\Commands\AtlasEngineeringReplayCommand;
use App\Console\Commands\AtlasEngineeringRunCommand;
use App\Console\Commands\AtlasEngineeringSbomCommand;
use App\Console\Commands\AtlasEngineeringSecurityScanCommand;
use App\Console\Commands\AtlasEngineeringVisualBaselineCommand;
use App\Console\Commands\AtlasEngineeringVisualDriverCommand;
use App\Console\Commands\AtlasEngineeringVisualSmokeCommand;
use App\Console\Commands\AtlasInitiativesCommand;
use App\Console\Commands\AtlasInsightCommand;
use App\Console\Commands\AtlasInsightWatchCommand;
use App\Console\Commands\AtlasMemoryReviewQueueCommand;
use App\Console\Commands\AtlasMemoryRecallCommand;
use App\Console\Commands\AtlasMemorySeedCoreCommand;
use App\Console\Commands\AtlasOpenBrainContextCommand;
use App\Console\Commands\AtlasProposalCommand;
use App\Console\Commands\AtlasProposalScanCommand;
use App\Console\Commands\AtlasRuntimeCommand;
use App\Console\Commands\AtlasSchedulerTickCommand;
use App\Console\Commands\AtlasSelfDiagnosticCommand;
use App\Console\Commands\AtlasToolsCommand;
use App\Console\Commands\HealthRepairCommand;
use App\Console\Commands\RizeInspectCommand;
use App\Console\Commands\RizeSyncCommand;
use App\Console\Commands\SemanticActivateCommand;
use App\Console\Commands\SemanticBootstrapVaultCommand;
use App\Console\Commands\SemanticGovernCommand;
use App\Console\Commands\SemanticIndexCommand;
use App\Console\Commands\SemanticProposeCommand;
use App\Http\Middleware\AuthenticateAtlasToken;
use App\Http\Middleware\AuthenticateMobileDevice;
use App\Jobs\FlushBatchedMobilePushes;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: '',
    )
    ->withCommands([
        AiBootstrapSkillsCommand::class,
        AiChatCommand::class,
        AiDoctorCommand::class,
        AiEnqueueCommand::class,
        AiHealthCommand::class,
        AiMetricSnapshotRefreshCommand::class,
        AiPerformanceSmokeCommand::class,
        AiRecommendationMeasureCommand::class,
        AiReportEngineBackfillCommand::class,
        AiReportEngineRunCommand::class,
        AiTelemetryCostRatesCommand::class,
        AiTelemetryHealthCommand::class,
        AiTelemetryPerformanceReportCommand::class,
        AiTelemetryRollupCommand::class,
        AiWorkCommand::class,
        AtlasAiDecideCommand::class,
        AtlasCliBootstrapCommand::class,
        AtlasCliCheckpointCommand::class,
        AtlasCliCompareCommand::class,
        AtlasCliDashboardCommand::class,
        AtlasCliDevCommand::class,
        AtlasCliDogfoodCommand::class,
        AtlasCliDoctorCommand::class,
        AtlasCliFinalCommand::class,
        AtlasCliFixCommand::class,
        AtlasCliHelpCommand::class,
        AtlasCliInstallCommand::class,
        AtlasCliInboxCommand::class,
        AtlasInsightCommand::class,
        AtlasInsightWatchCommand::class,
        AtlasInitiativesCommand::class,
        AtlasCliMemoryCommand::class,
        AtlasCliMobileCommand::class,
        AtlasCliPermissionsCommand::class,
        AtlasCliQualityCommand::class,
        AtlasCliReleaseCommand::class,
        AtlasCliProvidersCommand::class,
        AtlasProposalCommand::class,
        AtlasProposalScanCommand::class,
        AtlasMemoryRecallCommand::class,
        AtlasMemoryReviewQueueCommand::class,
        AtlasMemorySeedCoreCommand::class,
        AtlasOpenBrainContextCommand::class,
        AtlasCliRollbackCommand::class,
        AtlasCliScheduleCommand::class,
        AtlasCliSetupCommand::class,
        AtlasCliSkillsCommand::class,
        AtlasCliStateCommand::class,
        AtlasCliTraceCommand::class,
        AtlasCliTuiCommand::class,
        AtlasCliUpdateCommand::class,
        AtlasCliVersionCommand::class,
        AtlasEngineeringBenchmarkCalibrateCommand::class,
        AtlasEngineeringBenchmarkCommand::class,
        AtlasEngineeringBenchmarkSeedCommand::class,
        AtlasEngineeringApiContractCommand::class,
        AtlasEngineeringDockerCleanupCommand::class,
        AtlasEngineeringHarnessabilityCalibrateCommand::class,
        AtlasEngineeringKnowledgeCommand::class,
        AtlasEngineeringQualityScanCommand::class,
        AtlasEngineeringReplayCommand::class,
        AtlasEngineeringRunCommand::class,
        AtlasEngineeringSbomCommand::class,
        AtlasEngineeringSecurityScanCommand::class,
        AtlasEngineeringVisualDriverCommand::class,
        AtlasEngineeringVisualBaselineCommand::class,
        AtlasEngineeringVisualSmokeCommand::class,
        AtlasSchedulerTickCommand::class,
        AtlasSelfDiagnosticCommand::class,
        AtlasToolsCommand::class,
        AtlasRuntimeCommand::class,
        HealthRepairCommand::class,
        RizeInspectCommand::class,
        RizeSyncCommand::class,
        SemanticActivateCommand::class,
        SemanticBootstrapVaultCommand::class,
        SemanticGovernCommand::class,
        SemanticIndexCommand::class,
        SemanticProposeCommand::class,
    ])
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

        $schedule->command('atlas:ai:telemetry:rollup --hours=48')
            ->hourly()
            ->withoutOverlapping();

        $schedule->command('atlas:ai:telemetry:health --hours=48 --emit')
            ->hourly()
            ->withoutOverlapping();

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
        $middleware->alias([
            'atlas.token' => AuthenticateAtlasToken::class,
            'atlas.mobile.bearer' => AuthenticateMobileDevice::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn () => true);
    })->create();
