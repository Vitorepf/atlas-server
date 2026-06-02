<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Cores;

use App\Services\Ai\Aaeos\Cores\MemoryRecallRanker;
use PHPUnit\Framework\TestCase;

final class MemoryRecallRankerTest extends TestCase
{
    private MemoryRecallRanker $ranker;

    protected function setUp(): void
    {
        $this->ranker = new MemoryRecallRanker();
    }

    public function testSchemaVersionIsStable(): void
    {
        $result = $this->ranker->rank([]);

        $this->assertSame('atlas.aaeos.memory_recall_ranking.v1', $result['schema_version']);
    }

    /**
     * (a) global+accepted+canonical A vs task+observation+archived B with LOWER score
     *     -> B before A (scope outranks); ranked[0].id===B.id; B.rank===1 < A.rank.
     */
    public function testScopeOutranksDecisionCanonicalAndScore(): void
    {
        $candidateA = [
            'id' => 'A',
            'scope' => 'global',
            'kind' => 'decision',
            'decision_status' => 'accepted',
            'doc_status' => 'canonical',
            'unresolved_issue' => false,
            'task_relevant' => false,
            'semantic_score' => 0.95,
            'source_ref' => 'doc://global-accepted.md',
        ];
        $candidateB = [
            'id' => 'B',
            'scope' => 'task',
            'kind' => 'observation',
            'decision_status' => 'open',
            'doc_status' => 'archived',
            'unresolved_issue' => false,
            'task_relevant' => false,
            'semantic_score' => 0.10,
            'source_ref' => 'doc://task-observation.md',
        ];

        $result = $this->ranker->rank([$candidateA, $candidateB]);

        $this->assertSame('B', $result['ranked'][0]['id']);
        $this->assertSame(1, $result['ranked'][0]['rank']);
        $this->assertSame('A', $result['ranked'][1]['id']);
        $this->assertSame(2, $result['ranked'][1]['rank']);
        $this->assertTrue($result['ranked'][0]['rank'] < $result['ranked'][1]['rank']);

        $this->assertSame(-1, $this->ranker->compare($candidateB, $candidateA));
        $this->assertSame(1, $this->ranker->compare($candidateA, $candidateB));
    }

    /**
     * (b) same scope, equal score, accepted decision ranks before observation.
     */
    public function testWithinScopeAcceptedDecisionBeatsObservation(): void
    {
        $decision = [
            'id' => 'D',
            'scope' => 'project',
            'kind' => 'decision',
            'decision_status' => 'accepted',
            'doc_status' => 'other',
            'unresolved_issue' => false,
            'task_relevant' => false,
            'semantic_score' => 0.5,
            'source_ref' => 'doc://decision.md',
        ];
        $observation = [
            'id' => 'O',
            'scope' => 'project',
            'kind' => 'observation',
            'decision_status' => 'n/a',
            'doc_status' => 'other',
            'unresolved_issue' => false,
            'task_relevant' => false,
            'semantic_score' => 0.5,
            'source_ref' => 'doc://observation.md',
        ];

        $result = $this->ranker->rank([$observation, $decision]);

        $this->assertSame('D', $result['ranked'][0]['id']);
        $this->assertSame(1, $result['ranked'][0]['rank']);
        $this->assertSame('O', $result['ranked'][1]['id']);
        $this->assertSame(2, $result['ranked'][1]['rank']);
        $this->assertSame(0, $result['ranked'][0]['order_key']['decision_rank']);
        $this->assertSame(1, $result['ranked'][1]['order_key']['decision_rank']);

        $this->assertSame(-1, $this->ranker->compare($decision, $observation));
    }

    /**
     * (c) two task accepted equal-score, canonical before archived.
     */
    public function testCanonicalBeatsArchivedAtEqualHigherTiers(): void
    {
        $canonical = [
            'id' => 'CAN',
            'scope' => 'task',
            'kind' => 'decision',
            'decision_status' => 'accepted',
            'doc_status' => 'canonical',
            'unresolved_issue' => false,
            'task_relevant' => false,
            'semantic_score' => 0.7,
            'source_ref' => 'doc://canonical.md',
        ];
        $archived = [
            'id' => 'ARC',
            'scope' => 'task',
            'kind' => 'decision',
            'decision_status' => 'accepted',
            'doc_status' => 'archived',
            'unresolved_issue' => false,
            'task_relevant' => false,
            'semantic_score' => 0.7,
            'source_ref' => 'doc://archived.md',
        ];

        $result = $this->ranker->rank([$archived, $canonical]);

        $this->assertSame('CAN', $result['ranked'][0]['id']);
        $this->assertSame('ARC', $result['ranked'][1]['id']);
        $this->assertSame(0, $result['ranked'][0]['order_key']['canonical_rank']);
        $this->assertSame(1, $result['ranked'][1]['order_key']['canonical_rank']);

        $this->assertSame(-1, $this->ranker->compare($canonical, $archived));
    }

