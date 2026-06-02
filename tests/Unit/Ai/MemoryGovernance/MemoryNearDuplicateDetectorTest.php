<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\MemoryGovernance;

use App\Services\Ai\MemoryGovernance\MemoryNearDuplicateDetector;
use PHPUnit\Framework\TestCase;

final class MemoryNearDuplicateDetectorTest extends TestCase
{
    private MemoryNearDuplicateDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new MemoryNearDuplicateDetector();
    }

    public function testSchemaVersionAndThresholdEcho(): void
    {
        $result = $this->detector->detect([], 0.82);

        $this->assertSame('atlas.memory_governance.near_duplicate.v1', $result['schema_version']);
        $this->assertSame(0.82, $result['threshold']);
        $this->assertSame(0, $result['evaluated']);
        $this->assertSame([], $result['clusters']);
        $this->assertSame(0, $result['cluster_count']);
        $this->assertSame(0, $result['duplicate_count']);
    }

    public function testThresholdBoundaryUsesGreaterOrEqual(): void
    {
        // pair_equal Jaccard == 0.60 (must cluster, proving >= not >);
        // pair_below Jaccard == 0.3333 (one shared shingle fewer, must NOT cluster).
        $rows = [
            ['id' => 'eq-a', 'tokens' => ['a', 'b', 'c', 'd', 'e'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 5, 'importance' => 1, 'recency' => 1],
            ['id' => 'eq-b', 'tokens' => ['a', 'b', 'c', 'd', 'f'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 'lo-a', 'tokens' => ['m', 'n', 'o', 'p', 'q'], 'memory_type' => 'learning', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 'lo-b', 'tokens' => ['m', 'n', 'z', 'p', 'q'], 'memory_type' => 'learning', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
        ];

        $result = $this->detector->detect($rows, 0.6);

        $this->assertSame(1, $result['cluster_count']);
        $cluster = $result['clusters'][0];
        $this->assertSame(['eq-a', 'eq-b'], $cluster['member_ids']);
        $this->assertSame(0.6, $cluster['max_similarity']);
        $this->assertSame('decision::global', $cluster['key']);

        // The below-threshold (0.3333) learning pair never forms a cluster.
        $this->assertNotContains('lo-a', $this->allMemberIds($result));
        $this->assertNotContains('lo-b', $this->allMemberIds($result));
    }

    public function testTransitiveClosureMergesChainIntoSingleCluster(): void
    {
        // A~B = 0.5, B~C = 0.5 (both >= threshold), A~C = 0.2 (< threshold).
        $rows = [
            ['id' => 'A', 'tokens' => ['a', 'b', 'c', 'd'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 'B', 'tokens' => ['a', 'b', 'c', 'x'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 'C', 'tokens' => ['y', 'b', 'c', 'x'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
        ];

        $result = $this->detector->detect($rows, 0.5);

        $this->assertSame(1, $result['cluster_count']);
        $cluster = $result['clusters'][0];
        $this->assertSame(['A', 'B', 'C'], $cluster['member_ids']);
        $this->assertSame(0.5, $cluster['max_similarity']);

        // A~C is below threshold, so the direct A-C pair must be absent.
        $pairKeys = array_map(
            static fn (array $pair): string => $pair['a'].'-'.$pair['b'],
            $cluster['pairs'],
        );
        $this->assertContains('A-B', $pairKeys);
        $this->assertContains('B-C', $pairKeys);
        $this->assertNotContains('A-C', $pairKeys);
        $this->assertCount(2, $cluster['pairs']);
    }

    public function testCanonicalChosenByHighestPriority(): void
    {
        // B has strictly highest priority -> B is canonical, the rest are candidates.
        $rows = [
            ['id' => 'A', 'tokens' => ['a', 'b', 'c', 'd'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 3, 'importance' => 9, 'recency' => 9],
            ['id' => 'B', 'tokens' => ['a', 'b', 'c', 'x'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 7, 'importance' => 1, 'recency' => 1],
            ['id' => 'C', 'tokens' => ['y', 'b', 'c', 'x'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 5, 'importance' => 5, 'recency' => 5],
        ];

        $result = $this->detector->detect($rows, 0.5);

        $this->assertSame(1, $result['cluster_count']);
        $cluster = $result['clusters'][0];
        $this->assertSame('B', $cluster['canonical_id']);
        $this->assertSame(['A', 'C'], $cluster['merge_candidate_ids']);
    }

    public function testCanonicalTieBreakPicksLexicographicallySmallestId(): void
    {
        // Equal priority + importance + recency -> lexicographically smallest id wins.
        $rows = [
            ['id' => 'mem-b', 'tokens' => ['a', 'b', 'c', 'd', 'e'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 4, 'importance' => 4, 'recency' => 4],
            ['id' => 'mem-a', 'tokens' => ['a', 'b', 'c', 'd', 'f'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 4, 'importance' => 4, 'recency' => 4],
        ];

        $result = $this->detector->detect($rows, 0.6);

        $this->assertSame(1, $result['cluster_count']);
        $cluster = $result['clusters'][0];
        $this->assertSame('mem-a', $cluster['canonical_id']);
        $this->assertSame(['mem-b'], $cluster['merge_candidate_ids']);
    }

    public function testScopeIsolationYieldsZeroClusters(): void
    {
        // Identical token lists but different (memory_type, scope) must not group.
        $rows = [
            ['id' => 'x1', 'tokens' => ['a', 'b', 'c', 'd', 'e'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 'x2', 'tokens' => ['a', 'b', 'c', 'd', 'e'], 'memory_type' => 'learning', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 'x3', 'tokens' => ['a', 'b', 'c', 'd', 'e'], 'memory_type' => 'decision', 'scope' => 'project', 'priority' => 1, 'importance' => 1, 'recency' => 1],
        ];

        $result = $this->detector->detect($rows, 0.5);

        $this->assertSame(0, $result['cluster_count']);
        $this->assertSame([], $result['clusters']);
        $this->assertSame(0, $result['duplicate_count']);
        $this->assertSame(3, $result['evaluated']);
    }

    public function testOneTokenDifferenceDetectedAndEmptyRowsSkipped(): void
    {
        // Bodies differ by a single token: sha256 would split them, shingle-Jaccard
        // catches them with threshold < similarity < 1.0 (0.5 < 0.6 < 1.0).
        $rows = [
            ['id' => 'near-1', 'tokens' => ['a', 'b', 'c', 'd', 'e'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 'near-2', 'tokens' => ['a', 'b', 'c', 'd', 'f'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 'empty-1', 'tokens' => [], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 9, 'importance' => 9, 'recency' => 9],
            ['id' => 'blank-1', 'tokens' => ['   ', ''], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 9, 'importance' => 9, 'recency' => 9],
        ];

        $result = $this->detector->detect($rows, 0.5);

        $this->assertSame(2, $result['evaluated']);
        $this->assertSame(1, $result['cluster_count']);

        $cluster = $result['clusters'][0];
        $this->assertSame(['near-1', 'near-2'], $cluster['member_ids']);
        $this->assertGreaterThan(0.5, $cluster['max_similarity']);
        $this->assertLessThan(1.0, $cluster['max_similarity']);
        $this->assertSame(0.6, $cluster['max_similarity']);

        $this->assertNotContains('empty-1', $this->allMemberIds($result));
        $this->assertNotContains('blank-1', $this->allMemberIds($result));
    }

    public function testClustersOrderedByMaxSimilarityDescThenKeyAsc(): void
    {
        // Cluster 'alpha::global' = identical pair (1.0); cluster 'beta::global' = 0.6.
        // Higher max_similarity must be emitted first.
        $rows = [
            ['id' => 'b1', 'tokens' => ['a', 'b', 'c', 'd', 'e'], 'memory_type' => 'beta', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 'b2', 'tokens' => ['a', 'b', 'c', 'd', 'f'], 'memory_type' => 'beta', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 'a1', 'tokens' => ['p', 'q', 'r', 's'], 'memory_type' => 'alpha', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 'a2', 'tokens' => ['p', 'q', 'r', 's'], 'memory_type' => 'alpha', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
        ];

        $result = $this->detector->detect($rows, 0.5);

        $this->assertSame(2, $result['cluster_count']);
        $this->assertSame(1.0, $result['clusters'][0]['max_similarity']);
        $this->assertSame('alpha::global', $result['clusters'][0]['key']);
        $this->assertSame(0.6, $result['clusters'][1]['max_similarity']);
        $this->assertSame('beta::global', $result['clusters'][1]['key']);

        // duplicate_count = sum of merge_candidate_ids across both clusters (1 + 1).
        $this->assertSame(2, $result['duplicate_count']);
    }

    public function testKeyTieBreakAscendingWhenMaxSimilarityEqual(): void
    {
        // Two clusters with identical max_similarity (1.0) but different keys must be
        // ordered by key ascending: 'k-a::global' before 'k-b::global'.
        $rows = [
            ['id' => 'p1', 'tokens' => ['a', 'b', 'c', 'd'], 'memory_type' => 'k-b', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 'p2', 'tokens' => ['a', 'b', 'c', 'd'], 'memory_type' => 'k-b', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 'q1', 'tokens' => ['e', 'f', 'g', 'h'], 'memory_type' => 'k-a', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 'q2', 'tokens' => ['e', 'f', 'g', 'h'], 'memory_type' => 'k-a', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
        ];

        $result = $this->detector->detect($rows, 0.5);

        $this->assertSame(2, $result['cluster_count']);
        $this->assertSame(1.0, $result['clusters'][0]['max_similarity']);
        $this->assertSame(1.0, $result['clusters'][1]['max_similarity']);
        $this->assertSame('k-a::global', $result['clusters'][0]['key']);
        $this->assertSame('k-b::global', $result['clusters'][1]['key']);
    }

    public function testDeterministicAcrossRepeatedRuns(): void
    {
        $rows = [
            ['id' => 'A', 'tokens' => ['a', 'b', 'c', 'd'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 'B', 'tokens' => ['a', 'b', 'c', 'x'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 'C', 'tokens' => ['y', 'b', 'c', 'x'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
        ];

        $first = $this->detector->detect($rows, 0.5);
        $second = $this->detector->detect($rows, 0.5);

        $this->assertSame($first, $second);
    }

    /**
     * @param  array<string,mixed>  $result
     * @return list<int|string>
     */
    private function allMemberIds(array $result): array
    {
        $ids = [];

        foreach ($result['clusters'] as $cluster) {
            foreach ($cluster['member_ids'] as $memberId) {
                $ids[] = $memberId;
            }
        }

        return $ids;
    }
}
