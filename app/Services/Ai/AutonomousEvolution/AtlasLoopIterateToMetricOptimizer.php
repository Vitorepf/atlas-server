<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * The ARBOR ITERATE-TO-METRIC OPTIMIZER — the inner search of the evolution loop,
 * isolated and pure.
 *
 * It drives the Arbor algorithm on ONE already-prepared workspace, IN-PLACE:
 *   round 0..maxEdits-1: EDIT -> MEASURE -> keep-if-STRICTLY-better -> repeat,
 *   stopping on patience (no strict improvement for N rounds), on target reached,
 *   or on maxEdits exhausted.
 *
 * It is deliberately decoupled: it never touches a provider, a workspace, or git.
 * Those are injected as the $edit and $measure callables, so the search is a pure
 * function of "what the edit produced" and "what the measure returned".
 *
 * The strict-better comparison MIRRORS (by semantics, not by import — to avoid
 * coupling) the loop's improvesBest()/isStrictlyBetter():
 *   - a PASSING candidate always beats a FAILING one; a failing one never wins;
 *   - then metricKind direction (maximize=higher / minimize=lower / gate=tie);
 *   - a non-finite metric can NEVER win a maximize/minimize task.
 *
 * Anti-gaming note: this optimizer only ever sees what $measure returns. In the
 * loop, $measure runs the DEV (train) command — the held-out TEST command is never
 * visible here. The certifier proves the held-out gain separately.
 */
final class AtlasLoopIterateToMetricOptimizer
{
    public const METRIC_GATE = 'gate';

    public const METRIC_MINIMIZE = 'minimize';

    public const METRIC_MAXIMIZE = 'maximize';

    /**
     * Run the Arbor edit->measure->keep-if-better->repeat loop.
     *
     * @param  callable(int, array<string,mixed>|null): array<string,mixed>  $edit
     *                                                                              fn(int $round, ?array $lastVerdict): array — mutates the workspace,
     *                                                                              returns the attempt {diff_text, diff_size, cost_cents, tokens}.
     * @param  callable(): array<string,mixed>  $measure
     *                                                    fn(): array — returns a verdict {passed:bool, metric:float, metric_finite:bool}.
     * @param  string  $metricKind  'gate'|'maximize'|'minimize'
     * @param  float|null  $target  optional early-stop target (maximize: >=, minimize: <=, gate: passed)
     * @return array{
     *     best_verdict: array<string,mixed>|null,
     *     best_attempt: array<string,mixed>|null,
     *     rounds: int,
     *     converged: bool,
     *     target_reached: bool,
     *     history: list<array{round:int, metric:float, improved:bool, kept:bool}>,
     *     total_cost_cents: int,
     *     total_tokens: int
     * }
     */
    public function optimize(
        callable $edit,
        callable $measure,
        string $metricKind,
        ?float $target,
        int $maxEdits,
        int $patience,
    ): array {
        $metricKind = $this->normalizeKind($metricKind);
        $maxEdits = max(0, $maxEdits);
        $patience = max(1, $patience);

        $bestVerdict = null;
        $bestAttempt = null;
        $noImprove = 0;
        $rounds = 0;
        $converged = false;
        $targetReached = false;
        $totalCostCents = 0;
        $totalTokens = 0;
        $history = [];
        $lastVerdict = null;

        for ($round = 0; $round < $maxEdits; $round++) {
            $attempt = $this->normalizeAttempt(($edit)($round, $lastVerdict));
            $verdict = $this->normalizeVerdict(($measure)());
            $lastVerdict = $verdict;
            $rounds++;

            $totalCostCents += (int) $attempt['cost_cents'];
            $totalTokens += (int) $attempt['tokens'];

            $improved = $this->isStrictlyBetter($verdict, $bestVerdict, $metricKind);
            if ($improved) {
                $bestVerdict = $verdict;
                $bestAttempt = $attempt;
                $noImprove = 0;
            } else {
                $noImprove++;
            }

            $history[] = [
                'round' => $round,
                'metric' => (float) $verdict['metric'],
                'improved' => $improved,
                'kept' => $improved,
            ];

            // Target reached on the running best -> stop (the win is banked).
            if ($this->targetReached($bestVerdict, $metricKind, $target)) {
                $targetReached = true;
                break;
            }

            // Patience exhausted -> converged (no strict improvement for N rounds).
            if ($noImprove >= $patience) {
                $converged = true;
                break;
            }
        }

        return [
            'best_verdict' => $bestVerdict,
            'best_attempt' => $bestAttempt,
            'rounds' => $rounds,
            'converged' => $converged,
            'target_reached' => $targetReached,
            'history' => $history,
            'total_cost_cents' => $totalCostCents,
            'total_tokens' => $totalTokens,
        ];
    }

