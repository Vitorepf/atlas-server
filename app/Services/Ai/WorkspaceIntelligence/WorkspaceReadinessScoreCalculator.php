<?php

declare(strict_types=1);

namespace App\Services\Ai\WorkspaceIntelligence;

final class WorkspaceReadinessScoreCalculator
{
    /**
     * Readiness weights keyed by the boolean contract field that contributes them.
     * Only a strict === true value for a key adds its weight; everything else adds 0.
     *
     * @var array<string, int>
     */
    private const READINESS_WEIGHTS = [
        'workspace_binding' => 4,
        'artifacts_at_minimum' => 3,
        'contracts_certified' => 3,
        'next_session_brain_ready' => 2,
        'raw_conversation_excluded' => 1,
    ];

    private const MIN_SCORE = 0;

    private const MAX_SCORE = 13;

    public function score(array $contracts): int
    {
        $total = self::MIN_SCORE;

        foreach (self::READINESS_WEIGHTS as $key => $weight) {
            if (($contracts[$key] ?? null) === true) {
                $total += $weight;
            }
        }

        return min(max($total, self::MIN_SCORE), self::MAX_SCORE);
    }
}
