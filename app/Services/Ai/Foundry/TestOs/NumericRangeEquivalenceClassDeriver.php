<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\TestOs;

use InvalidArgumentException;

final class NumericRangeEquivalenceClassDeriver
{
    private const SCHEMA_VERSION = 'atlas.foundry.testos.numeric_range_equivalence_classes.v1';

    /**
     * Derive the five equivalence classes for a single numeric parameter.
     *
     * @param  array{name?: string, type?: string, min?: int|float, max?: int|float}  $paramSpec
     * @return array{
     *     schema_version: string,
     *     classes: list<array{label: string, representative: int|float, kind: string}>
     * }
     */
    public function derive(array $paramSpec): array
    {
        if (! array_key_exists('min', $paramSpec) || ! array_key_exists('max', $paramSpec)) {
            throw new InvalidArgumentException('paramSpec requires both min and max bounds.');
        }

        $isInt = ($paramSpec['type'] ?? 'int') === 'int';

        $min = $this->normalizeBound($paramSpec['min'], $isInt);
        $max = $this->normalizeBound($paramSpec['max'], $isInt);

        if ($min >= $max) {
            throw new InvalidArgumentException('paramSpec min must be strictly less than max.');
        }

        $belowMin = $isInt ? $min - 1 : $min - 1.0;
        $aboveMax = $isInt ? $max + 1 : $max + 1.0;
        $midpoint = $isInt ? intdiv($min + $max, 2) : ($min + $max) / 2;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'classes' => [
                [
                    'label' => 'below_min',
                    'representative' => $belowMin,
                    'kind' => 'invalid',
                ],
                [
                    'label' => 'min_boundary',
                    'representative' => $min,
                    'kind' => 'boundary',
                ],
                [
                    'label' => 'midpoint',
                    'representative' => $midpoint,
                    'kind' => 'valid',
                ],
                [
                    'label' => 'max_boundary',
                    'representative' => $max,
                    'kind' => 'boundary',
                ],
                [
                    'label' => 'above_max',
                    'representative' => $aboveMax,
                    'kind' => 'invalid',
                ],
            ],
        ];
    }

    private function normalizeBound(int|float $bound, bool $isInt): int|float
    {
        return $isInt ? (int) $bound : (float) $bound;
    }
}
