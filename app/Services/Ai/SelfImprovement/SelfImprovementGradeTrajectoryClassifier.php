<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement;

final class SelfImprovementGradeTrajectoryClassifier
{
    /**
     * Trust-delta weights, mirroring AtlasSelfImprovementResultLedgerService::trustDeltaFor.
     *
     * @var array<string, float>
     */
    private const GRADE_WEIGHTS = [
        'major_improvement' => 1.0,
        'improved' => 0.4,
        'neutral' => 0.0,
        'regressed' => -0.5,
        'invalid' => -0.2,
    ];

    /**
     * @param  list<mixed>  $orderedGrades
     * @return array{
     *     trajectory: string,
     *     running_sum: float,
     *     longest_positive_streak: int,
     *     longest_regression_streak: int,
     *     sign_flips: int,
     *     scored_count: int
     * }
     */
    public function classify(array $orderedGrades): array
    {
        $runningSum = 0.0;
        $scoredCount = 0;
        $longestPositiveStreak = 0;
        $longestRegressionStreak = 0;
        $positiveStreak = 0;
        $regressionStreak = 0;
        $signFlips = 0;
        $previousSign = 0;

        foreach ($orderedGrades as $grade) {
            if (! is_string($grade) || ! array_key_exists($grade, self::GRADE_WEIGHTS)) {
                continue;
            }

            $score = self::GRADE_WEIGHTS[$grade];
            $runningSum += $score;
            $scoredCount++;

            if ($score > 0.0) {
                $positiveStreak++;
                $regressionStreak = 0;
                $longestPositiveStreak = max($longestPositiveStreak, $positiveStreak);
            } elseif ($score < 0.0) {
                $regressionStreak++;
                $positiveStreak = 0;
                $longestRegressionStreak = max($longestRegressionStreak, $regressionStreak);
            } else {
                $positiveStreak = 0;
                $regressionStreak = 0;
            }

            $sign = $score > 0.0 ? 1 : ($score < 0.0 ? -1 : 0);

            if ($sign !== 0) {
                if ($previousSign !== 0 && $sign !== $previousSign) {
                    $signFlips++;
                }

                $previousSign = $sign;
            }
        }

        return [
            'trajectory' => $this->trajectoryFor($runningSum, $signFlips),
            'running_sum' => $runningSum,
            'longest_positive_streak' => $longestPositiveStreak,
            'longest_regression_streak' => $longestRegressionStreak,
            'sign_flips' => $signFlips,
            'scored_count' => $scoredCount,
        ];
    }

    private function trajectoryFor(float $runningSum, int $signFlips): string
    {
        if ($runningSum < 0.0) {
            return 'regressing';
        }

        if ($runningSum > 0.0) {
            return $signFlips >= 2 ? 'thrashing' : 'compounding';
        }

        return 'flat';
    }
}
