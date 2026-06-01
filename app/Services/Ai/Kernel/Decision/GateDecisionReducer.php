<?php

declare(strict_types=1);

namespace App\Services\Ai\Kernel\Decision;

/**
 * Fail-closed worst-of reducer over per-gate status tokens.
 *
 * Mirrors the escalation in AreaFocusGateEvaluatorService (block > warn > allow):
 * input tokens are the gate statuses 'pass'/'warn'/'block' and the verdict is one
 * of the decisions 'allow'/'warn'/'block'. Any empty input, or any token that is
 * not the exact lowercase 'pass'/'warn'/'block' string (unknown value, non-string,
 * or wrong case), fails closed to 'block'. The reduction is commutative: the verdict
 * never depends on the order of the tokens.
 */
final class GateDecisionReducer
{
    private const STATUS_PASS = 'pass';

    private const STATUS_WARN = 'warn';

    private const STATUS_BLOCK = 'block';

    private const DECISION_ALLOW = 'allow';

    private const DECISION_WARN = 'warn';

    private const DECISION_BLOCK = 'block';

    /**
     * @param  array<array-key, mixed>  $statuses
     * @return 'allow'|'warn'|'block'
     */
    public function decide(array $statuses): string
    {
        if ($statuses === []) {
            return self::DECISION_BLOCK;
        }

        $sawWarn = false;

        foreach ($statuses as $status) {
            if ($status === self::STATUS_PASS) {
                continue;
            }

            if ($status === self::STATUS_WARN) {
                $sawWarn = true;

                continue;
            }

            return self::DECISION_BLOCK;
        }

        return $sawWarn ? self::DECISION_WARN : self::DECISION_ALLOW;
    }
}
