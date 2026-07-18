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

    public const REASON_OK = 'ok';
    public const FIELD_WEIGHT = 'weight';
    public const FIELD_REASON = 'reason';
    public const FIELD_RAW_REQUEST = 'raw_request';
    public const FIELD_INTERPRETED_GOAL = 'interpreted_goal';
    public const FIELD_NON_GOALS = 'non_goals';
    public const FIELD_PRODUCT_AREA = 'product_area';
    public const FIELD_REQUIREMENTS = 'requirements';
    public const FIELD_ACCEPTANCE_CRITERIA = 'acceptance_criteria';
    public const FIELD_BUSINESS_ACTOR_OBJECT_ACTION = 'business_actor_object_action';
    public const FIELD_DESIGN_SYSTEM_CONSTRAINTS = 'design_system_constraints';
    public const FIELD_SECURITY_CONSTRAINTS = 'security_constraints';
    public const FIELD_ASSUMPTIONS = 'assumptions';
    public const FIELD_BLOCKING_QUESTIONS = 'blocking_questions';
    public const FIELD_TEST_STRATEGY = 'test_strategy';
    public const FIELD_PRESENT = 'present';
    public const FIELD_SATISFIED = 'satisfied';
    public const FIELD_FIELD = 'field';
    public const FIELD_WEIGHT_LOSS = 'weight_loss';
    public const FIELD_TOTAL_SCORE = 'total_score';
    public const FIELD_EARNED = 'earned';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_VERDICT = 'verdict';


    /**
     * Weighted importance of each canonical spec field. Sums to exactly 100.
     */
    public const WEIGHTS = [
        self::FIELD_RAW_REQUEST => 8,
        self::FIELD_INTERPRETED_GOAL => 12,
        self::FIELD_NON_GOALS => 5,
        self::FIELD_PRODUCT_AREA => 7,
        self::FIELD_BUSINESS_ACTOR_OBJECT_ACTION => 10,
        self::FIELD_REQUIREMENTS => 12,
        self::FIELD_ACCEPTANCE_CRITERIA => 14,
        self::FIELD_DESIGN_SYSTEM_CONSTRAINTS => 6,
        self::FIELD_SECURITY_CONSTRAINTS => 8,
        self::FIELD_ASSUMPTIONS => 9,
        self::FIELD_BLOCKING_QUESTIONS => 1,
        self::FIELD_TEST_STRATEGY => 8,
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
                self::FIELD_PRESENT => $present,
                self::FIELD_SATISFIED => $satisfied,
                self::FIELD_WEIGHT => $weight,
                self::FIELD_EARNED => $earned,
                self::FIELD_REASON => $reason,
            ];

            if (! $satisfied) {
                $missing[] = [
                    self::FIELD_FIELD => $field,
                    self::FIELD_WEIGHT => $weight,
                    self::FIELD_WEIGHT_LOSS => (AiValueNormalizer::finiteFloatOrNull($weight) ?? 0.0) - $earned,
                    self::FIELD_REASON => $reason,
                ];
            }
        }

        usort($missing, static function (array $a, array $b): int {
            return $b[self::FIELD_WEIGHT_LOSS] <=> $a[self::FIELD_WEIGHT_LOSS]
                ?: strcmp($a[self::FIELD_FIELD], $b[self::FIELD_FIELD]);
        });

        $totalScore = (int) round($earnedTotal);

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_TOTAL_SCORE => $totalScore,
            self::FIELD_VERDICT => $this->verdict($totalScore),
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
        return ($this->score($spec)[self::FIELD_TOTAL_SCORE] ?? 0) >= $minScore;
    }

    /**
     * @return array{0: bool, 1: bool, 2: string}
     */
    private function evaluateField(string $field, mixed $value): array
    {
        if ($field === 'blocking_questions' && $value === []) {
            return [true, true, self::REASON_OK];
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

        return [true, true, self::REASON_OK];
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

        return [true, true, self::REASON_OK];
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
