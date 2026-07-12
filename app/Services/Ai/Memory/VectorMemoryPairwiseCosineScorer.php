<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory;

/**
 * MAXH-03 — production adapter that routes through the REAL
 * {@see AtlasMemoryVectorSearchService::scoreEntries} call — same pgvector query,
 * same embedding provenance, no fabrication. When the vector service is
 * unavailable the underlying service already returns an empty map; we mirror
 * that behavior in `available()` so the scanner can short-circuit honestly.
 */
final class VectorMemoryPairwiseCosineScorer implements MemoryPairwiseCosineScorer
{
    public function __construct(private readonly AtlasMemoryVectorSearchService $vectors) {}

    /**
     * @param  array<int,string>  $ids
     * @return array<string,float>
     */
    public function scoreEntries(string $query, array $ids): array
    {
        return $this->vectors->scoreEntries($query, $ids);
    }

    public function available(): bool
    {
        return $this->vectors->available();
    }
}
