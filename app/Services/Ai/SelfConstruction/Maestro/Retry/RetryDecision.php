<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\Retry;

/**
 * Immutable verdict from {@see AtlasMaestroGiveBackRetryPolicy::evaluate()}.
 */
final class RetryDecision
{
    public const REASON_OK = 'ok';
    public const REASON_LOOP_DETECTED = 'loop_detected';
    public const REASON_BUDGET_EXHAUSTED = 'budget_exhausted';
    public const REASON_COOLDOWN_ACTIVE = 'cooldown_active';

    public function __construct(
        public readonly bool $allow,
        public readonly string $reason,
        public readonly int $nextAttemptIndex,
    ) {}

    public static function allow(int $nextAttemptIndex): self
    {
        return new self(true, self::REASON_OK, $nextAttemptIndex);
    }

    public static function deny(string $reason, int $nextAttemptIndex): self
    {
        return new self(false, $reason, $nextAttemptIndex);
    }
}
