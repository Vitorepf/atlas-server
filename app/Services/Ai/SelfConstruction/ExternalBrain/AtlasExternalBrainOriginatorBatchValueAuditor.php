<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure batch-value auditor. Scores an authored batch of task specs for
 * structural leverage, implementability, non-duplication, and expected
 * compounding value BEFORE the originator creates more tasks from it.
 *
 * A batch is flagged low_value when most tasks are same-theme variants,
 * test-only, wrapper-only, missing runnable proof, or low-impact despite
 * passing structural gates — the classic ways a batch looks "green" on
 * gates while delivering nothing compounding.
 *
 * Pure: no I/O, never mutates the task queue.
 */
final class AtlasExternalBrainOriginatorBatchValueAuditor
{
    public const SCHEMA = 'atlas.external_brain.originator_batch_value_auditor.v1';

    public const RECOMMENDATION_PROCEED = 'proceed';
    public const RECOMMENDATION_TRIM_BATCH = 'trim_batch';
    public const RECOMMENDATION_PIVOT_THEME = 'pivot_theme';
    public const RECOMMENDATION_STOP_AND_RESEARCH = 'stop_and_research';

    private const SAME_THEME_SHARE_THRESHOLD = 0.60;
    private const TEST_ONLY_SHARE_THRESHOLD = 0.50;
    private const WRAPPER_ONLY_SHARE_THRESHOLD = 0.50;
    private const LOW_IMPACT_SCORE_THRESHOLD = 0.30;
    private const LOW_IMPACT_SHARE_THRESHOLD = 0.50;

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function audit(array $facts): array
    {
        $tasks = array_values((array) ($facts['tasks'] ?? []));
        $taskCount = count($tasks);

        $taskScores = array_map(fn (array $task): array => $this->scoreTask((array) $task), $tasks);

        if ($taskCount === 0) {
            return $this->envelope([], $taskScores, [
                'proceed_blocked_empty_batch',
            ], self::RECOMMENDATION_STOP_AND_RESEARCH, ['empty_batch']);
        }

        $themeCounts = [];
        $testOnlyCount = 0;
        $wrapperOnlyCount = 0;
        $missingProofCount = 0;
        $lowImpactDespitePassingCount = 0;

        foreach ($tasks as $task) {
            $task = (array) $task;
            $theme = trim((string) ($task['theme'] ?? ''));
            if ($theme !== '') {
                $themeCounts[$theme] = ($themeCounts[$theme] ?? 0) + 1;
            }
            if ((bool) ($task['is_test_only'] ?? false)) {
                $testOnlyCount++;
            }
            if ((bool) ($task['is_wrapper_only'] ?? false)) {
                $wrapperOnlyCount++;
            }
            if (! (bool) ($task['has_runnable_proof'] ?? false)) {
                $missingProofCount++;
            }
            $structuralGatesPassed = (bool) ($task['structural_gates_passed'] ?? false);
            $impactScore = max(0.0, min(1.0, (float) ($task['impact_score'] ?? 0.0)));
            if ($structuralGatesPassed && $impactScore < self::LOW_IMPACT_SCORE_THRESHOLD) {
                $lowImpactDespitePassingCount++;
            }
        }

        $dominantThemeShare = $themeCounts === [] ? 0.0 : max($themeCounts) / $taskCount;
        $testOnlyShare = $testOnlyCount / $taskCount;
        $wrapperOnlyShare = $wrapperOnlyCount / $taskCount;
        $missingProofShare = $missingProofCount / $taskCount;
        $lowImpactDespitePassingShare = $lowImpactDespitePassingCount / $taskCount;

        $sameThemeVariantFlag = $dominantThemeShare >= self::SAME_THEME_SHARE_THRESHOLD;
        $testOnlyDominant = $testOnlyShare >= self::TEST_ONLY_SHARE_THRESHOLD;
        $wrapperOnlyDominant = $wrapperOnlyShare >= self::WRAPPER_ONLY_SHARE_THRESHOLD;
        $lowImpactDominant = $lowImpactDespitePassingShare >= self::LOW_IMPACT_SHARE_THRESHOLD;

        $lowValueReasons = array_values(array_filter([
            $sameThemeVariantFlag ? 'same_theme_variants_dominant' : null,
            $testOnlyDominant ? 'test_only_tasks_dominant' : null,
            $wrapperOnlyDominant ? 'wrapper_only_tasks_dominant' : null,
            $missingProofShare > 0.0 ? 'tasks_missing_runnable_proof' : null,
            $lowImpactDominant ? 'low_impact_despite_passing_structural_gates' : null,
        ]));
        $isLowValue = $lowValueReasons !== [];

        [$recommendation, $recommendationReasons] = match (true) {
            $missingProofShare >= 1.0 => [self::RECOMMENDATION_STOP_AND_RESEARCH, ['no_task_has_runnable_proof']],
            $missingProofShare > 0.0 => [self::RECOMMENDATION_TRIM_BATCH, ['missing_runnable_proof_tasks_present']],
            $sameThemeVariantFlag => [self::RECOMMENDATION_PIVOT_THEME, ['majority_same_theme_variants']],
            $testOnlyDominant || $wrapperOnlyDominant => [self::RECOMMENDATION_TRIM_BATCH, ['test_only_or_wrapper_only_dominant']],
            $lowImpactDominant => [self::RECOMMENDATION_STOP_AND_RESEARCH, ['low_impact_despite_passing_structural_gates']],
            default => [self::RECOMMENDATION_PROCEED, []],
        };

        return $this->envelope(
            $tasks,
            $taskScores,
            $lowValueReasons,
            $recommendation,
            $recommendationReasons,
            isLowValue: $isLowValue,
            dominantThemeShare: $dominantThemeShare,
            testOnlyShare: $testOnlyShare,
            wrapperOnlyShare: $wrapperOnlyShare,
            missingProofShare: $missingProofShare,
            lowImpactDespitePassingShare: $lowImpactDespitePassingShare,
        );
    }

