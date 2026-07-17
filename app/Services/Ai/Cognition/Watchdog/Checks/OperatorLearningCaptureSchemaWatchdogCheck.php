<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use App\Services\Ai\OperatorIntelligence\OperatorLearningRuntimeCaptureService;

final readonly class OperatorLearningCaptureSchemaWatchdogCheck implements AtlasWatchdogCheck
{
    public const CHECK_ID = 'maxn-01.operator_learning_capture_schema';

    public function __construct(private OperatorLearningRuntimeCaptureService $capture) {}

    public function id(): string
    {
        return self::CHECK_ID;
    }

    public function run(): AtlasWatchdogCheckResult
    {
        $report = $this->capture->captureFailureReport();
        $missingTables = $report['missing_tables'];

        if (! $report['chat_capture_enabled']) {
            return AtlasWatchdogCheckResult::skipped(array_merge($report, [
                'reason' => 'operator_learning_capture_disabled',
            ]));
        }

        if ($missingTables !== []) {
            return AtlasWatchdogCheckResult::alert($report, [
                'code' => 'operator_learning_schema_missing',
                'message' => 'Operator chat capture is enabled but required operator_* tables are missing.',
                'missing_tables' => $missingTables,
            ]);
        }

        return AtlasWatchdogCheckResult::ok(array_merge($report, [
            'operator_schema_ready' => true,
        ]));
    }
}
