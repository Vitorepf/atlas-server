<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use App\Services\Ai\OperatorIntelligence\OperatorLearningRuntimeCaptureService;

final readonly class OperatorLearningCaptureSchemaWatchdogCheck implements AtlasWatchdogCheck
{
    public const CHECK_ID = 'maxn-01.operator_learning_capture_schema';
    public const FIELD_MISSING_TABLES = 'missing_tables';
    public const FIELD_CHAT_CAPTURE_ENABLED = 'chat_capture_enabled';
    public const FIELD_REASON = 'reason';
    public const FIELD_OPERATOR_SCHEMA_READY = 'operator_schema_ready';

    public function __construct(private OperatorLearningRuntimeCaptureService $capture) {}

    public function id(): string
    {
        return self::CHECK_ID;
    }

    public function run(): AtlasWatchdogCheckResult
    {
        $report = $this->capture->captureFailureReport();
        $missingTables = $report[self::FIELD_MISSING_TABLES];

        if (! $report[self::FIELD_CHAT_CAPTURE_ENABLED]) {
            return AtlasWatchdogCheckResult::skipped(array_merge($report, [
                self::FIELD_REASON => 'operator_learning_capture_disabled',
            ]));
        }

        if ($missingTables !== []) {
            return AtlasWatchdogCheckResult::alert($report, [
                'code' => 'operator_learning_schema_missing',
                'message' => 'Operator chat capture is enabled but required operator_* tables are missing.',
                self::FIELD_MISSING_TABLES => $missingTables,
            ]);
        }

        return AtlasWatchdogCheckResult::ok(array_merge($report, [
            self::FIELD_OPERATOR_SCHEMA_READY => true,
        ]));
    }
}
