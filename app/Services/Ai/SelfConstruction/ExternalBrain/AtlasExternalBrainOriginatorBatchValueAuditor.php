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
    private const HIGH_COLLISION_RISK_THRESHOLD = 0.60;
    private const PADDING_SHARE_THRESHOLD = 0.50;
    private const WEAK_EVIDENCE_SCORE_THRESHOLD = 0.30;
    private const WEAK_EVIDENCE_SHARE_THRESHOLD = 0.50;

    /** recommendation => AC-facing decision vocabulary (keep/trim/reject/split). */
    private const RECOMMENDATION_TO_DECISION = [
        self::RECOMMENDATION_PROCEED => 'keep',
        self::RECOMMENDATION_TRIM_BATCH => 'trim',
        self::RECOMMENDATION_STOP_AND_RESEARCH => 'reject',
        self::RECOMMENDATION_PIVOT_THEME => 'split',
    ];

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
        $paddingCount = 0;
        $duplicateCount = 0;
        $weakEvidenceCount = 0;

        foreach ($tasks as $task) {
            $task = (array) $task;
            $theme = trim((string) ($task['theme'] ?? ''));
            if ($theme !== '') {
                $themeCounts[$theme] = ($themeCounts[$theme] ?? 0) + 1;
            }
            $isTestOnly = (bool) ($task['is_test_only'] ?? false);
            $isWrapperOnly = (bool) ($task['is_wrapper_only'] ?? false);
            if ($isTestOnly) {
                $testOnlyCount++;
            }
            if ($isWrapperOnly) {
                $wrapperOnlyCount++;
            }
            // AC1: padding — either explicitly flagged, or a task that is BOTH test-only and
            // wrapper-only (produces no real capability on its own, just structural noise).
            if ((bool) ($task['is_padding'] ?? false) || ($isTestOnly && $isWrapperOnly)) {
                $paddingCount++;
            }
            if (! (bool) ($task['has_runnable_proof'] ?? false)) {
                $missingProofCount++;
            }
            if (trim((string) ($task['duplicate_of'] ?? '')) !== '') {
                $duplicateCount++;
            }
            // Evidence strength defaults to "strong" when a task already has runnable proof and
            // simply never set the field — only an EXPLICIT low evidence_strength, or the
            // combination of no proof and no evidence at all, counts as weak.
            $hasRunnableProofForEvidence = (bool) ($task['has_runnable_proof'] ?? false);
            $evidenceStrengthDefault = $hasRunnableProofForEvidence ? 1.0 : 0.0;
            $evidenceStrength = max(0.0, min(1.0, (float) ($task['evidence_strength'] ?? $evidenceStrengthDefault)));
            if ($evidenceStrength < self::WEAK_EVIDENCE_SCORE_THRESHOLD) {
                $weakEvidenceCount++;
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
        $paddingShare = $paddingCount / $taskCount;
        $duplicateShare = $duplicateCount / $taskCount;
        $weakEvidenceShare = $weakEvidenceCount / $taskCount;

        $sameThemeVariantFlag = $dominantThemeShare >= self::SAME_THEME_SHARE_THRESHOLD;
        $testOnlyDominant = $testOnlyShare >= self::TEST_ONLY_SHARE_THRESHOLD;
        $wrapperOnlyDominant = $wrapperOnlyShare >= self::WRAPPER_ONLY_SHARE_THRESHOLD;
        $lowImpactDominant = $lowImpactDespitePassingShare >= self::LOW_IMPACT_SHARE_THRESHOLD;
        $paddingDominant = $paddingShare >= self::PADDING_SHARE_THRESHOLD;
        $duplicatePresent = $duplicateCount > 0;
        $weakEvidenceDominant = $weakEvidenceShare >= self::WEAK_EVIDENCE_SHARE_THRESHOLD;

        $lowValueReasons = array_values(array_filter([
            $sameThemeVariantFlag ? 'same_theme_variants_dominant' : null,
            $testOnlyDominant ? 'test_only_tasks_dominant' : null,
            $wrapperOnlyDominant ? 'wrapper_only_tasks_dominant' : null,
            $paddingDominant ? 'padding_tasks_dominant' : null,
            $duplicatePresent ? 'duplicate_value_present' : null,
            $missingProofShare > 0.0 ? 'tasks_missing_runnable_proof' : null,
            $weakEvidenceDominant ? 'weak_evidence_dominant' : null,
            $lowImpactDominant ? 'low_impact_despite_passing_structural_gates' : null,
        ]));
        $isLowValue = $lowValueReasons !== [];

        [$recommendation, $recommendationReasons] = match (true) {
            $missingProofShare >= 1.0 => [self::RECOMMENDATION_STOP_AND_RESEARCH, ['no_task_has_runnable_proof']],
            $missingProofShare > 0.0 => [self::RECOMMENDATION_TRIM_BATCH, ['missing_runnable_proof_tasks_present']],
            $sameThemeVariantFlag => [self::RECOMMENDATION_PIVOT_THEME, ['majority_same_theme_variants']],
            $weakEvidenceDominant => [self::RECOMMENDATION_STOP_AND_RESEARCH, ['weak_evidence_dominant']],
            $testOnlyDominant || $wrapperOnlyDominant || $paddingDominant => [self::RECOMMENDATION_TRIM_BATCH, ['test_only_wrapper_only_or_padding_dominant']],
            $duplicatePresent => [self::RECOMMENDATION_TRIM_BATCH, ['duplicate_value_present']],
            $lowImpactDominant => [self::RECOMMENDATION_STOP_AND_RESEARCH, ['low_impact_despite_passing_structural_gates']],
            default => [self::RECOMMENDATION_PROCEED, []],
        };

        $dominantTheme = null;
        if ($sameThemeVariantFlag && $themeCounts !== []) {
            $dominantTheme = array_search(max($themeCounts), $themeCounts, true);
        }

        [$trimmedTaskIds, $droppedTaskReasons] = $this->trimTasks(
            $tasks,
            $recommendation === self::RECOMMENDATION_TRIM_BATCH || $recommendation === self::RECOMMENDATION_PIVOT_THEME,
        );

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
            paddingShare: $paddingShare,
            duplicateShare: $duplicateShare,
            weakEvidenceShare: $weakEvidenceShare,
            trimmedTaskIds: $trimmedTaskIds,
            droppedTaskReasons: $droppedTaskReasons,
            dominantTheme: $dominantTheme,
        );
    }

    /**
     * Trims individual tasks with task-level keep/drop reasons — a batch is not merely
     * flagged low_value, low-value tasks are actually identified for removal.
     *
     * @param  list<mixed>  $tasks
     * @return array{0:list<string>, 1:array<string,string>}
     */
    private function trimTasks(array $tasks, bool $shouldTrim): array
    {
        if (! $shouldTrim) {
            return [[], []];
        }

        $trimmedTaskIds = [];
        $droppedTaskReasons = [];

        foreach ($tasks as $task) {
            $task = (array) $task;
            $taskId = (string) ($task['task_id'] ?? '');
            if ($taskId === '') {
                continue;
            }

            $isTestOnly = (bool) ($task['is_test_only'] ?? false);
            $isWrapperOnly = (bool) ($task['is_wrapper_only'] ?? false);
            $isPadding = (bool) ($task['is_padding'] ?? false) || ($isTestOnly && $isWrapperOnly);

            $reason = match (true) {
                ! (bool) ($task['has_runnable_proof'] ?? false) => 'missing_runnable_proof',
                trim((string) ($task['duplicate_of'] ?? '')) !== '' => 'duplicate_of_set',
                max(0.0, min(1.0, (float) ($task['collision_risk'] ?? 0.0))) > self::HIGH_COLLISION_RISK_THRESHOLD => 'high_collision_risk',
                $isPadding => 'padding',
                default => null,
            };

            if ($reason !== null) {
                $trimmedTaskIds[] = $taskId;
                $droppedTaskReasons[$taskId] = $reason;
            }
        }

        return [$trimmedTaskIds, $droppedTaskReasons];
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
        float $paddingShare = 0.0,
        float $duplicateShare = 0.0,
        float $weakEvidenceShare = 0.0,
        array $trimmedTaskIds = [],
        array $droppedTaskReasons = [],
        ?string $dominantTheme = null,
    ): array {
        return [
            'schema_version' => self::SCHEMA,
            'task_count' => count($tasks),
            'task_scores' => $taskScores,
            'low_value' => $isLowValue,
            'low_value_reasons' => $lowValueReasons,
            'padding_share' => round($paddingShare, 6),
            'duplicate_share' => round($duplicateShare, 6),
            'weak_evidence_share' => round($weakEvidenceShare, 6),
            'decision' => self::RECOMMENDATION_TO_DECISION[$recommendation] ?? 'reject',
            'dominant_theme_share' => round($dominantThemeShare, 6),
            'dominant_theme' => $dominantTheme,
            'test_only_share' => round($testOnlyShare, 6),
            'wrapper_only_share' => round($wrapperOnlyShare, 6),
            'missing_proof_share' => round($missingProofShare, 6),
            'low_impact_despite_passing_share' => round($lowImpactDespitePassingShare, 6),
            'recommendation' => $recommendation,
            'recommendation_reasons' => $recommendationReasons,
            'trimmed_task_ids' => $trimmedTaskIds,
            'dropped_task_reasons' => $droppedTaskReasons,
            'mutates_queue' => false,
        ];
    }

    /**
     * Compound proof audit: rewards tasks with downstream_unlocks, risk_reduction,
     * proof_strength and simplification_gain evidence. Flags structurally valid batches
     * as low_value when compound proof evidence is absent across most tasks.
     *
     * @param  list<mixed>  $tasks
     * @return array{compound_value_score:float, weak_compound_evidence_task_ids:list<string>, recommendation_reasons:list<string>}
     */
    public function compoundProofAudit(array $tasks): array
    {
        $compoundEvidenceKeys = ['downstream_unlocks', 'risk_reduction', 'proof_strength', 'simplification_gain'];
        $weakCompoundEvidenceTaskIds = [];
        $totalCompoundScore = 0.0;

        foreach ($tasks as $task) {
            $task = (array) $task;
            $taskId = (string) ($task['task_id'] ?? '');
            if ($taskId === '') {
                continue;
            }

            $taskCompoundScore = 0.0;
            foreach ($compoundEvidenceKeys as $key) {
                $val = (float) ($task[$key] ?? 0.0);
                $taskCompoundScore += max(0.0, min(1.0, $val));
            }

            $totalCompoundScore += $taskCompoundScore;

            // A task is weak if it has no compound evidence at all
            if ($taskCompoundScore <= 0.0) {
                $weakCompoundEvidenceTaskIds[] = $taskId;
            }
        }

        $taskCount = count($tasks);
        $compoundValueScore = $taskCount > 0 ? round($totalCompoundScore / ($taskCount * count($compoundEvidenceKeys)), 6) : 0.0;

        $recommendationReasons = [];
        if ($weakCompoundEvidenceTaskIds !== []) {
            $weakShare = count($weakCompoundEvidenceTaskIds) / max(1, $taskCount);
            if ($weakShare > 0.5) {
                $recommendationReasons[] = 'majority_tasks_lack_compound_proof_evidence';
            }
        }
        if ($compoundValueScore < 0.2 && $taskCount > 0) {
            $recommendationReasons[] = 'batch_compound_value_below_threshold';
        }

        return [
            'compound_value_score' => $compoundValueScore,
            'weak_compound_evidence_task_ids' => $weakCompoundEvidenceTaskIds,
            'recommendation_reasons' => $recommendationReasons,
        ];
    }
}
