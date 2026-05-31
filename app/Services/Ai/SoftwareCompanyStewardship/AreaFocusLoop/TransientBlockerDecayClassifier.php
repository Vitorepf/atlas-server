<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

final class TransientBlockerDecayClassifier
{
    /**
     * @return array{classification: string, decayed: bool, recurrences: int, budget: int, remaining: int}
     */
    public function classify(int $consecutiveRecurrences, int $transientBudget): array
    {
        $budget = max(1, $transientBudget);
        $recurrences = max(0, $consecutiveRecurrences);
        $remaining = max(0, $budget - $recurrences);
        $isTransient = $recurrences <= $budget;

        return [
            'classification' => $isTransient ? 'transient' : 'decayed_permanent',
            'decayed' => ! $isTransient,
            'recurrences' => $recurrences,
            'budget' => $budget,
            'remaining' => $remaining,
        ];
    }
}
