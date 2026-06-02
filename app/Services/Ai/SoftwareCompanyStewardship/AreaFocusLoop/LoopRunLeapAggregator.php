<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S139 — Loop Run Leap Aggregator.
 *
 * Pure, zero-constructor-dependency aggregator that rolls up a run's worth of
 * per-cycle quality verdicts (each one a CycleQualityScoreService::score()
 * output) into a single run-level judgement: did this run COMPOUND (produce at
 * least one leap), or was it merely valid-but-empty, or stalled?
 *
 * It never runs the loop, never invokes a provider, never reads the filesystem,
 * never touches a clock. Every returned field is COMPUTED from the supplied
 * per-cycle records via deterministic counting rules.
 *
 * Each element of $cycleScores is read for two booleans:
 *   - real_productive_merge: the cycle actually merged real engineering value;
 *   - counts_as_leap: the merge was a compounding leap (never true unless the
 *     cycle was also a real productive merge).
 *
 * Counting rules (<=4):
 *   1. valid_merge_count = number of elements with real_productive_merge === true.
 *   2. leap_count        = number of elements that are BOTH a real productive
 *                          merge AND counts_as_leap === true (a leap claim
 *                          without a real productive merge is never a leap).
 *   3. leap_ratio        = leap_count / valid_merge_count, rounded to 4 decimals;
 *                          0.0 when valid_merge_count is 0.
 *   4. run_verdict:
 *        - stalled_run         when valid_merge_count === 0;
 *        - valid_but_empty_run when valid_merge_count > 0 AND leap_count === 0;
 *        - compounding_run     when leap_count > 0.
 */
final class LoopRunLeapAggregator
{
    public const VERDICT_COMPOUNDING = 'compounding_run';

    public const VERDICT_VALID_BUT_EMPTY = 'valid_but_empty_run';

    public const VERDICT_STALLED = 'stalled_run';

    /**
     * @param  array<int, array<string, mixed>>  $cycleScores  per-cycle CycleQualityScoreService::score() outputs
     * @return array{leap_count: int, valid_merge_count: int, cycle_count: int, leap_ratio: float, run_verdict: string}
     */
    public function aggregate(array $cycleScores): array
    {
        $cycleCount = 0;
        $validMergeCount = 0;
        $leapCount = 0;

        foreach ($cycleScores as $cycleScore) {
            $cycleCount++;

            $record = is_array($cycleScore) ? $cycleScore : [];

            $realProductiveMerge = ($record['real_productive_merge'] ?? false) === true;
            $countsAsLeap = ($record['counts_as_leap'] ?? false) === true;

            if ($realProductiveMerge) {
                $validMergeCount++;
            }

            // A leap claim without a real productive merge is never a leap.
            if ($realProductiveMerge && $countsAsLeap) {
                $leapCount++;
            }
        }

        $leapRatio = $validMergeCount === 0
            ? 0.0
            : round($leapCount / $validMergeCount, 4);

        return [
            'leap_count' => $leapCount,
            'valid_merge_count' => $validMergeCount,
            'cycle_count' => $cycleCount,
            'leap_ratio' => $leapRatio,
            'run_verdict' => $this->verdict($validMergeCount, $leapCount),
        ];
    }

    private function verdict(int $validMergeCount, int $leapCount): string
    {
        if ($validMergeCount === 0) {
            return self::VERDICT_STALLED;
        }

        if ($leapCount === 0) {
            return self::VERDICT_VALID_BUT_EMPTY;
        }

        return self::VERDICT_COMPOUNDING;
    }
}
