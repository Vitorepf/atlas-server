<?php

declare(strict_types=1);

namespace App\Services\Ai\MemoryGovernance;

use App\Services\Ai\RuntimeBoundary\NearDuplicateRuntimeClient;

/**
 * Detect near-duplicate memory rows via token-shingle Jaccard similarity.
 *
 * GOVERNANCE BOUNDARY: the actual numeric work — the contiguous-bigram shingling,
 * the O(n^2) pairwise Jaccard, the transitive-closure clustering and the
 * deterministic canonical selection — is NO LONGER hand-rolled in this PHP kernel.
 * Per the runtime_language_boundary canon and the operator thesis ("data math
 * belongs in Python; never hand-roll a data engine in PHP; the kernel scanner
 * forbids reimplementing engines in PHP"), this class is now a thin governed
 * delegator: it forwards to the REAL numpy near-duplicate runtime through the
 * signed boundary (NearDuplicateRuntimeClient), which vectorises the pairwise
 * Jaccard as a single matmul and verifies an anti-fake receipt. The old hand-rolled
 * double loop + union-find + Jaccard were REMOVED (proven behaviour-equivalent by
 * the runtime's reference-oracle + the old-vs-new boundary equivalence test).
 *
 * There is NO PHP fallback math: if the runtime is absent the boundary throws
 * honestly (run scripts/setup-near-duplicate-runtime.sh) — a real Python engine or
 * an explicit failure, never a silent stand-in.
 */
final class MemoryNearDuplicateDetector
{
    public const SCHEMA_VERSION = 'atlas.memory_governance.near_duplicate.v1';

    private NearDuplicateRuntimeClient $runtime;

    public function __construct(?NearDuplicateRuntimeClient $runtime = null)
    {
        $this->runtime = $runtime ?? new NearDuplicateRuntimeClient;
    }

    /**
     * Detect near-duplicate memory rows via token-shingle Jaccard similarity.
     *
     * Rows are grouped only when they share the same (memory_type, scope). Within a
     * group, every pair is scored with contiguous bigram-shingle Jaccard similarity;
     * pairs scoring >= $threshold are linked into transitive-closure clusters of size
     * >= 2. A deterministic canonical member is chosen per cluster by priority ->
     * importance -> recency -> lexicographically smallest id. Rows whose token list is
     * empty or whitespace-only are skipped without error.
     *
     * The computation runs in the numpy runtime via the signed boundary; this method
     * only forwards the rows and returns the runtime's result (boundary receipt
     * verified and stripped inside the client).
     *
     * @param  list<array<string,mixed>>  $rows
     * @return array{
     *     schema_version: string,
     *     threshold: float,
     *     evaluated: int,
     *     clusters: list<array{
     *         key: string,
     *         member_ids: list<int|string>,
     *         canonical_id: int|string,
     *         merge_candidate_ids: list<int|string>,
     *         pairs: list<array{a: int|string, b: int|string, similarity: float}>,
     *         max_similarity: float
     *     }>,
     *     cluster_count: int,
     *     duplicate_count: int
     * }
     */
    public function detect(array $rows, float $threshold = 0.82): array
    {
        /** @var array{schema_version: string, threshold: float, evaluated: int, clusters: list<array{key: string, member_ids: list<int|string>, canonical_id: int|string, merge_candidate_ids: list<int|string>, pairs: list<array{a: int|string, b: int|string, similarity: float}>, max_similarity: float}>, cluster_count: int, duplicate_count: int} $result */
        $result = $this->runtime->detect($rows, $threshold);

        return $result;
    }
}
