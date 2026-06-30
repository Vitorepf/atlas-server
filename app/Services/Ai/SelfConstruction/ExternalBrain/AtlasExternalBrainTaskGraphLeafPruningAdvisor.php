<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Identifies low-leverage leaf tasks (no downstream unlocks) that should be
 * delayed, merged, or retired when the queue is already deep. Pure: it only
 * scores and recommends — it never mutates the queue itself.
 *
 * A task with has_downstream_unlocks=true is never a pruning candidate and
 * always keeps with reason has_downstream_unlocks_not_a_pruning_candidate.
 *
 * For leaf tasks (has_downstream_unlocks=false), leverage_score is:
 *   evidence_value * 0.4
 *   + maturity_gap_coverage * 0.4
 *   - implementation_effort * 0.1
 *   - duplication_risk * 0.1
 *   clamped to [0, 1]
 *
 * DECISION (first matching rule wins, in order):
 *   1. is_safety_or_certification=true AND evidence_value >= 0.6
 *      -> keep_leaf (preserved_high_evidence_safety_or_certification_leaf)
 *   2. duplication_risk >= 0.6
 *      -> merge_leaf (high_duplication_risk_merge_with_similar_task)
 *   3. leverage_score < 0.2
 *      -> retire_leaf (low_leverage_retire)
 *   4. leverage_score < 0.45
 *      -> delay_leaf (moderate_leverage_delay_until_queue_clears)
 *   5. otherwise
 *      -> keep_leaf (sufficient_leverage_keep)
 *
 * Rule 1 is checked first specifically so a high-evidence safety or
 * certification leaf is preserved even though it has no downstream
 * unlocks and would otherwise be pruned by rules 2-4.
 *
 * INPUT:
 *   tasks: list<{
 *     task_id:                    string
 *     has_downstream_unlocks?:    bool (default false)
 *     evidence_value?:            float (default 0.0)
 *     maturity_gap_coverage?:     float (default 0.0)
 *     implementation_effort?:     float (default 0.0)
 *     duplication_risk?:          float (default 0.0)
 *     is_safety_or_certification?: bool (default false)
 *   }>
 *
 * OUTPUT:
 *   { schema, recommendations: list<{task_id, recommendation, reason, leverage_score}> }
 *
 * Pure: no I/O, no queue mutation, no side effects.
 */
final class AtlasExternalBrainTaskGraphLeafPruningAdvisor
{
    public const SCHEMA = 'atlas.external_brain.task_graph_leaf_pruning_advisor.v1';

    private const SAFETY_EVIDENCE_THRESHOLD = 0.6;

    private const HIGH_DUPLICATION_RISK_THRESHOLD = 0.6;

    private const RETIRE_LEVERAGE_THRESHOLD = 0.2;

    private const DELAY_LEVERAGE_THRESHOLD = 0.45;

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function advise(array $input): array
    {
        $tasks = is_array($input['tasks'] ?? null) ? $input['tasks'] : [];

        $recommendations = [];
        foreach ($tasks as $task) {
            if (! is_array($task) || ! isset($task['task_id'])) {
                continue;
            }

            $taskId = (string) $task['task_id'];
            $hasDownstreamUnlocks = (bool) ($task['has_downstream_unlocks'] ?? false);

            if ($hasDownstreamUnlocks) {
                $recommendations[] = [
                    'task_id' => $taskId,
                    'recommendation' => 'keep_leaf',
                    'reason' => 'has_downstream_unlocks_not_a_pruning_candidate',
                    'leverage_score' => null,
                ];

                continue;
            }

            $evidenceValue = max(0.0, min(1.0, (float) ($task['evidence_value'] ?? 0.0)));
            $maturityGapCoverage = max(0.0, min(1.0, (float) ($task['maturity_gap_coverage'] ?? 0.0)));
            $implementationEffort = max(0.0, min(1.0, (float) ($task['implementation_effort'] ?? 0.0)));
            $duplicationRisk = max(0.0, min(1.0, (float) ($task['duplication_risk'] ?? 0.0)));
            $isSafetyOrCertification = (bool) ($task['is_safety_or_certification'] ?? false);

            $leverageScore = max(0.0, min(1.0,
                $evidenceValue * 0.4
                + $maturityGapCoverage * 0.4
                - $implementationEffort * 0.1
                - $duplicationRisk * 0.1,
            ));

            [$recommendation, $reason] = match (true) {
                $isSafetyOrCertification && $evidenceValue >= self::SAFETY_EVIDENCE_THRESHOLD => ['keep_leaf', 'preserved_high_evidence_safety_or_certification_leaf'],
                $duplicationRisk >= self::HIGH_DUPLICATION_RISK_THRESHOLD => ['merge_leaf', 'high_duplication_risk_merge_with_similar_task'],
                $leverageScore < self::RETIRE_LEVERAGE_THRESHOLD => ['retire_leaf', 'low_leverage_retire'],
                $leverageScore < self::DELAY_LEVERAGE_THRESHOLD => ['delay_leaf', 'moderate_leverage_delay_until_queue_clears'],
                default => ['keep_leaf', 'sufficient_leverage_keep'],
            };

            $recommendations[] = [
                'task_id' => $taskId,
                'recommendation' => $recommendation,
                'reason' => $reason,
                'leverage_score' => round($leverageScore, 4),
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'recommendations' => $recommendations,
        ];
    }
}
