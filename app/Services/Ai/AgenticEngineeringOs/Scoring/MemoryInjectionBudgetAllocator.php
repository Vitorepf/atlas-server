<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Scoring;

use App\Services\Ai\AgenticEngineeringOs\Support\AtlasArrayFieldReader;
use App\Services\Ai\Support\AiValueNormalizer;

final class MemoryInjectionBudgetAllocator
{
    public const SCHEMA_VERSION = 'atlas.aaeos.memory_injection_budget_allocation.v1';

    /**
     * Internal minimum-excerpt floor used when no explicit floor is provided.
     * The effective floor is min(perItemCapChars, this) so it can never exceed
     * the per-item cap.
     */
    public const DEFAULT_INTERNAL_FLOOR_CHARS = 80;

    public const REASON_BUDGET_EXHAUSTED = 'budget_exhausted';

    public const REASON_BELOW_MIN_EXCERPT = 'below_min_excerpt';

    public const REASON_ZERO_ESTIMATED_CHARS = 'zero_estimated_chars';
    public const FIELD_REF = 'ref';
    public const FIELD_PRIORITY = 'priority';
    public const FIELD_REQUESTED_CHARS = 'requested_chars';
    public const FIELD_ALLOCATED_CHARS = 'allocated_chars';
    public const FIELD_CAPPED = 'capped';
    public const FIELD_RANK = 'rank';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_TOTAL_BUDGET_CHARS = 'total_budget_chars';
    public const FIELD_ADMITTED = 'admitted';
    public const FIELD_ADMITTED_COUNT = 'admitted_count';
    public const FIELD_DROPPED = 'dropped';
    public const FIELD_DROPPED_COUNT = 'dropped_count';
    public const FIELD_ESTIMATED_CHARS = 'estimated_chars';
    public const FIELD_MIN_EXCERPT_CHARS = 'min_excerpt_chars';
    public const FIELD_PER_ITEM_CAP_CHARS = 'per_item_cap_chars';
    public const FIELD_USED_CHARS = 'used_chars';
    public const FIELD_TRUNCATED = 'truncated';
    public const FIELD_REMAINING_CHARS = 'remaining_chars';
    public const FIELD_REASON = 'reason';

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
            $ref = AiValueNormalizer::trimmedStringOrNull(AtlasArrayFieldReader::stringField($item, self::FIELD_REF)) ?? '';
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
                self::FIELD_REF => $ref,
                self::FIELD_PRIORITY => $priority,
                self::FIELD_REQUESTED_CHARS => $estimated,
                self::FIELD_ALLOCATED_CHARS => $allocatable,
                self::FIELD_CAPPED => $allocatable < $estimated,
                self::FIELD_RANK => $rank,
            ];
        }

        $usedChars = $totalBudget - $remaining;
        $droppedCount = count($dropped);

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_TOTAL_BUDGET_CHARS => $totalBudget,
            self::FIELD_PER_ITEM_CAP_CHARS => $perItemCap,
            self::FIELD_MIN_EXCERPT_CHARS => $minExcerpt,
            self::FIELD_ADMITTED => $admitted,
            self::FIELD_DROPPED => $dropped,
            self::FIELD_USED_CHARS => $usedChars,
            self::FIELD_REMAINING_CHARS => $remaining,
            self::FIELD_ADMITTED_COUNT => count($admitted),
            self::FIELD_DROPPED_COUNT => $droppedCount,
            self::FIELD_TRUNCATED => $droppedCount > 0,
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

        return strcmp(
            AiValueNormalizer::trimmedStringOrNull(AtlasArrayFieldReader::stringField($a, self::FIELD_REF)) ?? '',
            AiValueNormalizer::trimmedStringOrNull(AtlasArrayFieldReader::stringField($b, self::FIELD_REF)) ?? '',
        );
    }

    /**
     * @param  array<string,mixed>  $item
     * @return array{ref:string,priority:float,requested_chars:int,reason:string}
     */
    private function dropped(string $ref, float $priority, int $estimated, string $reason): array
    {
        return [
            self::FIELD_REF => $ref,
            self::FIELD_PRIORITY => $priority,
            self::FIELD_REQUESTED_CHARS => $estimated,
            self::FIELD_REASON => $reason,
        ];
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function priorityField(array $item): float
    {
        return AiValueNormalizer::finiteFloatOrNull($item[self::FIELD_PRIORITY] ?? 0) ?? 0.0;
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function estimatedChars(array $item): int
    {
        $value = AiValueNormalizer::finiteFloatOrNull($item[self::FIELD_ESTIMATED_CHARS] ?? 0);

        return $value === null ? 0 : max(0, (int) $value);
    }

}
