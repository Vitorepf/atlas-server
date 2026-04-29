<?php

use App\Console\Commands\AiBootstrapSkillsCommand;
use App\Console\Commands\AiEnqueueCommand;
use App\Console\Commands\AiHealthCommand;
use App\Console\Commands\AiWorkCommand;
use App\Console\Commands\RizeInspectCommand;
use App\Console\Commands\RizeSyncCommand;
use App\Console\Commands\SemanticActivateCommand;
use App\Console\Commands\SemanticBootstrapVaultCommand;
use App\Console\Commands\SemanticGovernCommand;
use App\Console\Commands\SemanticIndexCommand;
use App\Console\Commands\SemanticProposeCommand;
use App\Http\Middleware\AuthenticateAtlasToken;
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
        AiEnqueueCommand::class,
        AiHealthCommand::class,
        AiWorkCommand::class,
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

        if (config('atlas.ai.schedule_worker')) {
            $schedule->command('atlas:ai:work --once --limit=3')
                ->everyMinute()
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
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn () => true);
    })->create();
