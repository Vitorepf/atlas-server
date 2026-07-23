<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Scoring;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Pure generic multi-objective non-dominated frontier core.
 *
 * Given a set of variants, a per-axis direction map (maximize/minimize) and an
 * optional hard-constraint gate, it pre-filters blocked variants and then marks
 * each admitted variant as frontier or dominated by computing weak-domination
 * (every objective at-least-as-good per the declared direction) together with
 * strict-improvement (at least one objective strictly better). The frontier is
 * derived purely from the declared direction vector — polarity is never baked
 * into the class, so flipping a direction flips the result.
 */
final class ContextParetoDominanceFilter
{
    public const FIELD_ID = 'id';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const SCHEMA_VERSION = 'atlas.aaeos.context_pareto_dominance.v1';

    public const DIRECTION_MAXIMIZE = 'maximize';

    public const DIRECTION_MINIMIZE = 'minimize';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_DOMINATED = 'dominated';

    public const STATUS_FRONTIER = 'frontier';

    public const FIELD_BLOCKED = 'blocked';

    public const FIELD_DOMINATED = 'dominated';

    public const FIELD_FRONTIER = 'frontier';
    public const FIELD_DOMINATED_BY = 'dominated_by';
    public const FIELD_STATUS = 'status';
    public const FIELD_ADMITTED = 'admitted';
    public const FIELD_EQUALS = 'equals';
    public const FIELD_EVALUATED = 'evaluated';
    public const FIELD_FAILED_CONSTRAINTS = 'failed_constraints';
    public const FIELD_MAX = 'max';
    public const FIELD_MIN = 'min';
    public const FIELD_OBJECTIVE_DIRECTION = 'objective_direction';
    public const FIELD_SUMMARY = 'summary';
    public const FIELD_TOTAL = 'total';


