<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism\Finance;

use App\Services\Ai\Finance\StrategyLoop\Metrics\HonestMetrics;
use App\Services\Ai\Finance\StrategyLoop\TradingHonestyGate;
use App\Services\Ai\Organism\DomainProposal;
// NB: DefaultTradingHonestyJudge + TradingHonestyJudge are same-namespace (App\Services\Ai\Organism\Finance).
use App\Services\Ai\Organism\DomainValidator;
use Throwable;

/**
 * AOBG N4.F2 — the FINANCE {@see DomainValidator}: the REAL honest metric, REUSED.
 *
 * F1 stubbed this to the cost-free in-process Sharpe and only NAMED the full bundle.
 * F2 plugs in the REAL finance/trading honesty stack — it now DELEGATES to the shipped
 * {@see TradingHonestyGate} (the post-selection judge that runs the N-deflated Deflated
 * Sharpe with the Lo-2002 variance floor + PBO/CSCV + sealed holdout in the REAL Python
 * numpy runtime via {@see \App\Services\Ai\RuntimeBoundary\HonestMetricsRuntimeClient}) —
 * the exact gate {@see \App\Console\Commands\AtlasFinanceStrategyEvolveCommand} feeds. We
 * REUSE it; we do NOT reimplement any trading metric.
 *
 * TWO honest paths, chosen by what the on-machine payload carries — never a fabricated green:
 *   1) FULL BUNDLE (the real one): when the proposal's payload carries the strategy-loop's
 *      honesty inputs ({winner_daily_returns | daily_returns, sibling_windows,
 *      sibling_sharpes, scenarios_explored, holdout_sharpe, holdout_trades}) AND the Python
 *      runtime is available, delegate to {@see TradingHonestyGate::evaluate()} → DSR/PBO/
 *      holdout. `passed` = the gate's `certified`. This is the real, audit-pinned judge.
 *   2) HONEST-EMPTY / DEGRADE: when the full inputs are absent (a bare trade-idea payload
 *      with only `daily_returns`), or the runtime is unavailable, we DECLARE that honestly
 *      and score the cost-free in-process annualized Sharpe ({@see HonestMetrics}) against a
 *      materially-positive floor. The method string always says which path ran, so a reader
 *      knows the proposal was NOT judged by the full DSR/PBO bundle.
 *
 * WIN-RATE IS FORBIDDEN — it is the textbook trading fake-green and is never consulted on
 * EITHER path, here or in the engine. A proposal with no scorable returns is HONEST-EMPTY
 * (value null, passed false), never a fabricated green.
 *
 * COST: zero provider tokens. Path 1 is one LOCAL Python subprocess (no provider, gated by
 * runtime availability + payload presence — and the organism tests inject a stub gate so it
 * is cost-free); path 2 is pure in-process math. Finance is sensitive → everything stays
 * on-machine; the payload is the on-machine validator input and is NEVER provider-serialized.
 */
final class FinanceDomainValidator implements DomainValidator
{
    public const DOMAIN = 'finance';

    /** A materially-positive honest Sharpe floor — NOT a >=0 sign test, NOT win-rate. */
    private const SHARPE_FLOOR = 0.5;

    public function __construct(
        private readonly HonestMetrics $metrics = new HonestMetrics,
        private readonly ?TradingHonestyJudge $honestyGate = null,
    ) {}

    public function validate(DomainProposal $proposal): array
    {
        $payload = $proposal->payload;

        // 1) FULL BUNDLE — the REAL trading honesty gate (DSR/PBO/sealed holdout), reused,
        //    gated by the presence of its required on-machine inputs. Win-rate is never
        //    consulted by the gate. If the runtime is unavailable it throws; we degrade to
        //    the honest in-process Sharpe rather than fabricate a verdict.
        if ($this->hasFullBundleInputs($payload)) {
            $full = $this->validateWithHonestyGate($payload);
            if ($full !== null) {
                return $full;
            }
            // fell through ⇒ runtime unavailable; degrade honestly to the in-process Sharpe.
        }

        // 2) HONEST in-process Sharpe (cost-free), or HONEST-EMPTY when unscoreable.
        return $this->validateInProcessSharpe($payload);
    }

    public function domain(): string
    {
        return self::DOMAIN;
    }

    /**
     * The full bundle is only meaningful when the strategy-loop honesty inputs are present:
     * the cross-sibling windows/Sharpes (for the N-deflation + PBO) and the sealed-holdout
     * result. A bare trade-idea payload (only `daily_returns`) does NOT qualify — we never
     * call the gate with degenerate inputs and never claim a DSR it could not compute.
     *
     * @param  array<string,mixed>  $payload
     */
    private function hasFullBundleInputs(array $payload): bool
    {
        $returns = $this->floats($payload['winner_daily_returns'] ?? $payload['daily_returns'] ?? $payload['returns'] ?? []);

        return count($returns) >= 2
            && is_array($payload['sibling_windows'] ?? null) && ($payload['sibling_windows'] ?? []) !== []
            && is_array($payload['sibling_sharpes'] ?? null) && ($payload['sibling_sharpes'] ?? []) !== []
            && array_key_exists('holdout_sharpe', $payload);
    }

