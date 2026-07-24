<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Ai\Cognition\Watchdog\Checks\AcosDeadSeriesWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\AobgLatencyWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasAcosWatchdogHealthService;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckRegistry;
use App\Services\Ai\Cognition\Watchdog\Checks\AutonomyLadderAdversarialWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\CompactionRecoverySampleWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\DailyCanaryReplayByRefsWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\DiskFreeWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\EvidenceLedgerIntegrityWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\HealthReportWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\JointResourceBudgetWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\LocalModelIntegrityWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\OperatorLearningCaptureSchemaWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\OperatorReviewDebtWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\ProviderBoundRedactionDriftWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\Checks\SubstrateRestoreDrillWatchdogCheck;
use Illuminate\Support\ServiceProvider;

/**
 * ACOS watchdog check registration (full-pass ASP peel).
 */
final class AtlasAcosWatchdogServiceProvider extends ServiceProvider
{
    public function register(): void
    {

        $registry = app(AtlasWatchdogCheckRegistry::class);
        $health = app(AtlasAcosWatchdogHealthService::class);

        foreach (HealthReportWatchdogCheck::makeAll($health) as $check) {
            $registry->register($check);
        }

        foreach ([
            CompactionRecoverySampleWatchdogCheck::class,
            AobgLatencyWatchdogCheck::class,
            SubstrateRestoreDrillWatchdogCheck::class,
            OperatorLearningCaptureSchemaWatchdogCheck::class,
            AcosDeadSeriesWatchdogCheck::class,
            OperatorReviewDebtWatchdogCheck::class,
            LocalModelIntegrityWatchdogCheck::class,
            JointResourceBudgetWatchdogCheck::class,
            DiskFreeWatchdogCheck::class,
            EvidenceLedgerIntegrityWatchdogCheck::class,
            ProviderBoundRedactionDriftWatchdogCheck::class,
            DailyCanaryReplayByRefsWatchdogCheck::class,
            AutonomyLadderAdversarialWatchdogCheck::class,
        ] as $checkClass) {
            $registry->register(app($checkClass));
        }
    
    }
}
