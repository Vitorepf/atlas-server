<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\LiveCycle\Nesting;

/**
 * Folds a completed child sub-cycle's outcome back into the parent phase as FACTs only.
 * Atlas-floor I-17/I-18: brain-as-facts — REFUSE any scalar score/grade/rank/rating/quality_score.
 * Pure function: deterministic over (parent, outcome, clock); idempotent by construction.
 */
final class AtlasLoopSubCycleResultMerger
{
    public const FORBIDDEN_SCALAR_KEYS = [
        'score',
        'grade',
        'rank',
        'rating',
        'quality_score',
    ];

    /**
     * @param  callable():string|null  $clock  returns ISO-8601 UTC; null => live gmdate()
     */
    public function __construct(private readonly mixed $clock = null) {}

    /**
     * @param  array<string,mixed>  $childOutcome  enumerable facts only
     */
    public function merge(SubCycleSpawnRecord $parent, array $childOutcome): ParentMergeRecord|RejectionRecord
    {
        $forbidden = $this->findForbiddenKeys($childOutcome);
        if ($forbidden !== []) {
            return new RejectionRecord(
                parentCycleId: $parent->parentCycleId,
                childCycleId: $parent->childCycleId,
                forbiddenKeys: $forbidden,
                reason: 'forbidden_scalar_keys_present',
            );
        }

        $keys = array_keys($childOutcome);
        sort($keys, SORT_STRING);
        $sourceDigest = $this->digest($childOutcome);
        $mergedAt = $this->resolveClock();

        return new ParentMergeRecord(
            parentCycleId: $parent->parentCycleId,
            parentPhase: $parent->parentPhase,
            childCycleId: $parent->childCycleId,
            factCount: count($childOutcome),
            factKeysSorted: $keys,
            mergedAt: $mergedAt,
            sourceDigest: $sourceDigest,
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return list<string>
     */
    private function findForbiddenKeys(array $payload): array
    {
        $found = [];
        $forbidden = array_flip(self::FORBIDDEN_SCALAR_KEYS);
        foreach (array_keys($payload) as $key) {
            if (isset($forbidden[$key])) {
                $found[] = $key;
            }
        }
        sort($found, SORT_STRING);

        return $found;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function digest(array $payload): string
    {
        $this->ksortRecursive($payload);

        return 'sha256:'.hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string,mixed>  $arr
     */
    private function ksortRecursive(array &$arr): void
    {
        ksort($arr, SORT_STRING);
        foreach ($arr as &$v) {
            if (is_array($v)) {
                $this->ksortRecursive($v);
            }
        }
    }

    private function resolveClock(): string
    {
        if (is_callable($this->clock)) {
            return (string) ($this->clock)();
        }

        return gmdate('Y-m-d\TH:i:s\Z');
    }
}

final class ParentMergeRecord
{
    /**
     * @param  list<string>  $factKeysSorted
     */
    public function __construct(
        public readonly string $parentCycleId,
        public readonly string $parentPhase,
        public readonly string $childCycleId,
        public readonly int $factCount,
        public readonly array $factKeysSorted,
        public readonly string $mergedAt,
        public readonly string $sourceDigest,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'parent_cycle_id' => $this->parentCycleId,
            'parent_phase' => $this->parentPhase,
            'child_cycle_id' => $this->childCycleId,
            'fact_count' => $this->factCount,
            'fact_keys_sorted' => $this->factKeysSorted,
            'merged_at' => $this->mergedAt,
            'source_digest' => $this->sourceDigest,
        ];
    }
}

final class RejectionRecord
{
    /**
     * @param  list<string>  $forbiddenKeys
     */
    public function __construct(
        public readonly string $parentCycleId,
        public readonly string $childCycleId,
        public readonly array $forbiddenKeys,
        public readonly string $reason,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'parent_cycle_id' => $this->parentCycleId,
            'child_cycle_id' => $this->childCycleId,
            'forbidden_keys' => $this->forbiddenKeys,
            'reason' => $this->reason,
        ];
    }
}
