<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Cores;

use App\Services\Ai\Aaeos\Support\AtlasAaeosArrayFieldReader;

final class MemoryInjectionBudgetAllocator
{
    private const SCHEMA_VERSION = 'atlas.aaeos.memory_injection_budget_allocation.v1';

    /**
     * Internal minimum-excerpt floor used when no explicit floor is provided.
     * The effective floor is min(perItemCapChars, this) so it can never exceed
     * the per-item cap.
     */
    private const DEFAULT_INTERNAL_FLOOR_CHARS = 80;

    private const REASON_BUDGET_EXHAUSTED = 'budget_exhausted';

    private const REASON_BELOW_MIN_EXCERPT = 'below_min_excerpt';

    private const REASON_ZERO_ESTIMATED_CHARS = 'zero_estimated_chars';

    /**
     * Pure char-budget packer. Sorts a copy of the ranked items by
     * priority DESC, then estimated_chars ASC, then ref ASC, then greedily
     * admits each item while the remaining budget can fund an excerpt of at
     * least the effective minimum floor. Every returned field is computed from
     * the inputs via knapsack-style packing; no I/O, clock or randomness.
     *
     * @param  list<array{ref:string,priority:int|float,estimated_chars:int}>  $rankedItems
     * @return array{
     *     schema_version:string,
     *     total_budget_chars:int,
     *     per_item_cap_chars:int,
     *     min_excerpt_chars:int,
     *     admitted:list<array{ref:string,priority:float,requested_chars:int,allocated_chars:int,capped:bool,rank:int}>,
     *     dropped:list<array{ref:string,priority:float,requested_chars:int,reason:string}>,
     *     used_chars:int,
     *     remaining_chars:int,
     *     admitted_count:int,
     *     dropped_count:int,
     *     truncated:bool
     * }
     */
    public function allocate(
        array $rankedItems,
        int $totalBudgetChars,
        int $perItemCapChars,
        ?int $minExcerptChars = null,
    ): array {
        $totalBudget = max(0, $totalBudgetChars);
        $perItemCap = max(0, $perItemCapChars);
        $minExcerpt = $this->resolveMinExcerpt($minExcerptChars, $perItemCap);

        $ordered = $this->sortedCopy($rankedItems);

        $remaining = $totalBudget;
        $admitted = [];
        $dropped = [];
        $rank = 0;

        foreach ($ordered as $item) {
            $ref = AtlasAaeosArrayFieldReader::stringField($item, 'ref');
            $priority = $this->priorityField($item);
            $estimated = $this->estimatedChars($item);

            if ($estimated <= 0) {
                $dropped[] = $this->dropped($ref, $priority, $estimated, self::REASON_ZERO_ESTIMATED_CHARS);

                continue;
            }

            $intrinsicMax = min($estimated, $perItemCap);

            if ($intrinsicMax < $minExcerpt) {
                $dropped[] = $this->dropped($ref, $priority, $estimated, self::REASON_BELOW_MIN_EXCERPT);

                continue;
            }

            $allocatable = max(0, min($estimated, $perItemCap, $remaining));

            if ($allocatable < $minExcerpt) {
                $dropped[] = $this->dropped($ref, $priority, $estimated, self::REASON_BUDGET_EXHAUSTED);

                continue;
            }

            $rank++;
            $remaining -= $allocatable;

            $admitted[] = [
                'ref' => $ref,
                'priority' => $priority,
                'requested_chars' => $estimated,
                'allocated_chars' => $allocatable,
                'capped' => $allocatable < $estimated,
                'rank' => $rank,
            ];
        }

        $usedChars = $totalBudget - $remaining;
        $droppedCount = count($dropped);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'total_budget_chars' => $totalBudget,
            'per_item_cap_chars' => $perItemCap,
            'min_excerpt_chars' => $minExcerpt,
            'admitted' => $admitted,
            'dropped' => $dropped,
            'used_chars' => $usedChars,
            'remaining_chars' => $remaining,
            'admitted_count' => count($admitted),
            'dropped_count' => $droppedCount,
            'truncated' => $droppedCount > 0,
        ];
    }

    /**
     * Effective floor: explicit value when provided, else min(cap, internal
     * floor). Always clamped to [0, perItemCap] so a floor can never demand
     * more than the per-item cap allows.
     */
    private function resolveMinExcerpt(?int $minExcerptChars, int $perItemCap): int
    {
        $floor = $minExcerptChars ?? min($perItemCap, self::DEFAULT_INTERNAL_FLOOR_CHARS);

        return max(0, min($floor, $perItemCap));
    }

    /**
     * Deterministic copy sorted by priority DESC, estimated_chars ASC, ref ASC.
     *
     * @param  list<array<string,mixed>>  $rankedItems
     * @return list<array<string,mixed>>
     */
    private function sortedCopy(array $rankedItems): array
    {
        $ordered = array_values($rankedItems);

        usort($ordered, fn (array $a, array $b): int => $this->compare($a, $b));

        return $ordered;
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    private function compare(array $a, array $b): int
    {
        $priorityA = $this->priorityField($a);
        $priorityB = $this->priorityField($b);

        if ($priorityA !== $priorityB) {
            return $priorityB <=> $priorityA;
        }

        $estimatedA = $this->estimatedChars($a);
        $estimatedB = $this->estimatedChars($b);

        if ($estimatedA !== $estimatedB) {
            return $estimatedA <=> $estimatedB;
        }

        return strcmp(AtlasAaeosArrayFieldReader::stringField($a, 'ref'), AtlasAaeosArrayFieldReader::stringField($b, 'ref'));
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array{ref:string,priority:float,requested_chars:int,reason:string}
     */
    private function dropped(string $ref, float $priority, int $estimated, string $reason): array
    {
        return [
            'ref' => $ref,
            'priority' => $priority,
            'requested_chars' => $estimated,
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function priorityField(array $item): float
    {
        $value = $item['priority'] ?? 0;

        return is_numeric($value) ? (float) $value : 0.0;
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function estimatedChars(array $item): int
    {
        $value = $item['estimated_chars'] ?? 0;

        if (! is_numeric($value)) {
            return 0;
        }

        return max(0, (int) $value);
    }

}