    /**
     * Is $candidate STRICTLY better than the running $best, under $metricKind?
     * MIRRORS AtlasEvolutionFrozenJudge::isStrictlyBetter + the improvesBest
     * metric_finite guard. The first candidate (best === null) wins iff admissible.
     *
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>|null  $best
     */
    private function isStrictlyBetter(array $candidate, ?array $best, string $metricKind): bool
    {
        $cPass = (bool) ($candidate['passed'] ?? false);

        // A failing candidate never wins (the loop's improvesBest opens with this).
        if (! $cPass) {
            return false;
        }

        // A candidate whose metric was clamped from ±INF can never win a
        // minimize/maximize task (inert for GATE, where the metric is always finite).
        if ($metricKind !== self::METRIC_GATE && ($candidate['metric_finite'] ?? true) === false) {
            return false;
        }

        if ($best === null) {
            return true; // first admissible candidate is the seed best.
        }

        $bPass = (bool) ($best['passed'] ?? false);
        if ($cPass !== $bPass) {
            return $cPass; // passing beats failing.
        }

        $cm = (float) ($candidate['metric'] ?? 0.0);
        $bm = (float) ($best['metric'] ?? 0.0);

        return match ($metricKind) {
            self::METRIC_MINIMIZE => $cm < $bm,
            self::METRIC_MAXIMIZE => $cm > $bm,
            default => false, // pure gate: two passes tie — no strict improvement.
        };
    }

    /**
     * Has the running best reached the early-stop condition?
     *   gate: passed ; maximize: metric >= target ; minimize: metric <= target.
     * A null target means "no target" -> never short-circuits maximize/minimize.
     *
     * @param  array<string,mixed>|null  $best
     */
    private function targetReached(?array $best, string $metricKind, ?float $target): bool
    {
        if ($best === null) {
            return false;
        }

        if ($metricKind === self::METRIC_GATE) {
            return (bool) ($best['passed'] ?? false);
        }

        if ($target === null) {
            return false;
        }

        // Only a finite-metric best can satisfy a numeric target.
        if (($best['metric_finite'] ?? true) === false) {
            return false;
        }

        $m = (float) ($best['metric'] ?? 0.0);

        return $metricKind === self::METRIC_MINIMIZE ? $m <= $target : $m >= $target;
    }

    /** @param array<string,mixed> $attempt */
    private function normalizeAttempt(array $attempt): array
    {
        return [
            'diff_text' => (string) ($attempt['diff_text'] ?? ''),
            'diff_size' => (int) ($attempt['diff_size'] ?? 0),
            'cost_cents' => max(0, (int) ($attempt['cost_cents'] ?? 0)),
            'tokens' => max(0, (int) ($attempt['tokens'] ?? 0)),
        ];
    }

    /** @param array<string,mixed> $verdict */
    private function normalizeVerdict(array $verdict): array
    {
        return [
            'passed' => (bool) ($verdict['passed'] ?? false),
            'metric' => (float) ($verdict['metric'] ?? 0.0),
            'metric_finite' => (bool) ($verdict['metric_finite'] ?? true),
        ];
    }

    private function normalizeKind(string $metricKind): string
    {
        return match ($metricKind) {
            self::METRIC_MINIMIZE => self::METRIC_MINIMIZE,
            self::METRIC_MAXIMIZE => self::METRIC_MAXIMIZE,
            default => self::METRIC_GATE,
        };
    }
}
