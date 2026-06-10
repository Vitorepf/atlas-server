<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism\Finance;

/**
 * AOBG N4.F2 — the thin SEAM the {@see FinanceDomainValidator} depends on for the FULL
 * honesty bundle (DSR / PBO / sealed holdout).
 *
 * The shipped engine {@see \App\Services\Ai\Finance\StrategyLoop\TradingHonestyGate} is
 * `final` (the kernel keeps the certify/null decision sealed). Rather than weaken it, the
 * validator depends on THIS abstraction and the default binding is
 * {@see DefaultTradingHonestyJudge}, which delegates to the real, sealed gate (and through
 * it to the REAL Python numpy honest-metrics runtime). A test injects a deterministic judge
 * so the organism stays cost-free WITHOUT touching the shipped gate or spawning a subprocess.
 *
 * The verdict shape is exactly the gate's: {certified, reasons, report{deflated_sharpe, pbo,
 * scoring_sharpe, holdout_sharpe, holdout_trades, n_trials, var_sharpe_across_trials,
 * thresholds}}. Win-rate is never part of it — on either the real or the test path.
 */
interface TradingHonestyJudge
{
    /**
     * @param  array{
     *     winner_daily_returns: list<float>,
     *     sibling_windows: list<list<float>>,
     *     sibling_sharpes: list<float>,
     *     scenarios_explored: int,
     *     holdout_sharpe: float,
     *     holdout_trades?: int,
     *     thresholds?: array<string,float|int>
     * }  $input
     * @return array<string,mixed> {certified:bool, reasons:list<string>, report:array<string,mixed>}
     */
    public function evaluate(array $input): array;
}
