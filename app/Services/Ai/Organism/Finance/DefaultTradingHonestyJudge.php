<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism\Finance;

use App\Services\Ai\Finance\StrategyLoop\TradingHonestyGate;

/**
 * AOBG N4.F2 — the default {@see TradingHonestyJudge}: a thin adapter that DELEGATES to the
 * shipped, sealed {@see TradingHonestyGate} (DSR / PBO / sealed holdout in the REAL Python
 * numpy honest-metrics runtime). It adds NOTHING — no metric, no decision — it only forwards.
 *
 * This is the production binding: it keeps the kernel's certify/null decision and the
 * audit-pinned DSR (Lo-2002 variance floor) where they live — inside the final gate — while
 * giving the organism a substitutable seam for cost-free tests. Win-rate is never consulted
 * (the gate forbids it).
 */
final class DefaultTradingHonestyJudge implements TradingHonestyJudge
{
    public function __construct(private readonly TradingHonestyGate $gate = new TradingHonestyGate) {}

    public function evaluate(array $input): array
    {
        return $this->gate->evaluate($input);
    }
}
