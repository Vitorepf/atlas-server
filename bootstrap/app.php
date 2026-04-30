<?php

use App\Console\Commands\AiBootstrapSkillsCommand;
use App\Console\Commands\AiChatCommand;
use App\Console\Commands\AiDoctorCommand;
use App\Console\Commands\AiEnqueueCommand;
use App\Console\Commands\AiHealthCommand;
use App\Console\Commands\AiWorkCommand;
use App\Console\Commands\AtlasCliBootstrapCommand;
use App\Console\Commands\AtlasCliCheckpointCommand;
use App\Console\Commands\AtlasCliCompareCommand;
use App\Console\Commands\AtlasCliDashboardCommand;
use App\Console\Commands\AtlasCliDevCommand;
use App\Console\Commands\AtlasCliDogfoodCommand;
use App\Console\Commands\AtlasCliDoctorCommand;
use App\Console\Commands\AtlasCliFinalCommand;
use App\Console\Commands\AtlasCliFixCommand;
use App\Console\Commands\AtlasCliHelpCommand;
use App\Console\Commands\AtlasCliInstallCommand;
use App\Console\Commands\AtlasCliInboxCommand;
use App\Console\Commands\AtlasInsightCommand;
use App\Console\Commands\AtlasCliMemoryCommand;
use App\Console\Commands\AtlasCliMobileCommand;
use App\Console\Commands\AtlasCliPermissionsCommand;
use App\Console\Commands\AtlasCliProvidersCommand;
use App\Console\Commands\AtlasProposalCommand;
use App\Console\Commands\AtlasProposalScanCommand;
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
use App\Console\Commands\AtlasSchedulerTickCommand;
use App\Console\Commands\AtlasSelfDiagnosticCommand;
use App\Console\Commands\AtlasRuntimeCommand;
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
        AiWorkCommand::class,
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
        AtlasCliMemoryCommand::class,
        AtlasCliMobileCommand::class,
        AtlasCliPermissionsCommand::class,
        AtlasCliQualityCommand::class,
        AtlasCliReleaseCommand::class,
        AtlasCliProvidersCommand::class,
        AtlasProposalCommand::class,
        AtlasProposalScanCommand::class,
        AtlasCliRollbackCommand::class,
        AtlasCliScheduleCommand::class,
        AtlasCliSetupCommand::class,
        AtlasCliSkillsCommand::class,
        AtlasCliStateCommand::class,
        AtlasCliTraceCommand::class,
        AtlasCliTuiCommand::class,
        AtlasCliUpdateCommand::class,
        AtlasCliVersionCommand::class,
        AtlasSchedulerTickCommand::class,
        AtlasSelfDiagnosticCommand::class,
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
