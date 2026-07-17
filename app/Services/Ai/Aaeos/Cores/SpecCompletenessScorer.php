<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Cores;

use App\Services\Ai\Support\AiValueNormalizer;

final class SpecCompletenessScorer
{
    public const SCHEMA_VERSION = 'atlas.aaeos.spec_completeness_score.v1';

    public const TEXT_MIN_LENGTH = 8;

    public const TOTAL_FIELDS = 12;

    public const COMPLETE_THRESHOLD = 80;

    public const PARTIAL_THRESHOLD = 50;

    public const VERDICT_COMPLETE = 'complete';

    public const VERDICT_PARTIAL = 'partial';

    public const VERDICT_INSUFFICIENT = 'insufficient';


    /**
     * Weighted importance of each canonical spec field. Sums to exactly 100.
     */
    public const WEIGHTS = [
        'raw_request' => 8,
        'interpreted_goal' => 12,
        'non_goals' => 5,
        'product_area' => 7,
        'business_actor_object_action' => 10,
        'requirements' => 12,
        'acceptance_criteria' => 14,
        'design_system_constraints' => 6,
        'security_constraints' => 8,
        'assumptions' => 9,
        'blocking_questions' => 1,
        'test_strategy' => 8,
    ];

    /**
     * Fields whose value is a list; every other canonical field is free text.
     *
     * @var list<string>
     */
    public const LIST_FIELDS = [
        'non_goals',
        'requirements',
        'acceptance_criteria',
        'assumptions',
        'blocking_questions',
    ];

    /**
     * @param  array<string,mixed>  $spec
     * @return array{
     *     schema_version: string,
     *     total_score: int,
     *     verdict: string,
     *     fields: array<string,array{present: bool, satisfied: bool, weight: int, earned: float, reason: string}>,
     *     missing_or_weak: list<array{field: string, weight: int, weight_loss: float, reason: string}>,
     *     present_count: int,
     *     total_fields: int
     * }
     */
    public function score(array $spec): array
    {
        $fields = [];
        $missing = [];
        $earnedTotal = 0.0;
        $presentCount = 0;

        foreach (self::WEIGHTS as $field => $weight) {
            [$present, $satisfied, $reason] = $this->evaluateField($field, $spec[$field] ?? null);

            $earned = $satisfied ? (AiValueNormalizer::finiteFloatOrNull($weight) ?? 0.0) : 0.0;
            $earnedTotal += $earned;

            if ($present) {
                $presentCount++;
            }

            $fields[$field] = [
                'present' => $present,
                'satisfied' => $satisfied,
                'weight' => $weight,
                'earned' => $earned,
                'reason' => $reason,
            ];

            if (! $satisfied) {
                $missing[] = [
                    'field' => $field,
                    'weight' => $weight,
                    'weight_loss' => (AiValueNormalizer::finiteFloatOrNull($weight) ?? 0.0) - $earned,
                    'reason' => $reason,
                ];
            }
        }

        usort($missing, static function (array $a, array $b): int {
            return $b['weight_loss'] <=> $a['weight_loss']
                ?: strcmp($a['field'], $b['field']);
        });

        $totalScore = (int) round($earnedTotal);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'total_score' => $totalScore,
            'verdict' => $this->verdict($totalScore),
            'fields' => $fields,
            'missing_or_weak' => $missing,
            'present_count' => $presentCount,
            'total_fields' => self::TOTAL_FIELDS,
        ];
    }

    /**
     * True when the weighted completeness score meets the complete threshold.
     *
     * @param  array<string,mixed>  $spec
     */
    public function passesMin(array $spec, int $minScore = self::COMPLETE_THRESHOLD): bool
    {
        return ($this->score($spec)['total_score'] ?? 0) >= $minScore;
    }

    /**
     * @return array{0: bool, 1: bool, 2: string}
     */
    private function evaluateField(string $field, mixed $value): array
    {
        if ($field === 'blocking_questions' && $value === []) {
            return [true, true, 'ok'];
        }

        if (in_array($field, self::LIST_FIELDS, true)) {
            return $this->evaluateListField($value);
        }

        return $this->evaluateTextField($value);
    }

    /**
     * @return array{0: bool, 1: bool, 2: string}
     */
    private function evaluateTextField(mixed $value): array
    {
        $trimmed = AiValueNormalizer::trimmedStringOrNull($value);
        if ($trimmed === null) {
            return [false, false, 'absent'];
        }

        if (mb_strlen($trimmed) < self::TEXT_MIN_LENGTH) {
            return [true, false, 'too_short'];
        }

        return [true, true, 'ok'];
    }

    /**
     * @return array{0: bool, 1: bool, 2: string}
     */
    private function evaluateListField(mixed $value): array
    {
        if (! is_array($value) || $this->countNonEmptyItems($value) === 0) {
            return [false, false, 'empty_list'];
        }

        if (! $this->hasMeaningfulItem($value)) {
            return [true, false, 'too_short'];
        }

        return [true, true, 'ok'];
    }

    /**
     * @param  array<int|string,mixed>  $items
     */
    private function hasMeaningfulItem(array $items): bool
    {
        foreach ($items as $item) {
            if (($text = AiValueNormalizer::trimmedStringOrNull($item)) !== null) {
                if (mb_strlen($text) >= self::TEXT_MIN_LENGTH) {
                    return true;
                }

                continue;
            }

            if (is_array($item) && $this->hasMeaningfulItem($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int|string,mixed>  $items
     */
    private function countNonEmptyItems(array $items): int
    {
        $count = 0;

        foreach ($items as $item) {
            if (is_string($item)) {
                if (AiValueNormalizer::trimmedStringOrNull($item) !== null) {
                    $count++;
                }

                continue;
            }

            if (is_array($item)) {
                if ($this->countNonEmptyItems($item) > 0) {
                    $count++;
                }

                continue;
            }

            if ($item !== null && $item !== false && $item !== []) {
                $count++;
            }
        }

        return $count;
    }

    private function verdict(int $totalScore): string
    {
        if ($totalScore >= self::COMPLETE_THRESHOLD) {
            return self::VERDICT_COMPLETE;
        }

        if ($totalScore >= self::PARTIAL_THRESHOLD) {
            return self::VERDICT_PARTIAL;
        }

        return self::VERDICT_INSUFFICIENT;
    }
}
