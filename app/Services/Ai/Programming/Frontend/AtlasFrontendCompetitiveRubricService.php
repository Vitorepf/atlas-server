<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;

final class AtlasFrontendCompetitiveRubricService
{
    public const SCHEMA_VERSION = 'atlas.frontend.competitive_rubric.v1';

    /**
     * @return array<string,mixed>
     */
    public function rubric(): array
    {
        $dimensions = [
            $this->dimension('product_intent_fit', 12, 'Solves the user/company job without decorative detours.'),
            $this->dimension('visual_hierarchy_and_information_architecture', 12, 'Clear scanning order, density control and readable hierarchy.'),
            $this->dimension('composition_layout_and_spacing', 10, 'Stable layout, strong alignment, responsive proportions and no incoherent overlap.'),
            $this->dimension('interaction_states_and_workflow_ergonomics', 10, 'Complete states, efficient workflows and credible interaction behavior.'),
            $this->dimension('responsive_multi_viewport_quality', 10, 'Desktop, tablet and mobile fit without hidden clipping or text overflow.'),
            $this->dimension('accessibility_and_semantics', 10, 'Keyboard, names, contrast, semantic structure and reduced-motion care where relevant.'),
            $this->dimension('implementation_integrity', 10, 'Clean framework-native implementation, low drift, maintainable components and safe source patching.'),
            $this->dimension('performance_and_runtime_budget', 8, 'Reasonable bundle, rendering, asset and latency profile for the task.'),
            $this->dimension('anti_slop_originality_and_brand_fit', 8, 'Avoids generic AI visual tropes and fits the target brand/product context.'),
            $this->dimension('evidence_completeness', 10, 'Screenshots, state checks, detector results, verification hashes and audit trail are present.'),
        ];

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'ready',
            'score_max' => array_sum(array_column($dimensions, 'weight')),
            'dimensions' => $dimensions,
            'minimum_quality_policy' => [
                'per_dimension_minimum_for_world_best_claim' => 7,
                'total_percent_minimum_for_world_best_claim' => 90,
                'atlas_must_match_or_beat_best_rival_per_case' => true,
                'evidence_completeness_is_mandatory' => true,
                'raw_prompt_or_customer_source_forbidden' => true,
            ],
            'comparison_policy' => [
                'same_task_spec_hash_required_across_systems' => true,
                'same_viewport_matrix_required_across_systems' => true,
                'same_rubric_required_across_systems' => true,
                'provider_brand_is_not_a_score_dimension' => true,
            ],
        ];
        $payload['rubric_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $scoreBreakdown
     * @return array<int,string>
     */
    public function validateBreakdown(array $scoreBreakdown, int $scoreTotal, int $scoreMax): array
    {
        $rubric = $this->rubric();
        $issues = [];
        $sum = 0;

        foreach ($rubric['dimensions'] as $dimension) {
            $id = (string) $dimension['id'];
            $weight = (int) $dimension['weight'];
            if (! array_key_exists($id, $scoreBreakdown) || ! is_numeric($scoreBreakdown[$id])) {
                $issues[] = 'missing_score_breakdown_'.$id;

                continue;
            }

            $value = (int) $scoreBreakdown[$id];
            if ($value < 0 || $value > $weight) {
                $issues[] = 'score_breakdown_'.$id.'_out_of_range';
            }
            $sum += $value;
        }

        foreach (array_keys($scoreBreakdown) as $id) {
            if (! in_array($id, array_column($rubric['dimensions'], 'id'), true)) {
                $issues[] = 'unknown_score_breakdown_'.$id;
            }
        }

        if ($sum !== $scoreTotal) {
            $issues[] = 'score_breakdown_sum_mismatch';
        }
        if ($scoreMax !== (int) $rubric['score_max']) {
            $issues[] = 'score_max_must_match_competitive_rubric';
        }

        return $issues;
    }

    /**
     * @return array<string,mixed>
     */
    private function dimension(string $id, int $weight, string $description): array
    {
        return [
            'id' => $id,
            'weight' => $weight,
            'description' => $description,
        ];
    }
}
