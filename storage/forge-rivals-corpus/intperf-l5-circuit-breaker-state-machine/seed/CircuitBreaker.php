<?php

declare(strict_types=1);

namespace App\Domain\Resilience;

final class CircuitBreaker
{
    public const STATE_CLOSED = 'closed';

    public const STATE_OPEN = 'open';

    public const STATE_HALF_OPEN = 'half_open';

    /**
     * SEED stub — always pretends the breaker is closed. The arm must
     * implement the 3-state machine described in README.md, including
     * the backoff transition from open→half_open and emit a
     * CircuitBreakerEvent on every transition.
     *
     * @param  callable():mixed  $call
     * @return array{status:string,result?:mixed,reason?:string}
     */
    public function execute(callable $call): array
    {
        try {
            return ['status' => 'ok', 'result' => $call()];
        } catch (\Throwable $e) {
            return ['status' => 'failed', 'reason' => $e->getMessage()];
        }
    }

    public function state(): string
    {
        return self::STATE_CLOSED;
    }

    /** @return list<CircuitBreakerEvent> */
    public function events(): array
    {
        return [];
    }
}
