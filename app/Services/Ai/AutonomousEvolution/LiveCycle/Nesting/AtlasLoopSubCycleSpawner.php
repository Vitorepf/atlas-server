<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\LiveCycle\Nesting;


use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
/**
 * Spawns bounded child sub-cycles for a parent phase (e.g. ARCHITECT requests a research
 * mini-cycle). Pure service: no I/O on construct, all persistence is left to downstream
 * consumers. Fail-CLOSED on depth-cap: returns a refusal record, never throws.
 */
final class AtlasLoopSubCycleSpawner
{
    use KsortsArraysByReference;

    public const DEFAULT_MAX_DEPTH = 2;

    /**
     * @param  callable():int|null  $maxDepthResolver  resolves the configured cap; if null, the
     *                                                 spawner falls back to config('atlas.loop.nesting.max_depth', 2)
     *                                                 or DEFAULT_MAX_DEPTH if Laravel is unavailable
     * @param  callable():string|null  $clock           returns ISO-8601 UTC; null => gmdate live clock
     */
    public function __construct(
        private readonly mixed $maxDepthResolver = null,
        private readonly mixed $clock = null,
    ) {}

    /**
     * @param  array{cycle_id:string,phase:string,depth?:int}  $parent
     * @param  array<string,mixed>  $childScope
     * @param  int|null  $childIndex  monotonic per-parent index supplied by caller; null => 0
     */
    public function spawn(array $parent, array $childScope, ?int $childIndex = null): SubCycleSpawnRecord
    {
        $parentCycleId = (string) ($parent['cycle_id'] ?? '');
        $parentPhase = (string) ($parent['phase'] ?? '');
        $parentDepth = (int) ($parent['depth'] ?? 0);
        $childIndex = $childIndex ?? 0;

        $maxDepth = $this->resolveMaxDepth();
        $childDepth = $parentDepth + 1;
        $spawnedAt = $this->resolveClock();

        $childCycleId = $this->deterministicChildId($parentCycleId, $parentPhase, $childIndex);
        $scopeDigest = $this->scopeDigest($childScope);

        if ($parentCycleId === '' || $parentPhase === '') {
            return SubCycleSpawnRecord::refusal(
                parentCycleId: $parentCycleId,
                parentPhase: $parentPhase,
                childCycleId: $childCycleId,
                depth: $childDepth,
                spawnedAt: $spawnedAt,
                scopeDigest: $scopeDigest,
                reason: 'missing_parent_identity',
            );
        }
        if ($childDepth > $maxDepth) {
            return SubCycleSpawnRecord::refusal(
                parentCycleId: $parentCycleId,
                parentPhase: $parentPhase,
                childCycleId: $childCycleId,
                depth: $childDepth,
                spawnedAt: $spawnedAt,
                scopeDigest: $scopeDigest,
                reason: 'depth_cap_exceeded:parent_depth+1='.$childDepth.' > max='.$maxDepth,
            );
        }

        return SubCycleSpawnRecord::granted(
            parentCycleId: $parentCycleId,
            parentPhase: $parentPhase,
            childCycleId: $childCycleId,
            depth: $childDepth,
            spawnedAt: $spawnedAt,
            scopeDigest: $scopeDigest,
        );
    }

    private function deterministicChildId(string $parentCycleId, string $phase, int $childIndex): string
    {
        return $parentCycleId.'|'.$phase.'|'.$childIndex;
    }

    /**
     * @param  array<string,mixed>  $scope
     */
    private function scopeDigest(array $scope): string
    {
        $this->ksortRecursiveByReference($scope);

        return 'sha256:'.hash('sha256', (string) json_encode($scope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }


    private function resolveMaxDepth(): int
    {
        if (is_callable($this->maxDepthResolver)) {
            return max(1, (int) ($this->maxDepthResolver)());
        }
        if (function_exists('config')) {
            return max(1, (int) config('atlas.loop.nesting.max_depth', self::DEFAULT_MAX_DEPTH));
        }

        return self::DEFAULT_MAX_DEPTH;
    }

    private function resolveClock(): string
    {
        if (is_callable($this->clock)) {
            return (string) ($this->clock)();
        }

        return gmdate('Y-m-d\TH:i:s\Z');
    }
}

/**
 * FACT-only spawn record consumed by downstream packets P2/P3.
 */
final class SubCycleSpawnRecord
{
    public function __construct(
        public readonly bool $granted,
        public readonly string $parentCycleId,
        public readonly string $parentPhase,
        public readonly string $childCycleId,
        public readonly int $depth,
        public readonly string $spawnedAt,
        public readonly string $scopeDigest,
        public readonly ?string $refusalReason = null,
    ) {}

    public static function granted(
        string $parentCycleId,
        string $parentPhase,
        string $childCycleId,
        int $depth,
        string $spawnedAt,
        string $scopeDigest,
    ): self {
        return new self(
            granted: true,
            parentCycleId: $parentCycleId,
            parentPhase: $parentPhase,
            childCycleId: $childCycleId,
            depth: $depth,
            spawnedAt: $spawnedAt,
            scopeDigest: $scopeDigest,
        );
    }

    public static function refusal(
        string $parentCycleId,
        string $parentPhase,
        string $childCycleId,
        int $depth,
        string $spawnedAt,
        string $scopeDigest,
        string $reason,
    ): self {
        return new self(
            granted: false,
            parentCycleId: $parentCycleId,
            parentPhase: $parentPhase,
            childCycleId: $childCycleId,
            depth: $depth,
            spawnedAt: $spawnedAt,
            scopeDigest: $scopeDigest,
            refusalReason: $reason,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'granted' => $this->granted,
            'parent_cycle_id' => $this->parentCycleId,
            'parent_phase' => $this->parentPhase,
            'child_cycle_id' => $this->childCycleId,
            'depth' => $this->depth,
            'spawned_at' => $this->spawnedAt,
            'scope_digest' => $this->scopeDigest,
            'refusal_reason' => $this->refusalReason,
        ];
    }
}
