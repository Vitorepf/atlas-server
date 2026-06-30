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
 * Surviving observations are classified into an owner_dimension by keyword
 * match (first match wins), then enriched with evidence_requirement and
 * suggested_enforcement_point.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainPromptToPolicyExtractor
{
    public const SCHEMA = 'atlas.external_brain.prompt_to_policy_extractor.v1';

    private const MIN_OCCURRENCE_COUNT = 2;

    private const DIMENSION_MAP = [
        // keywords (lowercase) → [owner_dimension, evidence_requirement, enforcement_point]
        'proxy'      => ['loop_origination',  'origination_evidence',             'origination_classifier'],
        'faxina'     => ['loop_origination',  'origination_evidence',             'origination_classifier'],
        'cleanup'    => ['loop_origination',  'origination_evidence',             'origination_classifier'],
        'template'   => ['loop_origination',  'origination_evidence',             'origination_classifier'],
        'farm'       => ['loop_origination',  'origination_evidence',             'origination_classifier'],
        'evidence'   => ['quality_gate',      'tests_or_gates_result',            'pre_commit_gate'],
        'proof'      => ['quality_gate',      'tests_or_gates_result',            'pre_commit_gate'],
        'gate'       => ['quality_gate',      'tests_or_gates_result',            'pre_commit_gate'],
        'human'      => ['dependency_guard',  'autonomy_regression_scan',         'autonomy_regression_sentinel'],
        'operator'   => ['dependency_guard',  'autonomy_regression_scan',         'autonomy_regression_sentinel'],
        'provider'   => ['dependency_guard',  'autonomy_regression_scan',         'autonomy_regression_sentinel'],
        'memory'     => ['memory_discipline', 'atlas_native_store_check',         'memory_write_interceptor'],
        'prompt'     => ['memory_discipline', 'atlas_native_store_check',         'memory_write_interceptor'],
        'context'    => ['memory_discipline', 'atlas_native_store_check',         'memory_write_interceptor'],
        'macro'      => ['task_shape',        'macro_task_acceptance_criteria',   'task_packet_validator'],
        'scope'      => ['task_shape',        'macro_task_acceptance_criteria',   'task_packet_validator'],
    ];

    private const DEFAULT_DIMENSION   = ['general_policy', 'implementation_notes', 'policy_backlog'];

    /**
     * @param  array{prompt_observations?: list<array<string,mixed>>}  $input
     * @return array{schema:string, policy_candidates:list<array<string,string|int>>, rejected_observations:list<array<string,string>>}
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

            $rejectionReason = $this->rejectionReason(
                $isEmotional,
                $isRawProvider,
                $enforceable,
                $occurrences,
            );

            if ($rejectionReason !== null) {
                $rejected[] = [
                    'text'             => $text,
                    'rejection_reason' => $rejectionReason,
                ];
                continue;
            }

            [$ownerDimension, $evidenceRequirement, $enforcementPoint] = $this->classify($text);

            $candidates[] = [
                'normalized_text'          => $this->normalize($text),
                'owner_dimension'          => $ownerDimension,
                'evidence_requirement'     => $evidenceRequirement,
                'suggested_enforcement_point' => $enforcementPoint,
                'occurrence_count'         => $occurrences,
            ];
        }

        return [
            'schema'               => self::SCHEMA,
            'policy_candidates'    => $candidates,
            'rejected_observations' => $rejected,
        ];
    }

    private function rejectionReason(
        bool $isEmotional,
        bool $isRawProvider,
        bool $enforceable,
        int  $occurrences,
    ): ?string {
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

    /** @return array{string, string, string} */
    private function classify(string $text): array
    {
        $lower = strtolower($text);

        foreach (self::DIMENSION_MAP as $keyword => [$dimension, $evidence, $point]) {
            if (str_contains($lower, $keyword)) {
                return [$dimension, $evidence, $point];
            }
        }

        return self::DEFAULT_DIMENSION;
    }

    private function normalize(string $text): string
    {
        // Strip leading/trailing whitespace, collapse internal spaces, lowercase.
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
