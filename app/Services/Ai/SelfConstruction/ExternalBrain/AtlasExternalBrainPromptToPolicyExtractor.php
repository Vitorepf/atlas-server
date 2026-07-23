<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure prompt-to-policy extractor. Mines recurring human prompt observations
 * into normalized Atlas-native policy candidates.
 *
 * Rejection rules (any one → reject):
 *   one_off_emotional_wording           — is_emotional=true
 *   raw_provider_text                   — is_raw_provider_text=true
 *   cannot_be_enforced_by_atlas_native  — is_atlas_native_enforceable=false
 *   insufficient_occurrence_count       — occurrence_count < MIN_OCCURRENCE_COUNT
 *
 * Policy candidate output fields:
 *   normalized_text, category, trigger, enforcement_check,
 *   owner_dimension, evidence_requirement, suggested_enforcement_point,
 *   occurrence_count, minimum_occurrences, rejection_reason (null for accepted)
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainPromptToPolicyExtractor
{
    public const SCHEMA = 'atlas.external_brain.prompt_to_policy_extractor.v1';

    private const MIN_OCCURRENCE_COUNT = 2;

    // keyword → [owner_dimension, evidence_requirement, enforcement_point]
    private const DIMENSION_MAP = [
        'proxy'    => ['loop_origination',  'origination_evidence',           'origination_classifier'],
        'faxina'   => ['loop_origination',  'origination_evidence',           'origination_classifier'],
        'cleanup'  => ['loop_origination',  'origination_evidence',           'origination_classifier'],
        'template' => ['loop_origination',  'origination_evidence',           'origination_classifier'],
        'farm'     => ['loop_origination',  'origination_evidence',           'origination_classifier'],
        'evidence' => ['quality_gate',      'tests_or_gates_result',          'pre_commit_gate'],
        'proof'    => ['quality_gate',      'tests_or_gates_result',          'pre_commit_gate'],
        'gate'     => ['quality_gate',      'tests_or_gates_result',          'pre_commit_gate'],
        'human'    => ['dependency_guard',  'autonomy_regression_scan',       'autonomy_regression_sentinel'],
        'operator' => ['dependency_guard',  'autonomy_regression_scan',       'autonomy_regression_sentinel'],
        'provider' => ['dependency_guard',  'autonomy_regression_scan',       'autonomy_regression_sentinel'],
        'memory'   => ['memory_discipline', 'atlas_native_store_check',       'memory_write_interceptor'],
        'prompt'   => ['memory_discipline', 'atlas_native_store_check',       'memory_write_interceptor'],
        'context'  => ['memory_discipline', 'atlas_native_store_check',       'memory_write_interceptor'],
        'macro'    => ['task_shape',        'macro_task_acceptance_criteria', 'task_packet_validator'],
        'scope'    => ['task_shape',        'macro_task_acceptance_criteria', 'task_packet_validator'],
    ];

    // keyword → [category, trigger, enforcement_check, blocked_behavior]
    private const CATEGORY_MAP = [
        'proxy'    => ['proxy_anti_pattern',    'at_task_origination',        'reject_if_objective_is_proxy_or_cleanup', 'proxy_progress_without_delivery'],
        'faxina'   => ['proxy_anti_pattern',    'at_task_origination',        'reject_if_objective_is_proxy_or_cleanup', 'proxy_progress_without_delivery'],
        'cleanup'  => ['proxy_anti_pattern',    'at_task_origination',        'reject_if_objective_is_proxy_or_cleanup', 'proxy_progress_without_delivery'],
        'template' => ['proxy_anti_pattern',    'at_task_origination',        'reject_if_template_farm_detected', 'template_farming'],
        'farm'     => ['proxy_anti_pattern',    'at_task_origination',        'reject_if_template_farm_detected', 'template_farming'],
        'evidence' => ['evidence_discipline',   'at_evidence_validation',     'reject_if_no_runnable_evidence', 'unproven_progress_claim'],
        'proof'    => ['evidence_discipline',   'at_evidence_validation',     'reject_if_no_runnable_evidence', 'unproven_progress_claim'],
        'gate'     => ['evidence_discipline',   'at_evidence_validation',     'reject_if_gate_check_absent', 'unproven_progress_claim'],
        'human'    => ['autonomy_constraint',   'at_dependency_scan',         'reject_if_requires_human_approval', 'human_dependency_reintroduction'],
        'operator' => ['autonomy_constraint',   'at_dependency_scan',         'reject_if_requires_operator_intervention', 'operator_dependency_reintroduction'],
        'provider' => ['autonomy_constraint',   'at_dependency_scan',         'reject_if_provider_dependency_detected', 'provider_dependency_reintroduction'],
        'memory'   => ['memory_discipline',     'at_memory_write',            'reject_if_non_atlas_native_store', 'non_atlas_native_memory_write'],
        'prompt'   => ['memory_discipline',     'at_memory_write',            'reject_if_prompt_treated_as_memory', 'non_atlas_native_memory_write'],
        'context'  => ['memory_discipline',     'at_memory_write',            'reject_if_context_not_curated', 'non_atlas_native_memory_write'],
        'macro'    => ['task_shape_discipline', 'at_task_packet_validation',  'reject_if_scope_too_narrow', 'undersized_task_shape'],
        'scope'    => ['task_shape_discipline', 'at_task_packet_validation',  'reject_if_scope_too_narrow', 'undersized_task_shape'],
        'wait'     => ['continuity_or_origination_discipline', 'at_continuity_check', 'reject_if_wait_used_as_progress', 'wait_as_progress'],
        'queue'    => ['continuity_or_origination_discipline', 'at_continuity_check', 'reject_if_wait_used_as_progress', 'wait_as_progress'],
        'padding'  => ['continuity_or_origination_discipline', 'at_continuity_check', 'reject_if_padding_used_as_progress', 'padding_as_progress'],
    ];

    private const DEFAULT_DIMENSION = ['general_policy', 'implementation_notes', 'policy_backlog'];
    private const DEFAULT_CATEGORY  = ['general_policy_rule', 'at_policy_evaluation', 'log_and_flag_for_review', 'undisciplined_behavior'];

    /**
     * @param  array{prompt_observations?: list<array<string,mixed>>}  $input
     * @return array<string,mixed>
     */
    public function extract(array $input): array
    {
        $observations = (array) ($input['prompt_observations'] ?? []);

        $candidates = [];
        $rejected   = [];

        foreach ($observations as $obs) {
            $text          = (string) ($obs['text']                       ?? '');
            $occurrences   = max(0, (int) ($obs['occurrence_count']       ?? 0));
            $isEmotional   = (bool) ($obs['is_emotional']                 ?? false);
            $isRawProvider = (bool) ($obs['is_raw_provider_text']         ?? false);
            $enforceable   = (bool) ($obs['is_atlas_native_enforceable']  ?? true);

            $rejectionReason = $this->rejectionReason($isEmotional, $isRawProvider, $enforceable, $occurrences);

            if ($rejectionReason !== null) {
                $rejected[] = [
                    'text'             => $text,
                    'rejection_reason' => $rejectionReason,
                ];
                continue;
            }

            [$ownerDimension, $evidenceRequirement, $enforcementPoint] = $this->classifyDimension($text);
            [$category, $trigger, $enforcementCheck, $blockedBehavior] = $this->classifyCategory($text);

            $candidates[] = [
                'normalized_text'             => $this->normalize($text),
                'category'                    => $category,
                'trigger'                     => $trigger,
                'enforcement_check'           => $enforcementCheck,
                'blocked_behavior'            => $blockedBehavior,
                'owner_dimension'             => $ownerDimension,
                'evidence_requirement'        => $evidenceRequirement,
                'suggested_enforcement_point' => $enforcementPoint,
                'occurrence_count'            => $occurrences,
                'minimum_occurrences'         => self::MIN_OCCURRENCE_COUNT,
                'rejection_reason'            => null,
            ];
        }

        return [
            'schema'                => self::SCHEMA,
            'policy_candidates'    => $candidates,
            'rejected_observations' => $rejected,
        ];
    }

    private function rejectionReason(bool $isEmotional, bool $isRawProvider, bool $enforceable, int $occurrences): ?string
    {
        if ($isEmotional) {
            return 'one_off_emotional_wording';
        }
        if ($isRawProvider) {
            return 'raw_provider_text';
        }
        if (! $enforceable) {
            return 'cannot_be_enforced_by_atlas_native';
        }
        if ($occurrences < self::MIN_OCCURRENCE_COUNT) {
            return 'insufficient_occurrence_count';
        }
        return null;
    }

    /** @return array{string,string,string} */
    private function classifyDimension(string $text): array
    {
        $lower = strtolower($text);
        foreach (self::DIMENSION_MAP as $keyword => $attrs) {
            if (str_contains($lower, $keyword)) {
                return $attrs;
            }
        }
        return self::DEFAULT_DIMENSION;
    }

    /** @return array{string,string,string,string} */
    private function classifyCategory(string $text): array
    {
        $lower = strtolower($text);
        foreach (self::CATEGORY_MAP as $keyword => $attrs) {
            if (str_contains($lower, $keyword)) {
                return $attrs;
            }
        }
        return self::DEFAULT_CATEGORY;
    }

    private function normalize(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
