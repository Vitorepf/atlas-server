<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Composes the four muscle-assignment organs into a single governance runner:
 *   1. MuscleReadinessContract::check() — is the task spec ready for dispatch?
 *   2. MuscleSkillFitRouter::route() — which muscles fit the task best?
 *   3. MusclePromptVariantSelector::select() — which prompt variant for each muscle?
 *   4. MuscleThroughputFairnessBalancer::balance() — is the distribution fair?
 *
 * The runner is pure/read-only: it never dispatches, never claims, never
 * mutates leases. It produces an assignment plan that a caller can act on.
 *
 * OUTPUT:
 *   { schema, status, ready, readiness, routing, prompt_variants, fairness,
 *     assigned_muscles, excluded_muscles, blockers }
 *
 * Pure / deterministic. No I/O, no provider calls.
 */
final class AtlasExternalBrainMuscleAssignmentGovernanceRunner
{
    public const SCHEMA = 'atlas.external_brain.muscle_assignment_governance_runner.v1';

    public function __construct(
        private readonly AtlasExternalBrainMuscleReadinessContract $readinessContract = new AtlasExternalBrainMuscleReadinessContract,
        private readonly AtlasExternalBrainMuscleSkillFitRouter $skillFitRouter = new AtlasExternalBrainMuscleSkillFitRouter,
        private readonly AtlasExternalBrainMusclePromptVariantSelector $promptVariantSelector = new AtlasExternalBrainMusclePromptVariantSelector,
        private readonly AtlasExternalBrainMuscleThroughputFairnessBalancer $throughputFairnessBalancer = new AtlasExternalBrainMuscleThroughputFairnessBalancer,
    ) {}

    /**
     * @param  array<string, mixed>  $taskSpec
     * @param  array<string, mixed>  $routingInput
     * @param  array<string, mixed>  $fairnessInput
     * @return array<string, mixed>
     */
    public function govern(array $taskSpec, array $routingInput, array $fairnessInput): array
    {
        // 1. Readiness check — is the task spec ready for dispatch?
        $readiness = $this->readinessContract->check($taskSpec);
        $ready = (bool) ($readiness['ready'] ?? false);
        $blockers = [];
        $assignedMuscles = [];
        $excludedMuscles = [];
        $routing = [];
        $promptVariants = [];
        $fairness = [];

        if (! $ready) {
            $blockers = (array) ($readiness['blocking_deficiencies'] ?? []);
            $status = 'not_ready';
        } else {
            $status = 'ready';

            // 2. Skill-fit routing — rank candidate muscles.
            $routing = $this->skillFitRouter->route($routingInput);
            $rankedMuscles = (array) ($routing['ranked_muscles'] ?? []);

            // 3. Prompt variant selection — pick the right prompt for each muscle.
            $muscleType = (string) ($routingInput['muscle_type'] ?? 'external_muscle');
            $riskLevel = (string) ($taskSpec['risk_level'] ?? 'low');
            $taskMode = (string) ($taskSpec['task_mode'] ?? 'normal');
            $contextSize = (string) ($taskSpec['context_size'] ?? 'normal');

            foreach ($rankedMuscles as $muscle) {
                $muscleId = (string) ($muscle['muscle_id'] ?? '');
                $fitScore = (float) ($muscle['fit_score'] ?? 0.0);
                $riskReasons = (array) ($muscle['risk_reasons'] ?? []);

                $variant = $this->promptVariantSelector->select([
                    'muscle_type' => $muscleType,
                    'risk_level' => $riskLevel,
                    'context_size' => $contextSize,
                    'task_mode' => $taskMode,
                ]);

                $promptVariants[] = [
                    'muscle_id' => $muscleId,
                    'prompt_variant_id' => $variant['prompt_variant_id'] ?? '',
                    'included_sections' => $variant['included_sections'] ?? [],
                    'guardrails' => $variant['guardrails'] ?? [],
                ];

                // Exclude muscles with critical risk reasons or zero fit score.
                if ($fitScore <= 0.0 || in_array('risk_level_exceeds_max', $riskReasons, true)) {
                    $excludedMuscles[] = [
                        'muscle_id' => $muscleId,
                        'reason' => 'low_fit_or_risk_mismatch',
                        'fit_score' => $fitScore,
                    ];
                } else {
                    $assignedMuscles[] = [
                        'muscle_id' => $muscleId,
                        'fit_score' => $fitScore,
                        'rank' => (int) ($muscle['rank'] ?? 0),
                        'expected_success_confidence' => (float) ($muscle['expected_success_confidence'] ?? 0.0),
                    ];
                }
            }

            // 4. Throughput fairness — is the distribution balanced?
            $fairness = $this->throughputFairnessBalancer->balance($fairnessInput);
        }

        return [
            'schema' => self::SCHEMA,
            'status' => $status,
            'ready' => $ready,
            'readiness' => $readiness,
            'routing' => $routing,
            'prompt_variants' => $promptVariants,
            'fairness' => $fairness,
            'assigned_muscles' => $assignedMuscles,
            'excluded_muscles' => $excludedMuscles,
            'blockers' => $blockers,
        ];
    }
}
