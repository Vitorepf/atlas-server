<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class QuarantineEligibilityScorer
{
    /**
     * @return array{
     *     score: int,
     *     severity: string,
     *     components: array{
     *         severity_points: int,
     *         recurrence_points: int,
     *         repair_points: int,
     *         work_relief: int
     *     }
     * }
     */
    public function score(string $severity, int $recurrenceCount, bool $repairExhausted, bool $workProduced): array
    {
        $severityPoints = match ($severity) {
            'permanent' => 70,
            'post_repair' => 35,
            'transient' => 5,
            default => 20,
        };

        $boundedRecurrence = min(max($recurrenceCount, 0), 5);
        $recurrencePoints = $boundedRecurrence * 6;
        $repairPoints = $repairExhausted ? 20 : 0;
        $workRelief = $workProduced ? -15 : 0;

        $rawScore = $severityPoints + $recurrencePoints + $repairPoints + $workRelief;
        $score = min(max($rawScore, 0), 100);

        return [
            'score' => $score,
            'severity' => $severity,
            'components' => [
                'severity_points' => $severityPoints,
                'recurrence_points' => $recurrencePoints,
                'repair_points' => $repairPoints,
                'work_relief' => $workRelief,
            ],
        ];
    }
}
