<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Cores;

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
    private const SCHEMA_VERSION = 'atlas.aaeos.context_pareto_dominance.v1';

    private const DIRECTION_MAXIMIZE = 'maximize';

    private const DIRECTION_MINIMIZE = 'minimize';

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
                    'id' => $id,
                    'failed_constraints' => $failed,
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
                    'id' => $id,
                    'status' => 'blocked',
                    'dominated_by' => [],
                ];

                continue;
            }

            $dominatedBy = $this->dominatorsOf($variant, $admitted, $direction);

            if ($dominatedBy === []) {
                $frontier[] = $id;
                $evaluated[] = [
                    'id' => $id,
                    'status' => 'frontier',
                    'dominated_by' => [],
                ];

                continue;
            }

            $evaluated[] = [
                'id' => $id,
                'status' => 'dominated',
                'dominated_by' => $dominatedBy,
            ];
        }

        $blockedCount = count($blocked);
        $frontierCount = count($frontier);
        $admittedCount = count($admitted);
        $dominatedCount = $admittedCount - $frontierCount;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'objective_direction' => $direction,
            'frontier' => $frontier,
            'blocked' => $blocked,
            'evaluated' => $evaluated,
            'summary' => [
                'total' => count($variants),
                'admitted' => $admittedCount,
                'blocked' => $blockedCount,
                'frontier' => $frontierCount,
                'dominated' => $dominatedCount,
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

        if (array_key_exists('equals', $rule)) {
            if ($value !== $rule['equals']) {
                return false;
            }
        }

        if (array_key_exists('min', $rule)) {
            if (! is_numeric($value) || (float) $value < (float) $rule['min']) {
                return false;
            }
        }

        if (array_key_exists('max', $rule)) {
            if (! is_numeric($value) || (float) $value > (float) $rule['max']) {
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
            $normalized[(string) $key] = $dir === self::DIRECTION_MINIMIZE
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
        $value = $variant[$key] ?? 0;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @param  array<string,mixed>  $variant
     */
    private function variantId(array $variant): string
    {
        $id = $variant['id'] ?? '';

        return is_string($id) ? $id : (string) $id;
    }
}
