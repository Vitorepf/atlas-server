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
    public const FIELD_REASON = 'reason';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_RECEIPT_PATH = 'receipt_path';
    public const FIELD_MAX_SUCCESS_AGE_DAYS = 'max_success_age_days';
    public const FIELD_CODE = 'code';
    public const FIELD_MESSAGE = 'message';
    public const FIELD_LAST_SUCCESSFUL_DRILL_AT = 'last_successful_drill_at';
    public const FIELD_AGE_DAYS = 'age_days';
    public const FIELD_CHECKED_AT = 'checked_at';
    public const FIELD_SNAPSHOT_PATH = 'snapshot_path';
    public const FIELD_RESTORED_OK = 'restored_ok';
    public const FIELD_STATUS = 'status';
    public const FIELD_NOW = 'now';
    public const FIELD_SUBSTRATE_RESTORE_DRILL_MISSING = 'substrate_restore_drill_missing';
    public const FIELD_SUBSTRATE_RESTORE_DRILL_STALE = 'substrate_restore_drill_stale';
    public const FIELD_UTC = 'UTC';
    public const FIELD_LAST_SUCCESSFUL_SUB_01_RESTORE_DRILL_IS_OLDER_THAN_THE_ALLOWED_WINDOW_ = 'Last successful SUB-01 restore drill is older than the allowed window.';
    public const FIELD_NO_SUCCESSFUL_SUB_01_RESTORE_DRILL_RECEIPT_FOUND_ = 'No successful SUB-01 restore drill receipt found.';


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
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                self::FIELD_RECEIPT_PATH => $receiptPath,
                self::FIELD_MAX_SUCCESS_AGE_DAYS => $maxAgeDays,
                self::FIELD_REASON => self::REASON_NO_SUCCESSFUL_DRILL,
            ], [
                self::FIELD_CODE => self::FIELD_SUBSTRATE_RESTORE_DRILL_MISSING,
                self::FIELD_MESSAGE => self::FIELD_NO_SUCCESSFUL_SUB_01_RESTORE_DRILL_RECEIPT_FOUND_,
            ]);
        }

        $checkedAt = CarbonImmutable::parse((AiValueNormalizer::trimmedStringOrNull($latest[self::FIELD_CHECKED_AT] ?? null) ?? self::FIELD_NOW), self::FIELD_UTC);
        $ageDays = (int) $checkedAt->diffInDays(CarbonImmutable::now(self::FIELD_UTC));
        $evidence = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_RECEIPT_PATH => $receiptPath,
            self::FIELD_MAX_SUCCESS_AGE_DAYS => $maxAgeDays,
            self::FIELD_LAST_SUCCESSFUL_DRILL_AT => $checkedAt->toISOString(),
            self::FIELD_AGE_DAYS => $ageDays,
            self::FIELD_SNAPSHOT_PATH => $latest[self::FIELD_SNAPSHOT_PATH] ?? null,
        ];

        if ($ageDays > $maxAgeDays) {
            return AtlasWatchdogCheckResult::alert($evidence + [self::FIELD_REASON => self::REASON_SUCCESSFUL_DRILL_STALE], [
                self::FIELD_CODE => self::FIELD_SUBSTRATE_RESTORE_DRILL_STALE,
                self::FIELD_MESSAGE => self::FIELD_LAST_SUCCESSFUL_SUB_01_RESTORE_DRILL_IS_OLDER_THAN_THE_ALLOWED_WINDOW_,
            ]);
        }

        return AtlasWatchdogCheckResult::ok($evidence + [self::FIELD_REASON => self::REASON_SUCCESSFUL_DRILL_FRESH]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestSuccessfulReceipt(string $receiptPath): ?array
    {
        $rows = (new JsonlReceiptStore($receiptPath))->read();
        $successes = array_values(array_filter(
            $rows,
            static fn (array $row): bool => ($row[self::FIELD_STATUS] ?? null) === self::FIELD_RESTORED_OK
                && (AiValueNormalizer::boolOrNull($row[self::FIELD_RESTORED_OK] ?? null) ?? false)
                && is_string($row[self::FIELD_CHECKED_AT] ?? null),
        ));

        usort(
            $successes,
            static fn (array $a, array $b): int => strcmp((AiValueNormalizer::trimmedStringOrNull($b[self::FIELD_CHECKED_AT] ?? null) ?? ''), (AiValueNormalizer::trimmedStringOrNull($a[self::FIELD_CHECKED_AT] ?? null) ?? '')),
        );

        return $successes[0] ?? null;
    }
}
