<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\MemoryGovernance;

use App\Services\Ai\MemoryGovernance\MemoryNearDuplicateDetector;
use App\Services\Ai\RuntimeBoundary\NearDuplicateRuntimeClient;
use Tests\TestCase;

/**
 * The near-duplicate math now lives in the REAL numpy runtime (the runtime
 * exhaustively unit-tests the engine + an old-vs-new reference oracle). This PHP
 * test proves the governed delegator (MemoryNearDuplicateDetector ->
 * NearDuplicateRuntimeClient -> python venv) returns the SAME behaviour end-to-end:
 * grouping, >=threshold linking, transitive closure, canonical selection, ordering,
 * empty-row skip, and the integer-id ordering split (member_ids strcmp vs pairs
 * PHP-`<=>`). It is gated on the runtime being set up (honest skip when absent — no
 * PHP fallback math exists anymore by design).
 */
final class MemoryNearDuplicateDetectorTest extends TestCase
{
    private MemoryNearDuplicateDetector $detector;

    protected function setUp(): void
    {
        parent::setUp();

        if (! (new NearDuplicateRuntimeClient)->available()) {
            $this->markTestSkipped('near_duplicate runtime not set up — honest skip (math is Python-only, no PHP fallback).');
        }

        $this->detector = new MemoryNearDuplicateDetector;
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

        $this->assertNotContains('lo-a', $this->allMemberIds($result));
        $this->assertNotContains('lo-b', $this->allMemberIds($result));
    }

    public function testTransitiveClosureMergesChainIntoSingleCluster(): void
    {
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

        $this->assertSame(2, $result['duplicate_count']);
    }

    public function testKeyTieBreakAscendingWhenMaxSimilarityEqual(): void
    {
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

    public function testIntegerIdOrderingSplitMemberStrcmpVsPairSpaceship(): void
    {
        // The byte-identity edge the boundary equivalence proof locked: member_ids
        // use PHP strcmp ('1' < '10' < '2'), but pairs use PHP `<=>` which is
        // numeric for numeric strings ((1,2) before (1,10) before (2,10)).
        $rows = [
            ['id' => 1, 'tokens' => ['a', 'b', 'c', 'd', 'e'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 2, 'tokens' => ['a', 'b', 'c', 'd', 'e'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
            ['id' => 10, 'tokens' => ['a', 'b', 'c', 'd', 'e'], 'memory_type' => 'decision', 'scope' => 'global', 'priority' => 1, 'importance' => 1, 'recency' => 1],
        ];

        $result = $this->detector->detect($rows, 0.5);

        $this->assertSame(1, $result['cluster_count']);
        $cluster = $result['clusters'][0];
        $this->assertSame([1, 10, 2], $cluster['member_ids']);
        $this->assertSame(1, $cluster['canonical_id']);

        $pairOrder = array_map(
            static fn (array $pair): string => $pair['a'].','.$pair['b'],
            $cluster['pairs'],
        );
        $this->assertSame(['1,2', '1,10', '2,10'], $pairOrder);
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