    /**
     * @param  list<array<string,mixed>>  $variants
     * @param  array<string,string>  $objectiveDirection
     * @param  array<string,array<string,mixed>>  $hardConstraints
     * @return array<string,mixed>
     */
    public function filter(array $variants, array $objectiveDirection, array $hardConstraints = []): array
    {
        $direction = $this->normalizeDirection($objectiveDirection);

        $admitted = [];
        $blocked = [];
        $blockedById = [];

        foreach ($variants as $variant) {
            $id = $this->variantId($variant);
            $failed = $this->failedConstraints($variant, $hardConstraints);

            if ($failed !== []) {
                $blocked[] = [
                    self::FIELD_ID => $id,
                    self::FIELD_FAILED_CONSTRAINTS => $failed,
                ];
                $blockedById[$id] = $failed;

                continue;
            }

            $admitted[] = $variant;
        }

        $frontier = [];
        $evaluated = [];

        foreach ($variants as $variant) {
            $id = $this->variantId($variant);

            if (array_key_exists($id, $blockedById)) {
                $evaluated[] = [
                    self::FIELD_ID => $id,
                    self::FIELD_STATUS => self::STATUS_BLOCKED,
                    self::FIELD_DOMINATED_BY => [],
                ];

                continue;
            }

            $dominatedBy = $this->dominatorsOf($variant, $admitted, $direction);

            if ($dominatedBy === []) {
                $frontier[] = $id;
                $evaluated[] = [
                    self::FIELD_ID => $id,
                    self::FIELD_STATUS => self::STATUS_FRONTIER,
                    self::FIELD_DOMINATED_BY => [],
                ];

                continue;
            }

            $evaluated[] = [
                self::FIELD_ID => $id,
                self::FIELD_STATUS => self::STATUS_DOMINATED,
                self::FIELD_DOMINATED_BY => $dominatedBy,
            ];
        }

        $blockedCount = count($blocked);
        $frontierCount = count($frontier);
        $admittedCount = count($admitted);
        $dominatedCount = $admittedCount - $frontierCount;

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_OBJECTIVE_DIRECTION => $direction,
            self::FIELD_FRONTIER => $frontier,
            self::FIELD_BLOCKED => $blocked,
            self::FIELD_EVALUATED => $evaluated,
            self::FIELD_SUMMARY => [
                self::FIELD_TOTAL => count($variants),
                self::FIELD_ADMITTED => $admittedCount,
                self::FIELD_BLOCKED => $blockedCount,
                self::FIELD_FRONTIER => $frontierCount,
                self::FIELD_DOMINATED => $dominatedCount,
            ],
        ];
    }

    /**
     * Returns true when $b weakly dominates $a over every declared objective
     * (at-least-as-good per direction) AND strictly improves on at least one.
     *
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     * @param  array<string,string>  $objectiveDirection
     */
    private function isDominatedBy(array $a, array $b, array $objectiveDirection): bool
    {
        $atLeastAsGood = true;
        $strictlyBetter = false;

        foreach ($objectiveDirection as $key => $dir) {
            $aValue = $this->objectiveValue($a, $key);
            $bValue = $this->objectiveValue($b, $key);

            if ($dir === self::DIRECTION_MAXIMIZE) {
                if ($bValue < $aValue) {
                    $atLeastAsGood = false;

                    break;
                }

                if ($bValue > $aValue) {
                    $strictlyBetter = true;
                }

                continue;
            }

            // minimize
            if ($bValue > $aValue) {
                $atLeastAsGood = false;

                break;
            }

            if ($bValue < $aValue) {
                $strictlyBetter = true;
            }
        }

        return $atLeastAsGood && $strictlyBetter;
    }

    /**
     * @param  array<string,mixed>  $variant
     * @param  list<array<string,mixed>>  $admitted
     * @param  array<string,string>  $objectiveDirection
     * @return list<string>
     */
    private function dominatorsOf(array $variant, array $admitted, array $objectiveDirection): array
    {
        $id = $this->variantId($variant);
        $dominators = [];

        foreach ($admitted as $other) {
            $otherId = $this->variantId($other);

            if ($otherId === $id) {
                continue;
            }

            if ($this->isDominatedBy($variant, $other, $objectiveDirection)) {
                $dominators[] = $otherId;
            }
        }

        return $dominators;
    }

    /**
     * @param  array<string,mixed>  $variant
     * @param  array<string,array<string,mixed>>  $hardConstraints
     * @return list<string>
     */
    private function failedConstraints(array $variant, array $hardConstraints): array
    {
        $failed = [];

        foreach ($hardConstraints as $field => $rule) {
            if (! is_array($rule)) {
                continue;
            }

            if (! $this->constraintSatisfied($variant, $field, $rule)) {
                $failed[] = $field;
            }
        }

        return $failed;
    }

    /**
     * @param  array<string,mixed>  $variant
     * @param  array<string,mixed>  $rule
     */
    private function constraintSatisfied(array $variant, string $field, array $rule): bool
    {
        $value = $variant[$field] ?? null;

        if (array_key_exists(self::FIELD_EQUALS, $rule)) {
            if ($value !== $rule[self::FIELD_EQUALS]) {
                return false;
            }
        }

        if (array_key_exists(self::FIELD_MIN, $rule)) {
            $numeric = AiValueNormalizer::finiteFloatOrNull($value);
            if ($numeric === null || $numeric < (AiValueNormalizer::finiteFloatOrNull($rule[self::FIELD_MIN] ?? null) ?? 0.0)) {
                return false;
            }
        }

        if (array_key_exists(self::FIELD_MAX, $rule)) {
            $numeric = AiValueNormalizer::finiteFloatOrNull($value);
            if ($numeric === null || $numeric > (AiValueNormalizer::finiteFloatOrNull($rule[self::FIELD_MAX] ?? null) ?? 0.0)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,string>  $objectiveDirection
     * @return array<string,string>
     */
    private function normalizeDirection(array $objectiveDirection): array
    {
        $normalized = [];

        foreach ($objectiveDirection as $key => $dir) {
            $normalized[(string) $key] = AiValueNormalizer::lowerTrimmedString($dir) === self::DIRECTION_MINIMIZE
                ? self::DIRECTION_MINIMIZE
                : self::DIRECTION_MAXIMIZE;
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $variant
     */
    private function objectiveValue(array $variant, string $key): float
    {
        return AiValueNormalizer::finiteFloatOrNull($variant[$key] ?? null) ?? 0.0;
    }

    /**
     * @param  array<string,mixed>  $variant
     */
    private function variantId(array $variant): string
    {
        return AiValueNormalizer::trimmedStringOrNull($variant[self::FIELD_ID] ?? null) ?? '';
    }
}
