<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Gives every executable wave an explicit proof BUDGET, so a wave never overloads its muscles
 * with broad suites or accepts weak (missing) evidence. Composes
 * AtlasExternalBrainImplementationProofDemand to derive the risk-proportional minimum proof per
 * task, then adds the task's own acceptance_criteria and required_evidence as explicit checks.
 *
 * per_task_required_checks = unique(acceptance_criteria checks + required_evidence checks +
 *                                    risk-derived minimum proofs)
 *
 * A task is over budget when:
 *   - its required_checks exceed MAX_CHECKS_PER_TASK (the gate is simply too broad for one task), OR
 *   - required_evidence is empty (the task asks for proof but names none — refused, never silently
 *     treated as "no evidence needed").
 *
 * The WAVE is proof_over_budget when:
 *   - any task is individually over budget, OR
 *   - total required checks exceed muscle_count * CHECK_BUDGET_PER_MUSCLE, OR
 *   - total estimated minutes exceed muscle_count * per_muscle_minute_budget.
 *
 * Pure: no I/O, no muscle dispatch, no queue mutation.
 */
final class AtlasExternalBrainWaveProofBudgetPlanner
{
    public const SCHEMA = 'atlas.external_brain.wave_proof_budget_planner.v1';

    public const STATUS_WITHIN_BUDGET = 'within_budget';
    public const STATUS_PROOF_OVER_BUDGET = 'proof_over_budget';

    private const MAX_CHECKS_PER_TASK = 6;
    private const CHECK_BUDGET_PER_MUSCLE = 10;
    private const DEFAULT_PER_MUSCLE_MINUTE_BUDGET = 45.0;
    private const DEFAULT_MINUTES_PER_CHECK = 5.0;

    public function __construct(
        private readonly AtlasExternalBrainImplementationProofDemand $proofDemand = new AtlasExternalBrainImplementationProofDemand,
    ) {}

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    public function plan(array $facts): array
    {
        $waveTasks = is_array($facts['wave_tasks'] ?? null) ? array_values($facts['wave_tasks']) : [];
        $muscleCount = max(1, (int) ($facts['muscle_count'] ?? 1));
        $perMuscleMinuteBudget = max(0.0, (float) ($facts['per_muscle_minute_budget'] ?? self::DEFAULT_PER_MUSCLE_MINUTE_BUDGET));

        $perTaskRequiredChecks = [];
        $overBudgetTasks = [];
        $proofSlimmingRecommendations = [];
        $waveTotalEstimatedMinutes = 0.0;
        $waveTotalChecks = 0;

        foreach ($waveTasks as $task) {
            if (! is_array($task)) {
                continue;
            }

            $taskId = (string) ($task['task_id'] ?? '');
            $riskLevel = (string) ($task['risk_level'] ?? 'low');
            $acceptanceCriteria = $this->stringList($task['acceptance_criteria'] ?? []);
            $requiredEvidence = $this->stringList($task['required_evidence'] ?? []);
            $affectedFileFamilies = $this->stringList($task['affected_file_families'] ?? []);

            $minimumProofs = (array) ($this->proofDemand->derive(['task' => ['risk_level' => $riskLevel]])['required_proofs'] ?? []);

            $checks = [];
            foreach ($acceptanceCriteria as $criterion) {
                $checks[] = 'acceptance:'.$criterion;
            }
            foreach ($requiredEvidence as $evidence) {
                $checks[] = 'evidence:'.$evidence;
            }
            foreach ($minimumProofs as $proofType) {
                $checks[] = 'proof:'.$proofType;
            }
            $checks = array_values(array_unique($checks));

            $missingEvidence = $requiredEvidence === [];
            $tooBroad = count($checks) > self::MAX_CHECKS_PER_TASK;

            $estimatedMinutes = array_key_exists('estimated_test_minutes', $task)
                ? max(0.0, (float) $task['estimated_test_minutes'])
                : round(count($checks) * self::DEFAULT_MINUTES_PER_CHECK, 4);

            $waveTotalEstimatedMinutes += $estimatedMinutes;
            $waveTotalChecks += count($checks);

            $perTaskRequiredChecks[] = [
                'task_id' => $taskId,
                'required_checks' => $checks,
                'check_count' => count($checks),
                'estimated_minutes' => $estimatedMinutes,
                'risk_level' => $riskLevel,
                'affected_file_families' => $affectedFileFamilies,
                'missing_evidence' => $missingEvidence,
                'too_broad' => $tooBroad,
            ];

            if ($missingEvidence || $tooBroad) {
                $overBudgetTasks[] = $taskId;

                $reasons = array_values(array_filter([
                    $missingEvidence ? 'required_evidence_is_empty' : null,
                    $tooBroad ? sprintf('required_checks=%d_exceeds_max=%d', count($checks), self::MAX_CHECKS_PER_TASK) : null,
                ]));

                $proofSlimmingRecommendations[] = [
                    'task_id' => $taskId,
                    'reasons' => $reasons,
                    'recommendation' => $missingEvidence
                        ? "add at least one required_evidence entry for task {$taskId} before it can be accepted"
                        : sprintf('trim task %s required_checks from %d to <= %d before this wave runs (split into a follow-up task instead)', $taskId, count($checks), self::MAX_CHECKS_PER_TASK),
                ];
            }
        }

        $checkBudget = $muscleCount * self::CHECK_BUDGET_PER_MUSCLE;
        $minuteBudget = $muscleCount * $perMuscleMinuteBudget;

        $waveExceedsCheckBudget = $waveTotalChecks > $checkBudget;
        $waveExceedsMinuteBudget = $waveTotalEstimatedMinutes > $minuteBudget;

        if ($waveExceedsCheckBudget || $waveExceedsMinuteBudget) {
            $proofSlimmingRecommendations[] = [
                'task_id' => null,
                'reasons' => array_values(array_filter([
                    $waveExceedsCheckBudget ? sprintf('wave_total_checks=%d_exceeds_check_budget=%d', $waveTotalChecks, $checkBudget) : null,
                    $waveExceedsMinuteBudget ? sprintf('wave_total_estimated_minutes=%.1f_exceeds_minute_budget=%.1f', $waveTotalEstimatedMinutes, $minuteBudget) : null,
                ])),
                'recommendation' => 'reduce wave size or increase muscle_count before dispatching this wave',
            ];
        }

        $proofBudgetStatus = ($overBudgetTasks !== [] || $waveExceedsCheckBudget || $waveExceedsMinuteBudget)
            ? self::STATUS_PROOF_OVER_BUDGET
            : self::STATUS_WITHIN_BUDGET;

        return [
            'schema' => self::SCHEMA,
            'per_task_required_checks' => $perTaskRequiredChecks,
            'wave_total_estimated_minutes' => round($waveTotalEstimatedMinutes, 4),
            'wave_total_checks' => $waveTotalChecks,
            'muscle_count' => $muscleCount,
            'check_budget' => $checkBudget,
            'minute_budget' => $minuteBudget,
            'proof_budget_status' => $proofBudgetStatus,
            'over_budget_tasks' => array_values(array_unique($overBudgetTasks)),
            'proof_slimming_recommendations' => $proofSlimmingRecommendations,
            'mutates_queue' => false,
        ];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $value), static fn (string $s): bool => $s !== ''));
    }
}
