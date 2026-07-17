<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use Carbon\CarbonImmutable;

final class SubstrateRestoreDrillWatchdogCheck implements AtlasWatchdogCheck
{
    public const SCHEMA_VERSION = 'atlas.memory.substrate_restore_drill.watchdog.v1';

    public const CHECK_ID = 'wdg-01.substrate_restore_drill';

    public const DEFAULT_MAX_SUCCESS_AGE_DAYS = 45;

    public const RECEIPT_PATH_CONFIG_KEY = 'atlas.cognition.substrate_restore_drill.receipt_path';

    public const DEFAULT_RECEIPT_RELATIVE_PATH = 'app/atlas/evidence/substrate-restore-drills.jsonl';

    public const MAX_SUCCESS_AGE_DAYS_CONFIG_KEY = 'atlas.cognition.substrate_restore_drill.max_success_age_days';

    public const REASON_NO_SUCCESSFUL_DRILL = 'no_successful_drill';

    public const REASON_SUCCESSFUL_DRILL_FRESH = 'successful_drill_fresh';

    public const REASON_SUCCESSFUL_DRILL_STALE = 'successful_drill_stale';


    public function id(): string
    {
        return self::CHECK_ID;
    }

    public function run(): AtlasWatchdogCheckResult
    {
        $receiptPath = AiValueNormalizer::trimmedStringOrNull(config(self::RECEIPT_PATH_CONFIG_KEY))
            ?? storage_path(self::DEFAULT_RECEIPT_RELATIVE_PATH);
        $maxAgeDays = max(1, (int) (AiValueNormalizer::finiteFloatOrNull(config(self::MAX_SUCCESS_AGE_DAYS_CONFIG_KEY, self::DEFAULT_MAX_SUCCESS_AGE_DAYS)) ?? self::DEFAULT_MAX_SUCCESS_AGE_DAYS));
        $latest = $this->latestSuccessfulReceipt($receiptPath);

        if ($latest === null) {
            return AtlasWatchdogCheckResult::alert([
                'schema_version' => self::SCHEMA_VERSION,
                'receipt_path' => $receiptPath,
                'max_success_age_days' => $maxAgeDays,
                'reason' => self::REASON_NO_SUCCESSFUL_DRILL,
            ], [
                'code' => 'substrate_restore_drill_missing',
                'message' => 'No successful SUB-01 restore drill receipt found.',
            ]);
        }

        $checkedAt = CarbonImmutable::parse((AiValueNormalizer::trimmedStringOrNull($latest['checked_at'] ?? null) ?? 'now'), 'UTC');
        $ageDays = (int) $checkedAt->diffInDays(CarbonImmutable::now('UTC'));
        $evidence = [
            'schema_version' => self::SCHEMA_VERSION,
            'receipt_path' => $receiptPath,
            'max_success_age_days' => $maxAgeDays,
            'last_successful_drill_at' => $checkedAt->toISOString(),
            'age_days' => $ageDays,
            'snapshot_path' => $latest['snapshot_path'] ?? null,
        ];

        if ($ageDays > $maxAgeDays) {
            return AtlasWatchdogCheckResult::alert($evidence + ['reason' => self::REASON_SUCCESSFUL_DRILL_STALE], [
                'code' => 'substrate_restore_drill_stale',
                'message' => 'Last successful SUB-01 restore drill is older than the allowed window.',
            ]);
        }

        return AtlasWatchdogCheckResult::ok($evidence + ['reason' => self::REASON_SUCCESSFUL_DRILL_FRESH]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestSuccessfulReceipt(string $receiptPath): ?array
    {
        $rows = (new JsonlReceiptStore($receiptPath))->read();
        $successes = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row['status'] ?? null) === 'restored_ok'
                && (AiValueNormalizer::boolOrNull($row['restored_ok'] ?? null) ?? false)
                && is_string($row['checked_at'] ?? null),
        ));

        usort(
            $successes,
            static fn (array $a, array $b): int => strcmp((AiValueNormalizer::trimmedStringOrNull($b['checked_at'] ?? null) ?? ''), (AiValueNormalizer::trimmedStringOrNull($a['checked_at'] ?? null) ?? '')),
        );

        return $successes[0] ?? null;
    }
}
