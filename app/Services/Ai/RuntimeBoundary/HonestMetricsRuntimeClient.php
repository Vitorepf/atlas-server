<?php

declare(strict_types=1);

namespace App\Services\Ai\RuntimeBoundary;

/**
 * PHP adapter to the REAL Python honest-finance-metrics data runtime
 * (runtimes/python/honest_metrics). By the runtime_language_boundary canon — and
 * the operator thesis "data math belongs in Python; nunca faça em PHP o que
 * deveria ser Python; the kernel scanner forbids reimplementing engines in PHP" —
 * the PHP kernel must NOT hand-roll the Deflated Sharpe (with the Lo-2002 variance
 * floor), the PBO/CSCV overfitting estimator, or the return-distribution moments;
 * it invokes this Python runtime (numpy) instead.
 *
 * This client is the governed bridge: it writes a manifest, runs the runtime under
 * its own venv (where numpy lives), parses the receipt, and REFUSES any result
 * that is not real, in-Python numpy (boundary.honest_metrics_in_python !== true) —
 * so a PHP hand-rolled stand-in can never pass back through the boundary.
 *
 * Mirrors StatsEngineRuntimeClient / SemanticRagRuntimeClient EXACTLY (manifest
 * temp-file, single Process invocation of .venv/bin/python main.py, receipt
 * enforcement). There is NO PHP fallback math by design: if the runtime is not set
 * up the client raises explicitly (run scripts/setup-honest-metrics-runtime.sh).
 * That is the canon: a real Python engine or an honest failure, never a hand-rolled
 * stand-in of a SENSITIVE finance metric.
 *
 * The SENSITIVE piece is the DSR's Lo-2002 analytic variance floor (a prior audit
 * fix that keeps the trial count N biting when the passing siblings cluster). It is
 * ported FAITHFULLY into the Python engine and proven behaviourally equivalent to
 * the removed PHP (old-vs-new known-answer within 1e-9, incl. the varSharpe=0 /
 * clustered-siblings N-sweep). See runtimes/python/honest_metrics/tests/.
 *
 * honestyGate() is the perf contract: the post-selection TradingHonestyGate is NOT
 * on a synchronous HTTP hot path (it scores ONE campaign verdict), so it computes
 * the WHOLE honesty bundle (var_sharpe + DSR + PBO + scoring Sharpe) in ONE
 * subprocess instead of one boundary call per metric.
 */
final class HonestMetricsRuntimeClient
{
    private const RUNTIME_ROOT = 'runtimes/python/honest_metrics';

    private readonly PythonManifestRuntimeClient $runtime;

    public function __construct(?PythonManifestRuntimeClient $runtime = null)
    {
        $this->runtime = $runtime ?? new PythonManifestRuntimeClient(
            self::RUNTIME_ROOT,
            'atlas-honest-metrics',
            'honest_metrics Python runtime is not set up — run scripts/setup-honest-metrics-runtime.sh. '
                .'The canon forbids a PHP finance-metrics fallback; this is an explicit failure, not a silent hand-rolled stand-in.',
            'honest_metrics',
        );
    }

    public function available(): bool
    {
        return $this->runtime->available();
    }

    /**
     * The complete post-selection honesty computation in one subprocess: the
     * cross-trial Sharpe variance (std² of the sibling Sharpes), the N-deflated
     * DSR with the Lo-2002 floor, the PBO over the sibling OOS windows, and the
     * annualized scoring Sharpe. The PHP gate keeps the thresholds + decisioning;
     * this returns only the numbers.
     *
     * @param  array<int,float>  $winnerDailyReturns
     * @param  array<int,float>  $siblingSharpes
     * @param  array<int,array<int,float>>  $siblingWindows
     * @return array<string,mixed>
     */
    public function honestyGate(
        array $winnerDailyReturns,
        array $siblingSharpes,
        array $siblingWindows,
        int $scenariosExplored,
        float $periodsPerYear = 365.0,
        int $pboBlocks = 8,
    ): array {
        return $this->run([
            'operation' => 'honesty_gate',
            'winner_daily_returns' => array_values($winnerDailyReturns),
            'sibling_sharpes' => array_values($siblingSharpes),
            'sibling_windows' => array_map(static fn ($w): array => array_values($w), array_values($siblingWindows)),
            'scenarios_explored' => $scenariosExplored,
            'periods_per_year' => $periodsPerYear,
            'pbo_blocks' => $pboBlocks,
        ])['result'] ?? [];
    }

    /**
     * Deflated Sharpe Ratio from its already-computed inputs.
     *
     * @return array<string,mixed>
     */
    public function deflatedSharpe(
        float $sr,
        int $nObs,
        float $skew,
        float $kurt,
        int $trials,
        float $varSharpeAcrossTrials,
    ): array {
        return $this->run([
            'operation' => 'deflated_sharpe',
            'sr' => $sr,
            'n_obs' => $nObs,
            'skew' => $skew,
            'kurt' => $kurt,
            'trials' => $trials,
            'var_sharpe' => $varSharpeAcrossTrials,
        ])['result'] ?? [];
    }

    /**
     * Deflated Sharpe Ratio straight from a returns series.
     *
     * @param  array<int,float>  $returns
     * @return array<string,mixed>
     */
    public function deflatedSharpeRatio(array $returns, int $trials, float $varSharpeAcrossTrials): array
    {
        return $this->run([
            'operation' => 'deflated_sharpe_returns',
            'returns' => array_values($returns),
            'trials' => $trials,
            'var_sharpe' => $varSharpeAcrossTrials,
        ])['result'] ?? [];
    }

    /**
     * Probability of Backtest Overfitting via CSCV.
     *
     * @param  array<int,array<int,float>>  $matrix
     * @return array<string,mixed>
     */
    public function pbo(array $matrix, int $blocks = 8): array
    {
        return $this->run([
            'operation' => 'pbo',
            'matrix' => array_map(static fn ($r): array => array_values($r), array_values($matrix)),
            'blocks' => $blocks,
        ])['result'] ?? [];
    }

    /**
     * Return-distribution moments (mean / std / skewness / kurtosis).
     *
     * @param  array<int,float>  $x
     * @return array<string,mixed>
     */
    public function moments(array $x): array
    {
        return $this->run([
            'operation' => 'moments',
            'x' => array_values($x),
        ])['result'] ?? [];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    private function run(array $manifest): array
    {
        $result = $this->runtime->run($manifest);

        // Boundary enforcement: a result is only accepted if it proves real,
        // in-Python numpy metrics. This is where a PHP fake would be rejected.
        PythonBoundaryReceiptGuard::assertReal(
            $result,
            ['honest_metrics_in_python', 'real_metrics'],
            ['fabricated'],
            'honest_metrics returned a non-real-metrics boundary receipt — refusing (anti-fake guard).',
        );

        return $result;
    }
}