    /**
     * Delegate to the REAL {@see TradingHonestyGate}. Returns the uniform verdict, or null
     * if the gate cannot run (Python honest-metrics runtime absent) so the caller degrades
     * honestly — NEVER a fabricated pass.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>|null
     */
    private function validateWithHonestyGate(array $payload): ?array
    {
        $gate = $this->honestyGate ?? new DefaultTradingHonestyJudge;

        try {
            $verdict = $gate->evaluate([
                'winner_daily_returns' => $this->floats($payload['winner_daily_returns'] ?? $payload['daily_returns'] ?? $payload['returns'] ?? []),
                'sibling_windows' => $this->matrix($payload['sibling_windows'] ?? []),
                'sibling_sharpes' => $this->floats($payload['sibling_sharpes'] ?? []),
                'scenarios_explored' => max(1, (int) ($payload['scenarios_explored'] ?? 1)),
                'holdout_sharpe' => (float) ($payload['holdout_sharpe'] ?? -INF),
                'holdout_trades' => (int) ($payload['holdout_trades'] ?? 0),
                'thresholds' => is_array($payload['thresholds'] ?? null) ? $payload['thresholds'] : [],
            ]);
        } catch (Throwable) {
            // The honest-metrics Python runtime is not set up (the boundary throws by canon —
            // a real engine or an honest failure, never a hand-rolled stand-in). Degrade to
            // the cost-free in-process Sharpe rather than invent a DSR.
            return null;
        }

        $report = is_array($verdict['report'] ?? null) ? $verdict['report'] : [];
        $certified = (bool) ($verdict['certified'] ?? false);

        return [
            // The metric IS the deflated Sharpe — the real overfitting-aware honest metric.
            'metric' => 'deflated_sharpe',
            'value' => isset($report['deflated_sharpe']) ? (float) $report['deflated_sharpe'] : null,
            'passed' => $certified,
            // Names the REAL gate reused — and that win-rate is forbidden. Never "win_rate".
            'method' => 'trading_honesty_gate.dsr_pbo_holdout.on_machine_python(win_rate_forbidden)',
            'reasons' => is_array($verdict['reasons'] ?? null) && $verdict['reasons'] !== []
                ? array_values(array_map('strval', $verdict['reasons']))
                : [$certified ? 'certified' : 'not_certified'],
            // Provider-safe NUMBERS only (the gate's report carries no payload echo).
            'detail' => [
                'deflated_sharpe' => $report['deflated_sharpe'] ?? null,
                'pbo' => $report['pbo'] ?? null,
                'scoring_sharpe' => $report['scoring_sharpe'] ?? null,
                'holdout_sharpe' => $report['holdout_sharpe'] ?? null,
                'holdout_trades' => $report['holdout_trades'] ?? null,
                'n_trials' => $report['n_trials'] ?? null,
                'var_sharpe_across_trials' => $report['var_sharpe_across_trials'] ?? null,
                'thresholds' => $report['thresholds'] ?? null,
            ],
        ];
    }

    /**
     * The cost-free in-process annualized Sharpe (the trivial O(n) reduction the inner
     * search loop already uses) against a materially-positive floor. HONEST-EMPTY when
     * there are no scorable returns. Win-rate is never consulted.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function validateInProcessSharpe(array $payload): array
    {
        $returns = $this->floats($payload['daily_returns'] ?? $payload['returns'] ?? $payload['winner_daily_returns'] ?? []);

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
            // The method names the honest stack reused — and that this is the degraded,
            // single-window path (NOT the full DSR/PBO bundle). Never "win_rate".
            'method' => 'honest_metrics.sharpe.in_process(win_rate_forbidden)',
            'reasons' => $reasons === [] ? ['honest_sharpe_cleared_floor'] : $reasons,
            'detail' => array_filter([
                'sharpe_floor' => self::SHARPE_FLOOR,
                'periods_per_year' => $periodsPerYear,
                'n_returns' => count($returns),
                'max_drawdown' => $maxDd,
                // Pointer to the full honest bundle (DSR/PBO/holdout) the gate path uses
                // when the strategy-loop inputs are present — runs on-machine (Python numpy).
                'full_honest_bundle' => TradingHonestyGate::class,
            ], static fn ($v): bool => $v !== null),
        ];
    }

    /** @param mixed $list @return list<float> */
    private function floats($list): array
    {
        if (! is_array($list)) {
            return [];
        }

        return array_values(array_map('floatval', array_filter($list, 'is_numeric')));
    }

    /**
     * @param  mixed  $matrix
     * @return list<list<float>>
     */
    private function matrix($matrix): array
    {
        if (! is_array($matrix)) {
            return [];
        }
        $rows = [];
        foreach ($matrix as $row) {
            if (is_array($row)) {
                $rows[] = $this->floats($row);
            }
        }

        return $rows;
    }
}
