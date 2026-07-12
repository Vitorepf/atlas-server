<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use Illuminate\Support\Carbon;

final class AtlasLearningCurriculumService
{
    public const SCHEMA_VERSION = 'atlas.ai.learning_curriculum.v1';

    public const FORMULA_VERSION = 'atlas_ai_learning_curriculum_v1';

    public function __construct(
        private readonly AtlasLearningRecallUseLiftService $lessonYield,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(?int $minCases = null): array
    {
        $yield = $this->lessonYield->lessonTypeYieldReport($minCases);
        $types = array_values((array) ($yield['types'] ?? []));
        $ranking = [];

        foreach ($types as $type) {
            if (! is_array($type) || ($type['status'] ?? null) !== 'measured') {
                continue;
            }

            $lift = (float) ($type['passed_rate_lift'] ?? 0.0);
            $caseCount = (int) ($type['case_count'] ?? 0);
            $denominatorMin = max(1, (int) ($type['denominator_min'] ?? $yield['denominator_min'] ?? AtlasLearningRecallUseLiftService::LESSON_TYPE_YIELD_DENOMINATOR_MIN));
            $evidenceWeight = min(1.0, round($caseCount / $denominatorMin, 4));
            $score = round($lift * $evidenceWeight, 4);

            $ranking[] = [
                'category' => [
                    'memory_type' => (string) ($type['memory_type'] ?? 'unknown'),
                    'flow' => '*',
                    'scope' => '*',
                ],
                'bucket' => $lift > 0.0 ? 'positive_roi' : 'non_positive_roi',
                'curriculum_score' => $score,
                'signals' => [
                    'passed_rate_lift' => $lift,
                    'case_count' => $caseCount,
                    'baseline_case_count' => (int) ($type['baseline_case_count'] ?? 0),
                    'denominator_min' => $denominatorMin,
                    'evidence_weight' => $evidenceWeight,
                ],
                'prior' => $this->priorForLift($lift),
            ];
        }

        usort($ranking, static fn (array $a, array $b): int => [
            -1 * (float) $a['curriculum_score'],
            (string) data_get($a, 'category.memory_type', ''),
        ] <=> [
            -1 * (float) $b['curriculum_score'],
            (string) data_get($b, 'category.memory_type', ''),
        ]);

        $status = $ranking === [] ? 'insufficient_signal' : 'ok';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'formula_version' => self::FORMULA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'inputs' => [
                'lesson_type_yield' => [
                    'measure_id' => (string) ($yield['measure_id'] ?? AtlasLearningRecallUseLiftService::LESSON_TYPE_YIELD_MEASURE_ID),
                    'formula_version' => (string) ($yield['formula_version'] ?? AtlasLearningRecallUseLiftService::LESSON_TYPE_YIELD_FORMULA_VERSION),
                    'status' => (string) ($yield['status'] ?? 'unknown'),
                    'denominator_min' => (int) ($yield['denominator_min'] ?? AtlasLearningRecallUseLiftService::LESSON_TYPE_YIELD_DENOMINATOR_MIN),
                ],
            ],
            'global_prior' => $status === 'ok' ? [
                'mode' => 'ranked_by_measured_roi',
                'evidence_multiplier' => 1.0,
                'effort_multiplier' => 1.0,
            ] : $this->neutralPrior(),
            'totals' => [
                'candidate_categories' => count($types),
                'measured_categories' => count($ranking),
            ],
            'ranking' => $ranking,
            'claim_policy' => [
                'read_only' => true,
                'provider_calls_made' => false,
                'memory_written' => false,
                'distiller_live_policy_changed' => false,
                'category_disabled' => false,
                'never_zero_category' => true,
                'completion_claim_allowed' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function priorForLift(float $lift): array
    {
        if ($lift > 0.0) {
            return [
                'mode' => 'prioritize_learning',
                'effort_multiplier' => 1.15,
                'evidence_multiplier' => 0.9,
                'category_still_allowed' => true,
                'floor' => 'never_zero',
            ];
        }

        return [
            'mode' => 'require_more_evidence',
            'effort_multiplier' => 0.85,
            'evidence_multiplier' => 1.15,
            'category_still_allowed' => true,
            'floor' => 'never_zero',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function neutralPrior(): array
    {
        return [
            'mode' => 'neutral',
            'effort_multiplier' => 1.0,
            'evidence_multiplier' => 1.0,
            'category_still_allowed' => true,
            'floor' => 'never_zero',
        ];
    }
}
