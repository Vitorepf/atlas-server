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
            $evidence = AiValueNormalizer::arrayOrEmpty(($this->digest->digest(7))['operator_review_debt'] ?? null);
        } catch (Throwable $e) {
            return AtlasWatchdogCheckResult::error([
                'schema_version' => self::SCHEMA_VERSION,
            ], [
                'code' => 'elev_25_review_debt_unavailable',
                'message' => $e->getMessage(),
            ]);
        }

        if (($evidence['status'] ?? null) === 'alert') {
            return AtlasWatchdogCheckResult::alert($evidence, [
                'code' => 'elev-25.operator_review_debt',
                'message' => 'Operator review-debt idade_max_da_fila exceeded the frozen cap; next auto-apply cycle is slowed ephemerally.',
                'cadence' => $evidence['cadence'] ?? [],
            ]);
        }

        return AtlasWatchdogCheckResult::ok($evidence);
    }
}
