<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\StrategyLoop;

use App\Services\Ai\RuntimeBoundary\HonestMetricsRuntimeClient;

/**
 * THE HONESTY GATE — the post-selection judge that turns a raw "best Sharpe among N"
 * into either a certified-for-review proposal or an honest null. This is where the
 * loop's #1 overfitting risk is closed.
 *
 * The frozen judge scores ONE workspace, blind to how many siblings ran. So the trial
 * count N (= the engine's reported `scenarios_explored`) is INJECTED here and used to:
 *   1. DEFLATE the winner's Sharpe by N (the more scenarios explored, the higher the
 *      "best by luck" bar SR0 — searching harder makes the metric STRICTER, not easier);
 *   2. estimate PBO across the siblings' out-of-sample windows (is the in-sample winner
 *      actually overfit?);
 *   3. require the winner to survive the SEALED holdout it never optimized against.
 *
 * Certify only if DSR ≥ dsr_min AND PBO ≤ pbo_max AND the holdout stays positive. If any
 * fails, the honest output is "no candidate survived" — that null is the system working,
 * never a failure to paper over. Win-rate is never consulted.
 *
 * R7.3: the SENSITIVE statistical engine behind the DSR (with the Lo-2002 variance
 * floor that keeps N biting under clustered siblings — a prior audit fix), the PBO/CSCV
 * estimator, and the cross-trial Sharpe variance now run in the REAL Python numpy
 * runtime behind {@see HonestMetricsRuntimeClient}, proven equivalent to the removed
 * PHP within 1e-9. This gate is NOT on a synchronous HTTP hot path (one campaign
 * verdict), so the whole bundle is computed in ONE governed subprocess. The thresholds,
 * the sibling-diversity precondition, and the certify/null decision stay HERE in the
 * kernel — Python returns numbers, PHP governs.
 */
final class TradingHonestyGate
{
    public function __construct(private readonly HonestMetricsRuntimeClient $metrics = new HonestMetricsRuntimeClient) {}

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
     * @return array<string,mixed>
     */
    public function evaluate(array $input): array
    {
        $t = $input['thresholds'] ?? [];
        $dsrMin = (float) ($t['dsr_min'] ?? 0.95);
        $pboMax = (float) ($t['pbo_max'] ?? 0.2);
        $holdoutMinSharpe = (float) ($t['holdout_min_sharpe'] ?? 0.5); // a materially-positive OOS edge, NOT a >=0 sign test
        $holdoutMinTrades = (int) ($t['holdout_min_trades'] ?? 10);    // enough holdout bets that a coin flip can't pass it
        $minSiblings = (int) ($t['min_siblings'] ?? 3);                // DSR/PBO need real cross-sibling diversity to mean anything

        $returns = array_map('floatval', $input['winner_daily_returns'] ?? []);
        $n = max(1, (int) ($input['scenarios_explored'] ?? 1));
        $siblingSharpes = array_map('floatval', $input['sibling_sharpes'] ?? []);
        $siblingWindows = $this->cleanMatrix($input['sibling_windows'] ?? []);
        $holdoutSharpe = (float) ($input['holdout_sharpe'] ?? -INF);
        $holdoutTrades = (int) ($input['holdout_trades'] ?? 0);

        $reasons = [];
        if (count($returns) < 2) {
            $reasons[] = 'no_returns';
        }

        // ONE governed subprocess computes the whole honesty bundle in the REAL Python
        // numpy engine (var_sharpe = std²(sibling Sharpes), N-deflated DSR with the
        // Lo-2002 floor, PBO/CSCV over the sibling OOS windows, annualized scoring
        // Sharpe). No PHP fallback math — if the runtime is absent this throws (the
        // canon: a real engine or an honest failure, never a hand-rolled stand-in).
        $bundle = $this->metrics->honestyGate(
            $returns,
            $siblingSharpes,
            $siblingWindows,
            $n,
            365.0,
            8,
        );

        // variance of the Sharpe ESTIMATES across the N trials = the luck the search drew from.
        $varSharpe = (float) ($bundle['var_sharpe_across_trials'] ?? 0.0);
        $dsr = (float) ($bundle['deflated_sharpe'] ?? 0.0);
        $pbo = (float) ($bundle['pbo'] ?? 1.0);
        $scoringSharpe = (float) ($bundle['scoring_sharpe'] ?? 0.0);

        // Cross-sibling diversity is a precondition: with too few or identical siblings, neither
        // the DSR's variance term nor PBO carries signal. Reject honestly rather than certify on a
        // degenerate set (the convergent/singleton regime the audit exploited).
        $distinctSiblings = count(array_unique(array_map(
            static fn (float $s): string => (string) round($s, 6),
            $siblingSharpes,
        )));
        if (count($siblingSharpes) < $minSiblings || $distinctSiblings < 2) {
            $reasons[] = 'insufficient_sibling_diversity(n='.count($siblingSharpes).', distinct='.$distinctSiblings.', need>='.$minSiblings.')';
        }
        if ($dsr < $dsrMin) {
            $reasons[] = 'deflated_sharpe_too_low('.round($dsr, 4).'<'.$dsrMin.', N='.$n.')';
        }
        if ($pbo >= $pboMax) {
            $reasons[] = 'pbo_too_high('.round($pbo, 4).'>='.$pboMax.')';
        }
        if (! is_finite($holdoutSharpe) || $holdoutSharpe < $holdoutMinSharpe) {
            $reasons[] = 'holdout_not_positive('.(is_finite($holdoutSharpe) ? round($holdoutSharpe, 4) : 'nan').')';
        }
        if ($holdoutTrades < $holdoutMinTrades) {
            $reasons[] = 'holdout_too_few_trades('.$holdoutTrades.')';
        }

        $certified = $reasons === [];

        return [
            'certified' => $certified,
            'reasons' => $certified ? ['certified'] : $reasons,
            'report' => [
                'n_trials' => $n,
                'deflated_sharpe' => round($dsr, 6),
                'pbo' => round($pbo, 6),
                'scoring_sharpe' => round($scoringSharpe, 6),
                'holdout_sharpe' => is_finite($holdoutSharpe) ? round($holdoutSharpe, 6) : null,
                'holdout_trades' => $holdoutTrades,
                'var_sharpe_across_trials' => round($varSharpe, 8),
                'thresholds' => ['dsr_min' => $dsrMin, 'pbo_max' => $pboMax, 'holdout_min_sharpe' => $holdoutMinSharpe, 'holdout_min_trades' => $holdoutMinTrades],
            ],
        ];
    }

    /**
     * Keep only equal-length numeric rows (a ragged matrix can't be cross-validated).
     *
     * @param  array<int,mixed>  $matrix
     * @return list<list<float>>
     */
    private function cleanMatrix(array $matrix): array
    {
        $rows = [];
        $width = null;
        foreach ($matrix as $row) {
            if (! is_array($row)) {
                continue;
            }
            $row = array_map('floatval', array_values($row));
            $width ??= count($row);
            if (count($row) === $width && $width > 0) {
                $rows[] = $row;
            }
        }

        return $rows;
    }
}
