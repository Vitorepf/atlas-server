<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism;

/**
 * AOBG N4.F1 — the per-domain HONEST-METRIC seam.
 *
 * A domain proposal is NEVER self-declared a success. Every domain plugs in a validator
 * that scores the proposal against a REAL honest metric for that domain — the metric that
 * cannot be gamed by the textbook fake-green:
 *   - finance/trading: DSR (N-deflated Sharpe) / PBO (overfitting) / sealed holdout —
 *     win-rate is FORBIDDEN (see {@see Finance\FinanceDomainValidator} reusing
 *     {@see \App\Services\Ai\Finance\StrategyLoop\Metrics\HonestMetrics} and, for the full
 *     bundle on-machine, {@see \App\Services\Ai\Finance\StrategyLoop\TradingHonestyGate});
 *   - other domains supply their own honest, non-vanity metric as they are onboarded.
 *
 * The verdict shape is uniform so the organism can present ANY domain's validation the
 * same way: {metric, value, passed, method, detail?}.
 */
interface DomainValidator
{
    /**
     * Score a proposal against the domain's honest metric.
     *
     * @return array{
     *     metric: string,            // the metric name (e.g. "deflated_sharpe")
     *     value: float|null,         // its value (null ⇒ honest-empty / could not score)
     *     passed: bool,              // did it clear the honest threshold?
     *     method: string,            // HOW it was computed (e.g. "honest_metrics.sharpe.in_process")
     *     reasons?: list<string>,    // why it failed (never a green word it did not earn)
     *     detail?: array<string,mixed> // provider-safe extra numbers (no payload echo)
     * }
     */
    public function validate(DomainProposal $proposal): array;

    /** The canonical domain id this validator serves. */
    public function domain(): string;
}
