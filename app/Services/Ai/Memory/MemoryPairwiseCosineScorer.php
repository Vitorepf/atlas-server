<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory;

/**
 * MAXH-03 — thin adapter around the real vector search so the consolidation
 * scanner can be tested without pgvector. The adapter contract is intentionally
 * small: given a query string and a candidate id set, return `id => cosine`
 * only for rows that have a real embedding. This mirrors
 * {@see AtlasMemoryVectorSearchService::scoreEntries} byte-for-byte on prod.
 *
 * The scanner never fabricates similarity scores. When the environment cannot
 * embed (SQLite tests, missing engine), the adapter returns an empty map — the
 * scanner honestly reports `similarity_source=unavailable`.
 */
interface MemoryPairwiseCosineScorer
{
    /**
     * @param  array<int,string>  $ids
     * @return array<string,float> id => cosine (0..1), only rows with a vector
     */
    public function scoreEntries(string $query, array $ids): array;

    /**
     * Non-throwing readiness probe. `false` means every scoreEntries() call is
     * guaranteed to return an empty map (no pgvector, feature off, etc.), so
     * the scanner can short-circuit and emit an honest empty proposal without
     * pretending a pair was ever evaluated.
     */
    public function available(): bool;
}
