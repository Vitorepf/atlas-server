<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure prompt-variant selector. Picks a compact prompt contract variant for a given
 * muscle type, task risk, and context-size limitation, instead of stuffing one
 * generic mega-prompt into every worker regardless of what it can actually use.
 *
 * INPUT:
 *   muscle_type:  string  — external_muscle|local_subscription_client (default external_muscle)
 *   risk_level:   string  — low|medium|high (default low)
 *   context_size: string  — low|normal (default normal) — low = small-context/cheap model
 *   task_mode:    string  — normal|recovery|give_back (default normal)
 *
 * VARIANT PRIORITY (first match wins — a recovery task always gets the recovery prompt,
 * regardless of muscle type or risk):
 *   recovery_give_back_task — task_mode is recovery or give_back
 *   high_risk_task          — risk_level is high
 *   low_context_task        — context_size is low
 *   local_subscription_client — muscle_type is local_subscription_client
 *   external_muscle          — default
 *
 * GUARDRAILS (always present): allowed_files_only, no_manual_git_commands,
 *   prove_before_report, give_back_on_duplicate.
 *   + no_paid_api_dependency when muscle_type is local_subscription_client.
 *
 * OUTPUT:
 *   { schema, prompt_variant_id, included_sections, omitted_sections, guardrails, reason }
 *
 * Pure / deterministic. No I/O, no provider calls.
 */
final class AtlasExternalBrainMusclePromptVariantSelector
{
    public const SCHEMA = 'atlas.external_brain.muscle_prompt_variant_selector.v1';

    public const VARIANT_EXTERNAL_MUSCLE          = 'external_muscle';
    public const VARIANT_LOCAL_SUBSCRIPTION_CLIENT = 'local_subscription_client';
    public const VARIANT_HIGH_RISK_TASK           = 'high_risk_task';
    public const VARIANT_LOW_CONTEXT_TASK         = 'low_context_task';
    public const VARIANT_RECOVERY_GIVE_BACK_TASK  = 'recovery_give_back_task';

    /** Every possible prompt section, used to derive omitted_sections from included_sections. */
    private const ALL_SECTIONS = [
        'objective',
        'allowed_files',
        'acceptance_criteria',
        'required_evidence',
        'full_codebase_context',
        'architecture_rationale',
        'risk_review_checklist',
        'dual_review_requirement',
        'provider_api_usage_notes',
        'recovery_diagnosis_steps',
        'duplicate_detection_steps',
        'minimal_scope_reminder',
        'give_back_examples',
    ];

    /** @var array<string, list<string>> variant_id => included_sections */
    private const VARIANT_SECTIONS = [
        self::VARIANT_RECOVERY_GIVE_BACK_TASK => [
            'objective', 'allowed_files', 'acceptance_criteria', 'required_evidence',
            'recovery_diagnosis_steps', 'duplicate_detection_steps', 'give_back_examples',
        ],
        self::VARIANT_HIGH_RISK_TASK => [
            'objective', 'allowed_files', 'acceptance_criteria', 'required_evidence',
            'full_codebase_context', 'architecture_rationale', 'risk_review_checklist', 'dual_review_requirement',
        ],
        self::VARIANT_LOW_CONTEXT_TASK => [
            'objective', 'allowed_files', 'acceptance_criteria', 'required_evidence', 'minimal_scope_reminder',
        ],
        self::VARIANT_LOCAL_SUBSCRIPTION_CLIENT => [
            'objective', 'allowed_files', 'acceptance_criteria', 'required_evidence', 'minimal_scope_reminder',
        ],
        self::VARIANT_EXTERNAL_MUSCLE => [
            'objective', 'allowed_files', 'acceptance_criteria', 'required_evidence',
            'full_codebase_context', 'provider_api_usage_notes',
        ],
    ];

    /** @var array<string, string> variant_id => human-readable fit reason */
    private const VARIANT_REASONS = [
        self::VARIANT_RECOVERY_GIVE_BACK_TASK => 'task_mode is recovery/give_back: the worker needs diagnosis and duplicate-detection steps, not fresh-context onboarding',
        self::VARIANT_HIGH_RISK_TASK => 'risk_level is high: the worker needs full architecture context and a dual-review checklist before changing risky surfaces',
        self::VARIANT_LOW_CONTEXT_TASK => 'context_size is low: the worker has limited context budget, so the prompt stays to the minimal scope it can act on',
        self::VARIANT_LOCAL_SUBSCRIPTION_CLIENT => 'muscle_type is local_subscription_client: no paid provider API is available, so provider-specific sections are omitted',
        self::VARIANT_EXTERNAL_MUSCLE => 'default: a general-purpose external muscle gets full codebase context and provider usage notes',
    ];

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function select(array $input): array
    {
        $muscleType  = strtolower(trim((string) ($input['muscle_type'] ?? self::VARIANT_EXTERNAL_MUSCLE)));
        $riskLevel   = strtolower(trim((string) ($input['risk_level'] ?? 'low')));
        $contextSize = strtolower(trim((string) ($input['context_size'] ?? 'normal')));
        $taskMode    = strtolower(trim((string) ($input['task_mode'] ?? 'normal')));

        $variantId = match (true) {
            in_array($taskMode, ['recovery', 'give_back'], true) => self::VARIANT_RECOVERY_GIVE_BACK_TASK,
            $riskLevel === 'high' => self::VARIANT_HIGH_RISK_TASK,
            $contextSize === 'low' => self::VARIANT_LOW_CONTEXT_TASK,
            $muscleType === self::VARIANT_LOCAL_SUBSCRIPTION_CLIENT => self::VARIANT_LOCAL_SUBSCRIPTION_CLIENT,
            default => self::VARIANT_EXTERNAL_MUSCLE,
        };

        $included = self::VARIANT_SECTIONS[$variantId];
        $omitted  = array_values(array_diff(self::ALL_SECTIONS, $included));

        $guardrails = [
            'allowed_files_only',
            'no_manual_git_commands',
            'prove_before_report',
            'give_back_on_duplicate',
        ];
        if ($muscleType === self::VARIANT_LOCAL_SUBSCRIPTION_CLIENT) {
            $guardrails[] = 'no_paid_api_dependency';
        }

        return [
            'schema'             => self::SCHEMA,
            'prompt_variant_id'  => $variantId,
            'included_sections'  => $included,
            'omitted_sections'   => $omitted,
            'guardrails'         => $guardrails,
            'reason'             => self::VARIANT_REASONS[$variantId],
        ];
    }
}
