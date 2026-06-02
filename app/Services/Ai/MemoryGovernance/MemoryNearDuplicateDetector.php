<?php

declare(strict_types=1);

namespace App\Services\Ai\MemoryGovernance;

final class MemoryNearDuplicateDetector
{
    private const SCHEMA_VERSION = 'atlas.memory_governance.near_duplicate.v1';

    private const SIMILARITY_PRECISION = 4;

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
        $valid = $this->normalizeRows($rows);

        $groups = $this->groupByMemoryScope($valid);

        $clusters = [];

        foreach ($groups as $group) {
            foreach ($this->clustersForGroup($group, $threshold) as $cluster) {
                $clusters[] = $cluster;
            }
        }

        usort($clusters, function (array $left, array $right): int {
            return [$right['max_similarity'], $left['key'], (string) $left['canonical_id']]
                <=> [$left['max_similarity'], $right['key'], (string) $right['canonical_id']];
        });

        $duplicateCount = 0;
        foreach ($clusters as $cluster) {
            $duplicateCount += count($cluster['merge_candidate_ids']);
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'threshold' => $threshold,
            'evaluated' => count($valid),
            'clusters' => $clusters,
            'cluster_count' => count($clusters),
            'duplicate_count' => $duplicateCount,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<array{id: int|string, shingles: array<string,true>, memory_type: string, scope: string, priority: int, importance: int, recency: int}>
     */
    private function normalizeRows(array $rows): array
    {
        $valid = [];

        foreach ($rows as $row) {
            $tokens = $this->cleanTokens($row['tokens'] ?? []);

            if ($tokens === []) {
                continue;
            }

            $valid[] = [
                'id' => $this->idOf($row),
                'shingles' => $this->shingles($tokens),
                'memory_type' => (string) ($row['memory_type'] ?? ''),
                'scope' => (string) ($row['scope'] ?? ''),
                'priority' => (int) ($row['priority'] ?? 0),
                'importance' => (int) ($row['importance'] ?? 0),
                'recency' => (int) ($row['recency'] ?? 0),
            ];
        }

        return $valid;
    }

    /**
     * @param  mixed  $tokens
     * @return list<string>
     */
    private function cleanTokens(mixed $tokens): array
    {
        if (! is_array($tokens)) {
            return [];
        }

        $clean = [];

        foreach ($tokens as $token) {
            $trimmed = trim((string) $token);

            if ($trimmed === '') {
                continue;
            }

            $clean[] = $trimmed;
        }

        return $clean;
    }

    /**
     * Build the contiguous bigram-shingle set for a cleaned token list. A lone token
     * degrades to a single 1-shingle so any non-skipped row owns a non-empty set.
     *
     * @param  list<string>  $tokens
     * @return array<string,true>
     */
    private function shingles(array $tokens): array
    {
        $count = count($tokens);

        if ($count === 1) {
            return [$tokens[0] => true];
        }

        $shingles = [];

        for ($i = 0; $i < $count - 1; $i++) {
            $shingles[$tokens[$i].'|'.$tokens[$i + 1]] = true;
        }

        return $shingles;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return int|string
     */
    private function idOf(array $row): int|string
    {
        $id = $row['id'] ?? '';

        return is_int($id) ? $id : (string) $id;
    }

    /**
     * @param  list<array{id: int|string, shingles: array<string,true>, memory_type: string, scope: string, priority: int, importance: int, recency: int}>  $rows
     * @return list<list<array{id: int|string, shingles: array<string,true>, memory_type: string, scope: string, priority: int, importance: int, recency: int}>>
     */
    private function groupByMemoryScope(array $rows): array
    {
        $groups = [];

        foreach ($rows as $row) {
            $groups[$this->groupKey($row['memory_type'], $row['scope'])][] = $row;
        }

        return array_values($groups);
    }

    private function groupKey(string $memoryType, string $scope): string
    {
        return $memoryType.'::'.$scope;
    }

    /**
     * @param  list<array{id: int|string, shingles: array<string,true>, memory_type: string, scope: string, priority: int, importance: int, recency: int}>  $group
     * @return list<array{key: string, member_ids: list<int|string>, canonical_id: int|string, merge_candidate_ids: list<int|string>, pairs: list<array{a: int|string, b: int|string, similarity: float}>, max_similarity: float}>
     */
    private function clustersForGroup(array $group, float $threshold): array
    {
        $size = count($group);

        if ($size < 2) {
            return [];
        }

        $parent = range(0, $size - 1);
        $qualifyingPairs = [];

        for ($i = 0; $i < $size; $i++) {
            for ($j = $i + 1; $j < $size; $j++) {
                $similarity = $this->jaccard($group[$i]['shingles'], $group[$j]['shingles']);

                if ($similarity >= $threshold) {
                    $this->union($parent, $i, $j);
                    $qualifyingPairs[] = [
                        'i' => $i,
                        'j' => $j,
                        'similarity' => round($similarity, self::SIMILARITY_PRECISION),
                    ];
                }
            }
        }

        $membersByRoot = [];
        for ($i = 0; $i < $size; $i++) {
            $membersByRoot[$this->find($parent, $i)][] = $i;
        }

        $clusters = [];

        foreach ($membersByRoot as $indices) {
            if (count($indices) < 2) {
                continue;
            }

            $clusters[] = $this->buildCluster($group, $indices, $qualifyingPairs);
        }

        return $clusters;
    }

    /**
     * @param  list<array{id: int|string, shingles: array<string,true>, memory_type: string, scope: string, priority: int, importance: int, recency: int}>  $group
     * @param  list<int>  $indices
     * @param  list<array{i: int, j: int, similarity: float}>  $qualifyingPairs
     * @return array{key: string, member_ids: list<int|string>, canonical_id: int|string, merge_candidate_ids: list<int|string>, pairs: list<array{a: int|string, b: int|string, similarity: float}>, max_similarity: float}
     */
    private function buildCluster(array $group, array $indices, array $qualifyingPairs): array
    {
        $indexSet = array_fill_keys($indices, true);

        $memberIds = [];
        foreach ($indices as $index) {
            $memberIds[] = $group[$index]['id'];
        }
        usort($memberIds, fn ($a, $b): int => strcmp((string) $a, (string) $b));

        $canonicalIndex = $this->canonicalIndex($group, $indices);

        $pairs = [];
        $maxSimilarity = 0.0;
        foreach ($qualifyingPairs as $pair) {
            if (! isset($indexSet[$pair['i']], $indexSet[$pair['j']])) {
                continue;
            }

            $pairs[] = [
                'a' => $group[$pair['i']]['id'],
                'b' => $group[$pair['j']]['id'],
                'similarity' => $pair['similarity'],
            ];

            if ($pair['similarity'] > $maxSimilarity) {
                $maxSimilarity = $pair['similarity'];
            }
        }

        usort($pairs, function (array $left, array $right): int {
            return [(string) $left['a'], (string) $left['b']]
                <=> [(string) $right['a'], (string) $right['b']];
        });

        $canonicalId = $group[$canonicalIndex]['id'];

        $mergeCandidateIds = [];
        foreach ($memberIds as $memberId) {
            if ((string) $memberId === (string) $canonicalId) {
                continue;
            }

            $mergeCandidateIds[] = $memberId;
        }

        return [
            'key' => $this->groupKey($group[$canonicalIndex]['memory_type'], $group[$canonicalIndex]['scope']),
            'member_ids' => $memberIds,
            'canonical_id' => $canonicalId,
            'merge_candidate_ids' => $mergeCandidateIds,
            'pairs' => $pairs,
            'max_similarity' => round($maxSimilarity, self::SIMILARITY_PRECISION),
        ];
    }

    /**
     * Pick the canonical member: highest priority, then importance, then recency,
     * then the lexicographically smallest id.
     *
     * @param  list<array{id: int|string, shingles: array<string,true>, memory_type: string, scope: string, priority: int, importance: int, recency: int}>  $group
     * @param  list<int>  $indices
     */
    private function canonicalIndex(array $group, array $indices): int
    {
        $best = $indices[0];

        foreach ($indices as $index) {
            if ($this->outranks($group[$index], $group[$best])) {
                $best = $index;
            }
        }

        return $best;
    }

    /**
     * @param  array{id: int|string, priority: int, importance: int, recency: int}  $candidate
     * @param  array{id: int|string, priority: int, importance: int, recency: int}  $incumbent
     */
    private function outranks(array $candidate, array $incumbent): bool
    {
        if ($candidate['priority'] !== $incumbent['priority']) {
            return $candidate['priority'] > $incumbent['priority'];
        }

        if ($candidate['importance'] !== $incumbent['importance']) {
            return $candidate['importance'] > $incumbent['importance'];
        }

        if ($candidate['recency'] !== $incumbent['recency']) {
            return $candidate['recency'] > $incumbent['recency'];
        }

        return strcmp((string) $candidate['id'], (string) $incumbent['id']) < 0;
    }

    /**
     * @param  array<string,true>  $left
     * @param  array<string,true>  $right
     */
    private function jaccard(array $left, array $right): float
    {
        if ($left === [] && $right === []) {
            return 0.0;
        }

        $intersection = 0;
        foreach ($left as $shingle => $_) {
            if (isset($right[$shingle])) {
                $intersection++;
            }
        }

        $union = count($left) + count($right) - $intersection;

        if ($union === 0) {
            return 0.0;
        }

        return $intersection / $union;
    }

    /**
     * @param  list<int>  $parent
     */
    private function find(array &$parent, int $node): int
    {
        while ($parent[$node] !== $node) {
            $parent[$node] = $parent[$parent[$node]];
            $node = $parent[$node];
        }

        return $node;
    }

    /**
     * @param  list<int>  $parent
     */
    private function union(array &$parent, int $a, int $b): void
    {
        $rootA = $this->find($parent, $a);
        $rootB = $this->find($parent, $b);

        if ($rootA === $rootB) {
            return;
        }

        if ($rootA < $rootB) {
            $parent[$rootB] = $rootA;

            return;
        }

        $parent[$rootA] = $rootB;
    }
}
