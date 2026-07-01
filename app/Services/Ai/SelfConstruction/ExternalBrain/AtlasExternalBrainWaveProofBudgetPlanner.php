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
 *                                    risk-derived minimum proofs + blast-radius/weak-model checks)
 *
 * Allocation also grows with:
 *   - refactor_blast_radius (int, consumers/files touched): a wide-blast refactor earns extra
 *     consumer_impact/rollback_plan checks and budget headroom — a flat evidence floor is never
 *     enough once behavior-preservation must be proven across many callers.
 *   - model_weakness_score (float 0-1): a task routed to a muscle known to be weak on this class
 *     of work earns an extra collision_sweep check — the model's own track record is a risk input.
 *   - expected_leverage (float 0-1): a high-leverage task earns extra budget headroom, since it is
 *     worth deeper proof rather than being throttled by the flat per-task ceiling.
 *
 * A task is over budget when:
 *   - its required_checks exceed the risk/blast/leverage-adjusted budget (still capped at
 *     ABSOLUTE_MAX_CHECKS_PER_TASK), OR
 *   - required_evidence is empty (the task asks for proof but names none — refused, never silently
 *     treated as "no evidence needed").
 *
 * recommended_action per over-budget task/wave:
 *   - missing_evidence            → 'strengthen' (name at least one required_evidence entry)
 *   - too_broad + high blast radius + weak model → 'defer' (compounding risk: wait for a
 *     stronger muscle or a smaller scope, splitting alone will not fix it)
 *   - too_broad (otherwise)       → 'split'
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

    public const ACTION_SPLIT = 'split';
    public const ACTION_STRENGTHEN = 'strengthen';
    public const ACTION_DEFER = 'defer';

    private const BASE_MAX_CHECKS_PER_TASK = 6;
    private const ABSOLUTE_MAX_CHECKS_PER_TASK = 12;
    private const CHECK_BUDGET_PER_MUSCLE = 10;
    private const DEFAULT_PER_MUSCLE_MINUTE_BUDGET = 45.0;
    private const DEFAULT_MINUTES_PER_CHECK = 5.0;

    private const HIGH_BLAST_RADIUS_THRESHOLD = 3;
    private const WEAK_MODEL_THRESHOLD = 0.6;
    private const HIGH_LEVERAGE_THRESHOLD = 0.7;
    private const BLAST_RADIUS_BUDGET_BONUS = 2;
    private const LEVERAGE_BUDGET_BONUS = 1;

    /** Risk-adjusted proof budget multiplier: higher risk earns a larger (but still capped) budget. */
    private const RISK_BUDGET_MULTIPLIER = [
        'low' => 1.0,
        'medium' => 1.5,
        'high' => 2.0,
    ];

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
        $splitCandidates = [];
        $missingEvidenceTasks = [];
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
            $blastRadius = max(0, (int) ($task['refactor_blast_radius'] ?? 0));
            $modelWeaknessScore = max(0.0, min(1.0, (float) ($task['model_weakness_score'] ?? 0.0)));
            $expectedLeverage = max(0.0, min(1.0, (float) ($task['expected_leverage'] ?? 0.0)));
            $highBlastRadius = $blastRadius >= self::HIGH_BLAST_RADIUS_THRESHOLD;
            $modelWeak = $modelWeaknessScore >= self::WEAK_MODEL_THRESHOLD;
            $highLeverage = $expectedLeverage >= self::HIGH_LEVERAGE_THRESHOLD;

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
            if ($highBlastRadius) {
                $checks[] = 'proof:consumer_impact';
                $checks[] = 'proof:rollback_plan';
            }
            if ($modelWeak) {
                $checks[] = 'proof:collision_sweep';
            }
            $checks = array_values(array_unique($checks));

            $missingEvidence = $requiredEvidence === [];
            $riskBudget = (int) min(
                self::ABSOLUTE_MAX_CHECKS_PER_TASK,
                round(self::BASE_MAX_CHECKS_PER_TASK * (self::RISK_BUDGET_MULTIPLIER[$riskLevel] ?? 1.0))
                    + ($highBlastRadius ? self::BLAST_RADIUS_BUDGET_BONUS : 0)
                    + ($highLeverage ? self::LEVERAGE_BUDGET_BONUS : 0),
            );
            $tooBroad = count($checks) > $riskBudget;

            $estimatedMinutes = array_key_exists('estimated_test_minutes', $task)
                ? max(0.0, (float) $task['estimated_test_minutes'])
                : round(count($checks) * self::DEFAULT_MINUTES_PER_CHECK, 4);

            $waveTotalEstimatedMinutes += $estimatedMinutes;
            $waveTotalChecks += count($checks);

            $recommendedAction = match (true) {
                $missingEvidence => self::ACTION_STRENGTHEN,
                $tooBroad && $highBlastRadius && $modelWeak => self::ACTION_DEFER,
                $tooBroad => self::ACTION_SPLIT,
                default => null,
            };

            $perTaskRequiredChecks[] = [
                'task_id' => $taskId,
                'required_checks' => $checks,
                'check_count' => count($checks),
                'estimated_minutes' => $estimatedMinutes,
                'risk_level' => $riskLevel,
                'risk_adjusted_budget' => $riskBudget,
                'affected_file_families' => $affectedFileFamilies,
                'refactor_blast_radius' => $blastRadius,
                'model_weakness_score' => $modelWeaknessScore,
                'expected_leverage' => $expectedLeverage,
                'missing_evidence' => $missingEvidence,
                'too_broad' => $tooBroad,
                'recommended_action' => $recommendedAction,
            ];

            if ($missingEvidence) {
                $missingEvidenceTasks[] = $taskId;
            }
            if ($tooBroad) {
                $splitCandidates[] = $taskId;
            }

            if ($missingEvidence || $tooBroad) {
                $overBudgetTasks[] = $taskId;

                $reasons = array_values(array_filter([
                    $missingEvidence ? 'required_evidence_is_empty' : null,
                    $tooBroad ? sprintf('required_checks=%d_exceeds_risk_adjusted_budget=%d', count($checks), $riskBudget) : null,
                ]));

                $recommendation = match ($recommendedAction) {
                    self::ACTION_STRENGTHEN => "add at least one required_evidence entry for task {$taskId} before it can be accepted",
                    self::ACTION_DEFER => sprintf('defer task %s (required_checks=%d exceeds budget=%d, high blast radius=%d, weak model=%.2f) — split alone will not fix compounding risk', $taskId, count($checks), $riskBudget, $blastRadius, $modelWeaknessScore),
                    default => sprintf('split task %s (required_checks=%d exceeds risk-adjusted budget=%d) into a follow-up task instead of dispatching it as-is', $taskId, count($checks), $riskBudget),
                };

                $proofSlimmingRecommendations[] = [
                    'task_id' => $taskId,
                    'reasons' => $reasons,
                    'recommended_action' => $recommendedAction,
                    'recommendation' => $recommendation,
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
            'wave_slimming_plan' => [
                'split_candidates' => array_values(array_unique($splitCandidates)),
                'missing_evidence_tasks' => array_values(array_unique($missingEvidenceTasks)),
                'wave_over_budget' => $waveExceedsCheckBudget || $waveExceedsMinuteBudget,
            ],
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