    /** @param array<string,mixed> $task */
    private function scoreTask(array $task): array
    {
        $taskId = (string) ($task['task_id'] ?? '');
        $structuralGatesPassed = (bool) ($task['structural_gates_passed'] ?? false);
        $hasRunnableProof = (bool) ($task['has_runnable_proof'] ?? false);
        $impactScore = max(0.0, min(1.0, (float) ($task['impact_score'] ?? 0.0)));
        $evidenceStrength = max(0.0, min(1.0, (float) ($task['evidence_strength'] ?? 0.0)));
        $duplicateOf = trim((string) ($task['duplicate_of'] ?? ''));
        $collisionRisk = max(0.0, min(1.0, (float) ($task['collision_risk'] ?? 0.0)));

        $leverage = $impactScore;
        $implementability = ($structuralGatesPassed ? 0.6 : 0.0) + ($hasRunnableProof ? 0.4 : 0.0);
        $duplicationRisk = $duplicateOf !== '' ? 1.0 : 0.0;

        $compoundingValue = round(
            $leverage * $evidenceStrength * (1.0 - $duplicationRisk) * (1.0 - $collisionRisk),
            6,
        );

        return [
            'task_id' => $taskId,
            'leverage' => round($leverage, 6),
            'implementability' => round($implementability, 6),
            'evidence_strength' => round($evidenceStrength, 6),
            'collision_risk' => round($collisionRisk, 6),
            'duplication_risk' => round($duplicationRisk, 6),
            'compounding_value' => $compoundingValue,
        ];
    }

    /**
     * @param  list<mixed>  $tasks
     * @param  list<array<string,mixed>>  $taskScores
     * @param  list<string>  $lowValueReasons
     * @param  list<string>  $recommendationReasons
     * @return array<string,mixed>
     */
    private function envelope(
        array $tasks,
        array $taskScores,
        array $lowValueReasons,
        string $recommendation,
        array $recommendationReasons,
        bool $isLowValue = true,
        float $dominantThemeShare = 0.0,
        float $testOnlyShare = 0.0,
        float $wrapperOnlyShare = 0.0,
        float $missingProofShare = 0.0,
        float $lowImpactDespitePassingShare = 0.0,
    ): array {
        return [
            'schema_version' => self::SCHEMA,
            'task_count' => count($tasks),
            'task_scores' => $taskScores,
            'low_value' => $isLowValue,
            'low_value_reasons' => $lowValueReasons,
            'dominant_theme_share' => round($dominantThemeShare, 6),
            'test_only_share' => round($testOnlyShare, 6),
            'wrapper_only_share' => round($wrapperOnlyShare, 6),
            'missing_proof_share' => round($missingProofShare, 6),
            'low_impact_despite_passing_share' => round($lowImpactDespitePassingShare, 6),
            'recommendation' => $recommendation,
            'recommendation_reasons' => $recommendationReasons,
            'mutates_queue' => false,
        ];
    }
}
