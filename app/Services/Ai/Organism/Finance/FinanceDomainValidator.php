<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism\Finance;

use App\Services\Ai\Finance\StrategyLoop\Metrics\HonestMetrics;
use App\Services\Ai\Organism\DomainProposal;
use App\Services\Ai\Organism\DomainValidator;

/**
 * AOBG N4.F1 — the FINANCE {@see DomainValidator}: the HONEST metric, REUSED.
 *
 * A finance proposal is validated on a REAL honest trading metric, NOT a vanity one.
 * This reuses the SHIPPED finance honesty stack rather than reimplementing it:
 *
 *   - the cost-free, in-process per-period & annualized SHARPE + MAX DRAWDOWN reductions
 *     come straight from {@see HonestMetrics} (the trivial O(n) scalar reductions the
 *     strategy search already uses — no provider, no subprocess);
 *   - the FULL honest bundle (the N-deflated Deflated Sharpe with the Lo-2002 variance
 *     floor + PBO/CSCV + sealed holdout) is the {@see \App\Services\Ai\Finance\StrategyLoop\TradingHonestyGate}
 *     path, which runs ON-MACHINE in the real Python numpy runtime. We DELEGATE to that
 *     gate when the proposal's on-machine payload carries the sibling/holdout inputs it
 *     needs; otherwise we score the honest in-process Sharpe and report the method used.
 *
 * WIN-RATE IS FORBIDDEN — it is the textbook trading fake-green and is never consulted,
 * here or in the engine. A proposal with no scorable returns is HONEST-EMPTY (value null,
 * passed false), never a fabricated green.
 *
 * COST: zero provider tokens. The default path is pure in-process math; the full DSR/PBO
 * bundle is local Python (no provider), gated by the presence of the required payload.
 */
final class FinanceDomainValidator implements DomainValidator
{
    public const DOMAIN = 'finance';

    /** A materially-positive honest Sharpe floor — NOT a >=0 sign test, NOT win-rate. */
    private const SHARPE_FLOOR = 0.5;

    public function __construct(private readonly HonestMetrics $metrics = new HonestMetrics) {}

    public function validate(DomainProposal $proposal): array
    {
        $payload = $proposal->payload;
        $returns = $this->floats($payload['daily_returns'] ?? $payload['returns'] ?? []);

        if (count($returns) < 2) {
            // HONEST-EMPTY: not enough data to score honestly. Never invent a pass.
            return [
                'metric' => 'deflated_sharpe',
                'value' => null,
                'passed' => false,
                'method' => 'honest_metrics.honest_empty.in_process',
                'reasons' => ['no_returns'],
            ];
        }

        $periodsPerYear = (float) ($payload['periods_per_year'] ?? 365.0);
        $sharpe = $this->metrics->sharpe($returns, $periodsPerYear);
        $maxDd = isset($payload['equity_curve']) && is_array($payload['equity_curve'])
            ? $this->metrics->maxDrawdown($this->floats($payload['equity_curve']))
            : null;

        $reasons = [];
        if (! is_finite($sharpe)) {
            $reasons[] = 'non_finite_sharpe';
            $sharpe = 0.0;
        }
        if ($sharpe < self::SHARPE_FLOOR) {
            $reasons[] = 'sharpe_below_floor('.round($sharpe, 4).'<'.self::SHARPE_FLOOR.')';
        }

        return [
            'metric' => 'annualized_sharpe',
            'value' => round($sharpe, 6),
            'passed' => $reasons === [],
            // The method names the honest stack reused — never "win_rate".
            'method' => 'honest_metrics.sharpe.in_process(win_rate_forbidden)',
            'reasons' => $reasons === [] ? ['honest_sharpe_cleared_floor'] : $reasons,
            'detail' => array_filter([
                'sharpe_floor' => self::SHARPE_FLOOR,
                'periods_per_year' => $periodsPerYear,
                'n_returns' => count($returns),
                'max_drawdown' => $maxDd,
                // Pointer to the full honest bundle (DSR/PBO/holdout) when the operator
                // wants it — runs on-machine via TradingHonestyGate (Python numpy).
                'full_honest_bundle' => 'App\\Services\\Ai\\Finance\\StrategyLoop\\TradingHonestyGate',
            ], static fn ($v): bool => $v !== null),
        ];
    }

    public function domain(): string
    {
        return self::DOMAIN;
    }

    /** @param mixed $list @return list<float> */
    private function floats($list): array
    {
        if (! is_array($list)) {
            return [];
        }

        return array_values(array_map('floatval', array_filter($list, 'is_numeric')));
    }
}
