<?php

namespace App\Services\Ai\PersonalDevelopment;

class PersonalDevelopmentFlowCatalog
{
    /**
     * @var array<string,array<string,mixed>>
     */
    private const FLOWS = [
        'personal_development.reflect' => [
            'type' => 'reflection_plan',
            'cadence' => 'ad_hoc',
            'risk' => 'low',
            'artifact' => 'reflection_brief',
            'focus' => ['observation', 'interpretation', 'evidence', 'next_experiment'],
        ],
        'personal_development.daily_review' => [
            'type' => 'daily_review_plan',
            'cadence' => 'daily',
            'risk' => 'low',
            'artifact' => 'daily_review_summary',
            'focus' => ['wins', 'friction', 'energy', 'focus', 'tomorrow_experiment'],
        ],
        'personal_development.weekly_review' => [
            'type' => 'weekly_review_plan',
            'cadence' => 'weekly',
            'risk' => 'low',
            'artifact' => 'weekly_review_summary',
            'focus' => ['goals', 'routines', 'learning', 'energy', 'adjustments'],
        ],
        'personal_development.habit_design' => [
            'type' => 'habit_experiment_plan',
            'cadence' => 'experiment',
            'risk' => 'low',
            'artifact' => 'habit_experiment_card',
            'focus' => ['cue', 'minimum_action', 'friction', 'reward', 'review_signal'],
        ],
        'personal_development.focus_plan' => [
            'type' => 'focus_block_plan',
            'cadence' => 'daily',
            'risk' => 'low',
            'artifact' => 'focus_block_brief',
            'focus' => ['priority', 'attention_budget', 'interruption_policy', 'shutdown_rule'],
        ],
        'personal_development.learning_plan' => [
            'type' => 'learning_experiment_plan',
            'cadence' => 'weekly',
            'risk' => 'low',
            'artifact' => 'learning_loop_plan',
            'focus' => ['skill_target', 'practice_loop', 'feedback_source', 'evidence_of_progress'],
        ],
        'personal_development.energy_review' => [
            'type' => 'energy_evidence_plan',
            'cadence' => 'daily',
            'risk' => 'medium_if_sensitive',
            'artifact' => 'energy_pattern_review',
            'focus' => ['load', 'recovery', 'environment', 'routine_pattern', 'experiment'],
        ],
        'personal_development.goal_decomposition' => [
            'type' => 'goal_decomposition_plan',
            'cadence' => 'milestone',
            'risk' => 'low',
            'artifact' => 'goal_breakdown',
            'focus' => ['outcome', 'projects', 'next_actions', 'risks', 'review_signal'],
        ],
        'personal_development.recovery_plan' => [
            'type' => 'recovery_routine_plan',
            'cadence' => 'daily',
            'risk' => 'medium_if_sensitive',
            'artifact' => 'recovery_routine_brief',
            'focus' => ['load_boundary', 'rest_routine', 'energy_signal', 'adjustment_checkpoint'],
        ],
        'personal_development.forge' => [
            'type' => 'integrated_personal_operating_plan',
            'cadence' => 'weekly',
            'risk' => 'review_required',
            'artifact' => 'personal_operating_system_brief',
            'focus' => ['goals', 'habits', 'focus', 'learning', 'energy', 'recovery', 'review_loop'],
        ],
    ];

    /**
     * @return array<int,string>
     */
    public static function ids(): array
    {
        return array_keys(self::FLOWS);
    }

    public static function has(string $flow): bool
    {
        return array_key_exists($flow, self::FLOWS);
    }

    /**
     * @return array<string,mixed>
     */
    public static function get(string $flow): array
    {
        if (! self::has($flow)) {
            throw new \InvalidArgumentException("Unsupported personal development flow [{$flow}].");
        }

        return self::FLOWS[$flow];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public static function all(): array
    {
        return self::FLOWS;
    }
}
