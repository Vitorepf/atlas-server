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
     * Queue-pressure adjustment: high pressure retires/merges low-leverage duplicate
     * leaves faster (higher thresholds catch more candidates); low pressure delays
     * less aggressively and preserves moderate leaves instead of prematurely retiring.
     *
     * @var array<string,array{retire:float,delay:float,duplication:float}>
     */
    private const PRESSURE_THRESHOLDS = [
        'high' => ['retire' => 0.35, 'delay' => 0.60, 'duplication' => 0.40],
        'medium' => ['retire' => 0.20, 'delay' => 0.45, 'duplication' => 0.60],
        'low' => ['retire' => 0.05, 'delay' => 0.25, 'duplication' => 0.80],
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function advise(array $input): array
    {
        $tasks = is_array($input['tasks'] ?? null) ? $input['tasks'] : [];
        $queuePressure = strtolower(trim((string) ($input['queue_pressure'] ?? 'medium')));
        $thresholds = self::PRESSURE_THRESHOLDS[$queuePressure] ?? self::PRESSURE_THRESHOLDS['medium'];

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
                    'safe_pruning_evidence' => [],
                    'merge_target_hint' => null,
                    'retirement_blockers' => [],
                    'preserves_capability' => true,
                ];

                continue;
            }

            $evidenceValue = max(0.0, min(1.0, (float) ($task['evidence_value'] ?? 0.0)));
            $maturityGapCoverage = max(0.0, min(1.0, (float) ($task['maturity_gap_coverage'] ?? 0.0)));
            $implementationEffort = max(0.0, min(1.0, (float) ($task['implementation_effort'] ?? 0.0)));
            $duplicationRisk = max(0.0, min(1.0, (float) ($task['duplication_risk'] ?? 0.0)));
            $isSafetyOrCertification = (bool) ($task['is_safety_or_certification'] ?? false);
            $safePruningEvidence = array_values(array_filter(array_map(
                'strval',
                (array) ($task['safe_pruning_evidence'] ?? []),
            ), static fn (string $e): bool => $e !== ''));
            $mergeTargetHint = trim((string) ($task['merge_target_hint'] ?? ''));

            $leverageScore = max(0.0, min(1.0,
                $evidenceValue * 0.4
                + $maturityGapCoverage * 0.4
                - $implementationEffort * 0.1
                - $duplicationRisk * 0.1,
            ));

            [$recommendation, $reason] = match (true) {
                $isSafetyOrCertification && $evidenceValue >= self::SAFETY_EVIDENCE_THRESHOLD => ['keep_leaf', 'preserved_high_evidence_safety_or_certification_leaf'],
                $duplicationRisk >= $thresholds['duplication'] => ['merge_leaf', 'high_duplication_risk_merge_with_similar_task'],
                $leverageScore < $thresholds['retire'] => ['retire_leaf', 'low_leverage_retire'],
                $leverageScore < $thresholds['delay'] => ['delay_leaf', 'moderate_leverage_delay_until_queue_clears'],
                default => ['keep_leaf', 'sufficient_leverage_keep'],
            };

            // retire_leaf additionally requires concrete safe_pruning_evidence — a low leverage
            // score alone never proves it is safe to delete a leaf outright; without evidence
            // the leaf is delayed instead, and the missing requirement is named as a blocker.
            $retirementBlockers = [];
            if ($recommendation === 'retire_leaf' && $safePruningEvidence === []) {
                $recommendation = 'delay_leaf';
                $reason = 'low_leverage_but_missing_safe_pruning_evidence';
                $retirementBlockers[] = 'missing_safe_pruning_evidence';
            }

            // merge_leaf additionally requires merge_target_hint + safe_pruning_evidence to
            // proceed; without either, the leaf is delayed with named blockers so the
            // originator knows what evidence is needed before merging can be recommended.
            if ($recommendation === 'merge_leaf') {
                $mergeBlockers = [];
                if ($mergeTargetHint === '') {
                    $mergeBlockers[] = 'missing_merge_target_hint';
                }
                if ($safePruningEvidence === []) {
                    $mergeBlockers[] = 'missing_safe_pruning_evidence';
                }
                if ($mergeBlockers !== []) {
                    $recommendation = 'delay_leaf';
                    $reason = 'high_duplication_but_missing_merge_target_or_pruning_evidence';
                    $retirementBlockers = $mergeBlockers;
                }
            }

            $preservesCapability = in_array($recommendation, ['keep_leaf', 'merge_leaf', 'delay_leaf'], true);

            $recommendations[] = [
                'task_id' => $taskId,
                'recommendation' => $recommendation,
                'reason' => $reason,
                'leverage_score' => round($leverageScore, 4),
                'safe_pruning_evidence' => $safePruningEvidence,
                'merge_target_hint' => $recommendation === 'merge_leaf' ? ($mergeTargetHint !== '' ? $mergeTargetHint : null) : null,
                'retirement_blockers' => $retirementBlockers,
                'preserves_capability' => $preservesCapability,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'recommendations' => $recommendations,
        ];
    }
}