    /**
     * (d) recent unresolved issue task_relevant=false does NOT outrank a
     *     task_relevant=true peer of equal lower tiers.
     */
    public function testTaskRelevantUnresolvedBeatsNonTaskRelevantUnresolved(): void
    {
        $relevant = [
            'id' => 'REL',
            'scope' => 'project',
            'kind' => 'issue',
            'decision_status' => 'n/a',
            'doc_status' => 'other',
            'unresolved_issue' => true,
            'task_relevant' => true,
            'semantic_score' => 0.4,
            'source_ref' => 'doc://relevant-issue.md',
        ];
        $notRelevant = [
            'id' => 'IRR',
            'scope' => 'project',
            'kind' => 'issue',
            'decision_status' => 'n/a',
            'doc_status' => 'other',
            'unresolved_issue' => true,
            'task_relevant' => false,
            'semantic_score' => 0.4,
            'source_ref' => 'doc://irrelevant-issue.md',
        ];

        $result = $this->ranker->rank([$notRelevant, $relevant]);

        $this->assertSame('REL', $result['ranked'][0]['id']);
        $this->assertSame('IRR', $result['ranked'][1]['id']);
        $this->assertSame(0, $result['ranked'][0]['order_key']['unresolved_rank']);
        $this->assertSame(1, $result['ranked'][1]['order_key']['unresolved_rank']);
        $this->assertTrue($result['ranked'][0]['rank'] < $result['ranked'][1]['rank']);

        $this->assertSame(-1, $this->ranker->compare($relevant, $notRelevant));
    }

    /**
     * (e) identical-tier + equal-score candidates sort by ascending sha1(id/source_ref);
     *     rank() twice on a shuffled copy gives a byte-identical id sequence.
     */
    public function testIdenticalTierSortsByAscendingSha1AndIsDeterministic(): void
    {
        $tieA = [
            'id' => 'tie-a',
            'scope' => 'project',
            'kind' => 'note',
            'decision_status' => 'n/a',
            'doc_status' => 'other',
            'unresolved_issue' => false,
            'task_relevant' => false,
            'semantic_score' => 0.5,
            'source_ref' => 'doc://a.md',
        ];
        $tieB = [
            'id' => 'tie-b',
            'scope' => 'project',
            'kind' => 'note',
            'decision_status' => 'n/a',
            'doc_status' => 'other',
            'unresolved_issue' => false,
            'task_relevant' => false,
            'semantic_score' => 0.5,
            'source_ref' => 'doc://b.md',
        ];
        $tieC = [
            'id' => 'tie-c',
            'scope' => 'project',
            'kind' => 'note',
            'decision_status' => 'n/a',
            'doc_status' => 'other',
            'unresolved_issue' => false,
            'task_relevant' => false,
            'semantic_score' => 0.5,
            'source_ref' => 'doc://c.md',
        ];

        // sha1 tiebreaks:
        //   tie-c/doc://c.md -> 1d36f15a... (smallest)
        //   tie-a/doc://a.md -> 574f6921...
        //   tie-b/doc://b.md -> d51a9c4f... (largest)
        $expectedIds = ['tie-c', 'tie-a', 'tie-b'];

        $resultOne = $this->ranker->rank([$tieA, $tieB, $tieC]);
        $idsOne = array_map(static fn (array $row): string => $row['id'], $resultOne['ranked']);

        $this->assertSame($expectedIds, $idsOne);

        // Shuffled copy must yield the byte-identical ordered id sequence.
        $resultTwo = $this->ranker->rank([$tieB, $tieC, $tieA]);
        $idsTwo = array_map(static fn (array $row): string => $row['id'], $resultTwo['ranked']);

        $this->assertSame($idsOne, $idsTwo);
        $this->assertSame([1, 2, 3], array_map(static fn (array $row): int => $row['rank'], $resultTwo['ranked']));
    }

    /**
     * (f) same kind+source_ref collapses to one; the other lands in deduplicated[]
     *     with kept_id; ranks stay contiguous 1..N.
     */
    public function testDuplicateKindAndSourceRefCollapsesKeepingHighestRanked(): void
    {
        $high = [
            'id' => 'HIGH',
            'scope' => 'task',
            'kind' => 'doc',
            'decision_status' => 'n/a',
            'doc_status' => 'canonical',
            'unresolved_issue' => false,
            'task_relevant' => false,
            'semantic_score' => 0.9,
            'source_ref' => 'doc://shared.md',
        ];
        $duplicate = [
            'id' => 'LOW',
            'scope' => 'task',
            'kind' => 'doc',
            'decision_status' => 'n/a',
            'doc_status' => 'canonical',
            'unresolved_issue' => false,
            'task_relevant' => false,
            'semantic_score' => 0.1,
            'source_ref' => 'doc://shared.md',
        ];
        $other = [
            'id' => 'OTHER',
            'scope' => 'global',
            'kind' => 'note',
            'decision_status' => 'n/a',
            'doc_status' => 'other',
            'unresolved_issue' => false,
            'task_relevant' => false,
            'semantic_score' => 0.3,
            'source_ref' => 'doc://other.md',
        ];

        $result = $this->ranker->rank([$duplicate, $other, $high]);

        $rankedIds = array_map(static fn (array $row): string => $row['id'], $result['ranked']);
        $this->assertSame(['HIGH', 'OTHER'], $rankedIds);
        $this->assertSame([1, 2], array_map(static fn (array $row): int => $row['rank'], $result['ranked']));

        $this->assertCount(1, $result['deduplicated']);
        $this->assertSame('LOW', $result['deduplicated'][0]['id']);
        $this->assertSame('HIGH', $result['deduplicated'][0]['kept_id']);
        $this->assertSame('doc|doc://shared.md', $result['deduplicated'][0]['dedup_key']);
    }

    /**
     * (g) empty input -> ranked:[] with no crash.
     */
    public function testEmptyInputReturnsEmptyRanked(): void
    {
        $result = $this->ranker->rank([]);

        $this->assertSame([], $result['ranked']);
        $this->assertSame([], $result['deduplicated']);
        $this->assertSame('atlas.aaeos.memory_recall_ranking.v1', $result['schema_version']);
    }
}
