<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognition\Watchdog\Checks;

use App\Services\Ai\Autonomy\AtlasWeeklyMemoryDigestService;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheck;
use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckResult;
use App\Services\Ai\Support\AiValueNormalizer;
use Throwable;

final readonly class OperatorReviewDebtWatchdogCheck implements AtlasWatchdogCheck
{
    public const SCHEMA_VERSION = 'atlas.acos.operator_review_debt_watchdog.v1';

    public const CHECK_ID = 'elev-25.operator_review_debt';
    public const FIELD_CADENCE = 'cadence';
    public const FIELD_OPERATOR_REVIEW_DEBT = 'operator_review_debt';
    public const FIELD_MESSAGE = 'message';
    public const FIELD_CODE = 'code';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_STATUS = 'status';

    public function __construct(
        private AtlasWeeklyMemoryDigestService $digest,
    ) {}

    public function id(): string
    {
        return self::CHECK_ID;
    }

    public function run(): AtlasWatchdogCheckResult
    {
        try {
            $evidence = AiValueNormalizer::arrayOrEmpty(($this->digest->digest(7))[self::FIELD_OPERATOR_REVIEW_DEBT] ?? null);
        } catch (Throwable $e) {
            return AtlasWatchdogCheckResult::error([
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            ], [
                self::FIELD_CODE => 'elev_25_review_debt_unavailable',
                self::FIELD_MESSAGE => $e->getMessage(),
            ]);
        }

        if (($evidence[self::FIELD_STATUS] ?? null) === AtlasWatchdogCheckResult::STATUS_ALERT) {
            return AtlasWatchdogCheckResult::alert($evidence, [
                self::FIELD_CODE => self::CHECK_ID,
                self::FIELD_MESSAGE => 'Operator review-debt idade_max_da_fila exceeded the frozen cap; next auto-apply cycle is slowed ephemerally.',
                self::FIELD_CADENCE => $evidence[self::FIELD_CADENCE] ?? [],
            ]);
        }

        return AtlasWatchdogCheckResult::ok($evidence);
    }
}
