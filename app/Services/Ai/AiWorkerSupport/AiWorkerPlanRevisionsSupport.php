<?php

declare(strict_types=1);

namespace App\Services\Ai\AiWorkerSupport;

/**
 * Pure plan-revision history append for programming repair replan (full-pass peel).
 */
final class AiWorkerPlanRevisionsSupport
{
    /**
     * @param  array<string,mixed>  $metadata
     * @return array<int,array<string,mixed>>
     */
    public static function afterReplan(
        array $metadata,
        int $iteration,
        string $reason,
        string $archivedAt,
        int $cap = 10,
    ): array {
        $revisions = array_values((array) ($metadata['plan_revisions'] ?? []));
        $currentPlan = $metadata['execution_plan'] ?? null;
        if (! is_array($currentPlan) || $currentPlan === []) {
            return $revisions;
        }

        $revisions[] = [
            'revision' => count($revisions) + 1,
            'iteration' => $iteration,
            'reason' => $reason,
            'archived_at' => $archivedAt,
            'execution_plan' => $currentPlan,
        ];

        return array_slice($revisions, -$cap);
    }
}
