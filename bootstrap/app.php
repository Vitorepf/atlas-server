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
use App\Console\Commands\AtlasAiArchitectureValidateCommand;
use App\Console\Commands\AtlasAiAutomationDomainCommand;
use App\Console\Commands\AtlasAiAutonomousHoldingCommand;
use App\Console\Commands\AtlasAiCyberDomainCommand;
use App\Console\Commands\AtlasAiDecideCommand;
use App\Console\Commands\AtlasAiDomainsCommand;
use App\Console\Commands\AtlasAiDynamicComputeMarketCommand;
use App\Console\Commands\AtlasAiEngineeringCompanyCommand;
use App\Console\Commands\AtlasAiFinanceDomainCommand;
use App\Console\Commands\AtlasAiHyperflowCommand;
use App\Console\Commands\AtlasAiLedgerCommand;
use App\Console\Commands\AtlasAiLedgerProjectionCommand;
use App\Console\Commands\AtlasAiLocalRagBenchmarkCommand;
use App\Console\Commands\AtlasAiLocalRagReadinessCommand;
use App\Console\Commands\AtlasAiMarketingDomainCommand;
use App\Console\Commands\AtlasAiOperationsDomainCommand;
use App\Console\Commands\AtlasAiPersonalDevelopmentDomainCommand;
use App\Console\Commands\AtlasAiProgrammingRuntimeControlPlaneCommand;
use App\Console\Commands\AtlasAiProviderPerformanceCommand;
use App\Console\Commands\AtlasAiProviderReleaseSourcesCommand;
use App\Console\Commands\AtlasAiQualitativeLevelsCommand;
use App\Console\Commands\AtlasAiResearchDomainCommand;
use App\Console\Commands\AtlasAiRivalsStrategyCommand;
use App\Console\Commands\AtlasAiRuntimeBoundaryCommand;
use App\Console\Commands\AtlasAiSelfImproveCommand;
use App\Console\Commands\AtlasAiStrategicDecisionCommand;
use App\Console\Commands\AtlasAiMemoryForgetCommand;
use App\Console\Commands\AtlasAiStrategyDomainCommand;
use App\Console\Commands\AtlasAiWeeklyMemoryDigestCommand;
use App\Console\Commands\AtlasApplyLearningCommand;
use App\Console\Commands\AtlasBridgeEvidenceCommand;
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
use App\Console\Commands\AtlasCognitiveFunctionDecomposeCommand;
use App\Console\Commands\AtlasCostCalibrateCommand;
use App\Console\Commands\AtlasDevDesktopAcceptanceCommand;
use App\Console\Commands\AtlasDevDesktopEfficiencyEvidenceCommand;
use App\Console\Commands\AtlasDevDesktopEnableCommand;
use App\Console\Commands\AtlasDevDesktopGoalAuditCommand;
use App\Console\Commands\AtlasDevDesktopRealSmokeCommand;
use App\Console\Commands\AtlasDevMinimaxWorkerRunCommand;
use App\Console\Commands\AtlasDevSeniorLoopAuditCommand;
use App\Console\Commands\AtlasDevSeniorLoopRunCommand;
use App\Console\Commands\AtlasDevSmokeCommand;
use App\Console\Commands\AtlasEngineeringApiContractCommand;
use App\Console\Commands\AtlasEngineeringBenchmarkCalibrateCommand;
use App\Console\Commands\AtlasEngineeringBenchmarkCommand;
use App\Console\Commands\AtlasEngineeringBenchmarkFairCommand;
use App\Console\Commands\AtlasEngineeringBenchmarkReplayManifestCommand;
use App\Console\Commands\AtlasEngineeringBenchmarkReportCommand;
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
use App\Console\Commands\AtlasForgeRivalsCommand;
use App\Console\Commands\AtlasForgeRuntimeCertifyCommand;
use App\Console\Commands\AtlasInitiativesCommand;
use App\Console\Commands\AtlasInsightCommand;
use App\Console\Commands\AtlasInsightWatchCommand;
use App\Console\Commands\AtlasLoopMaterializeCommand;
use App\Console\Commands\AtlasLoopPromoteCommand;
use App\Console\Commands\AtlasMemoryMaintenanceCommand;
use App\Console\Commands\AtlasMemoryQualityCommand;
use App\Console\Commands\AtlasMemoryRecallCommand;
use App\Console\Commands\AtlasMemoryReviewQueueCommand;
use App\Console\Commands\AtlasMemorySeedCoreCommand;
use App\Console\Commands\AtlasMineHeldEvidenceCommand;
use App\Console\Commands\AtlasOpenBrainContextCommand;
use App\Console\Commands\AtlasOpenBrainMcpCommand;
use App\Console\Commands\AtlasPatamar4ActivateFlagsCommand;
use App\Console\Commands\AtlasPatamar4SelfConstructF4GapsCommand;
use App\Console\Commands\AtlasProductiveFailureCommand;
use App\Console\Commands\AtlasProgrammingCompletionAuditCommand;
use App\Console\Commands\AtlasProgrammingPatchVerifierBenchmarkCommand;
use App\Console\Commands\AtlasProgrammingRepairLoopBenchmarkCommand;
use App\Console\Commands\AtlasProgrammingResumeCommand;
use App\Console\Commands\AtlasProgrammingRetrievalBenchmarkCommand;
use App\Console\Commands\AtlasProgrammingRivalsEvidencePackCommand;
use App\Console\Commands\AtlasProgrammingRivalsForgeDryRunCommand;
use App\Console\Commands\AtlasProgrammingRivalsForgePreflightCommand;
use App\Console\Commands\AtlasProgrammingRivalsOneShotEvaluateCommand;
use App\Console\Commands\AtlasProgrammingRivalsReadinessCommand;
use App\Console\Commands\AtlasProgrammingTestImpactBenchmarkCommand;
use App\Console\Commands\AtlasProposalCommand;
use App\Console\Commands\AtlasProposalScanCommand;
use App\Console\Commands\AtlasRivalsCommand;
use App\Console\Commands\AtlasRivalsHarnessCommand;
use App\Console\Commands\AtlasRuntimeCommand;
use App\Console\Commands\AtlasSchedulerEnsureLaunchdCommand;
use App\Console\Commands\AtlasSchedulerHeartbeatCommand;
use App\Console\Commands\AtlasSchedulerInstallLaunchdCommand;
use App\Console\Commands\AtlasSchedulerStatusCommand;
use App\Console\Commands\AtlasSchedulerTickCommand;
use App\Console\Commands\AtlasSelfDiagnosticCommand;
use App\Console\Commands\AtlasSoftwareCompanyFirstLiveBranchProofCommand;
use App\Console\Commands\AtlasSoftwareCompanyIntegrationLaneCommand;
use App\Console\Commands\AtlasSoftwareCompanyLiveCycleAuditCommand;
use App\Console\Commands\AtlasSoftwareCompanyPriorityEngineCommand;
use App\Console\Commands\AtlasSwarmExecuteArmCommand;
use App\Console\Commands\AtlasToolsCommand;
use App\Console\Commands\AtlasTrustLadderCommand;
use App\Console\Commands\AtlasVaultCommand;
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
        AtlasAiArchitectureValidateCommand::class,
        AtlasAiAutomationDomainCommand::class,
        AtlasAiAutonomousHoldingCommand::class,
        AtlasAiCyberDomainCommand::class,
        AtlasAiDecideCommand::class,
        AtlasAiDomainsCommand::class,
        AtlasAiDynamicComputeMarketCommand::class,
        AtlasAiEngineeringCompanyCommand::class,
        AtlasAiFinanceDomainCommand::class,
        AtlasAiHyperflowCommand::class,
        AtlasAiLedgerCommand::class,
        AtlasAiLedgerProjectionCommand::class,
        AtlasAiLocalRagBenchmarkCommand::class,
        AtlasAiLocalRagReadinessCommand::class,
        AtlasAiMarketingDomainCommand::class,
        AtlasAiOperationsDomainCommand::class,
        AtlasAiPersonalDevelopmentDomainCommand::class,
        AtlasAiProgrammingRuntimeControlPlaneCommand::class,
        AtlasAiProviderPerformanceCommand::class,
        AtlasAiProviderReleaseSourcesCommand::class,
        AtlasAiQualitativeLevelsCommand::class,
        AtlasAiResearchDomainCommand::class,
        AtlasAiRivalsStrategyCommand::class,
        AtlasAiRuntimeBoundaryCommand::class,
        AtlasAiSelfImproveCommand::class,
        AtlasAiStrategicDecisionCommand::class,
        AtlasAiMemoryForgetCommand::class,
        AtlasAiStrategyDomainCommand::class,
        AtlasAiWeeklyMemoryDigestCommand::class,
        AtlasApplyLearningCommand::class,
        AtlasBridgeEvidenceCommand::class,
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
        AtlasProgrammingCompletionAuditCommand::class,
        AtlasProgrammingPatchVerifierBenchmarkCommand::class,
        AtlasProgrammingRepairLoopBenchmarkCommand::class,
        AtlasProgrammingRetrievalBenchmarkCommand::class,
        AtlasProgrammingRivalsEvidencePackCommand::class,
        AtlasProgrammingRivalsForgeDryRunCommand::class,
        AtlasProgrammingRivalsForgePreflightCommand::class,
        AtlasProgrammingRivalsOneShotEvaluateCommand::class,
        AtlasProgrammingRivalsReadinessCommand::class,
        AtlasProgrammingResumeCommand::class,
        AtlasProgrammingTestImpactBenchmarkCommand::class,
        AtlasProductiveFailureCommand::class,
        AtlasLoopMaterializeCommand::class,
        AtlasLoopPromoteCommand::class,
        AtlasMemoryMaintenanceCommand::class,
        AtlasMemoryQualityCommand::class,
        AtlasMemoryRecallCommand::class,
        AtlasMemoryReviewQueueCommand::class,
        AtlasMemorySeedCoreCommand::class,
        AtlasMineHeldEvidenceCommand::class,
        AtlasOpenBrainContextCommand::class,
        AtlasOpenBrainMcpCommand::class,
        AtlasCliRollbackCommand::class,
        AtlasCliScheduleCommand::class,
        AtlasCliSetupCommand::class,
        AtlasCliSkillsCommand::class,
        AtlasCliStateCommand::class,
        AtlasCliTraceCommand::class,
        AtlasCliTuiCommand::class,
        AtlasCliUpdateCommand::class,
        AtlasCliVersionCommand::class,
        AtlasDevDesktopAcceptanceCommand::class,
        AtlasDevDesktopEfficiencyEvidenceCommand::class,
        AtlasDevDesktopGoalAuditCommand::class,
        AtlasDevDesktopEnableCommand::class,
        AtlasDevDesktopRealSmokeCommand::class,
        AtlasDevMinimaxWorkerRunCommand::class,
        AtlasDevSeniorLoopAuditCommand::class,
        AtlasDevSeniorLoopRunCommand::class,
        AtlasDevSmokeCommand::class,
        AtlasEngineeringBenchmarkCalibrateCommand::class,
        AtlasEngineeringBenchmarkCommand::class,
        AtlasEngineeringBenchmarkFairCommand::class,
        AtlasEngineeringBenchmarkReplayManifestCommand::class,
        AtlasEngineeringBenchmarkReportCommand::class,
        AtlasEngineeringBenchmarkSeedCommand::class,
        AtlasForgeRivalsCommand::class,
        AtlasForgeRuntimeCertifyCommand::class,
        AtlasRivalsCommand::class,
        AtlasRivalsHarnessCommand::class,
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
        AtlasCognitiveFunctionDecomposeCommand::class,
        AtlasCostCalibrateCommand::class,
        AtlasPatamar4ActivateFlagsCommand::class,
        AtlasPatamar4SelfConstructF4GapsCommand::class,
        AtlasSchedulerEnsureLaunchdCommand::class,
        AtlasSwarmExecuteArmCommand::class,
        AtlasSchedulerHeartbeatCommand::class,
        AtlasSchedulerInstallLaunchdCommand::class,
        AtlasSchedulerStatusCommand::class,
        AtlasSchedulerTickCommand::class,
        AtlasSelfDiagnosticCommand::class,
        AtlasSoftwareCompanyFirstLiveBranchProofCommand::class,
        AtlasSoftwareCompanyIntegrationLaneCommand::class,
        AtlasSoftwareCompanyLiveCycleAuditCommand::class,
        AtlasSoftwareCompanyPriorityEngineCommand::class,
        AtlasToolsCommand::class,
        AtlasTrustLadderCommand::class,
        AtlasVaultCommand::class,
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

        if (config('atlas_ai.ledger_projection.enabled', true)) {
            $projectionHours = max(1, min(8760, (int) config('atlas_ai.ledger_projection.hours', 24)));
            $projectionLimit = max(1, min(5000, (int) config('atlas_ai.ledger_projection.limit', 500)));

            $schedule->command("atlas:ai:ledger-project --hours={$projectionHours} --limit={$projectionLimit} --json")
                ->everyTenMinutes()
                ->withoutOverlapping();
        }

        if (config('atlas_ai.autonomous_holding.operating_cycle_enabled', true)) {
            $schedule->command('atlas:ai:autonomous-holding observe-cycle --json')
                ->dailyAt((string) config('atlas_ai.autonomous_holding.operating_cycle_time', '05:40'))
                ->timezone((string) config('atlas_ai.autonomous_holding.timezone', config('app.timezone', 'UTC')))
                ->withoutOverlapping();
        }

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
