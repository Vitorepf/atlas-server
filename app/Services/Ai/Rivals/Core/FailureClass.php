<?php

namespace App\Services\Ai\Rivals\Core;

final class FailureClass
{
    public const MODEL = 'model_failure';

    public const ENVIRONMENT = 'environment_failure';

    public const TIMEOUT = 'timeout';

    public const INVALID_RESULT = 'invalid_result';

    public const JUDGE = 'judge_failure';

    public const BUDGET_EXHAUSTED = 'budget_exhausted';

    public const CANCELLED = 'cancelled';

    public const RATE_LIMITED = 'rate_limited';

    public const PROVIDER_REFUSED = 'provider_refused';

    public const QUOTA_EXHAUSTED = 'quota_exhausted';

    public const HOST_SUSPENDED = 'host_suspended';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::MODEL,
            self::ENVIRONMENT,
            self::TIMEOUT,
            self::INVALID_RESULT,
            self::JUDGE,
            self::BUDGET_EXHAUSTED,
            self::CANCELLED,
            self::RATE_LIMITED,
            self::PROVIDER_REFUSED,
            self::QUOTA_EXHAUSTED,
            self::HOST_SUSPENDED,
        ];
    }
}
