<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs\Scoring;

use App\Services\Ai\AgenticEngineeringOs\Scoring\SegmentImportanceRanker;
use PHPUnit\Framework\TestCase;

final class SegmentImportanceRankerTest extends TestCase
{
    private SegmentImportanceRanker $ranker;

    protected function setUp(): void
    {
        $this->ranker = new SegmentImportanceRanker();
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    private function segment(array $overrides): array
    {
        return array_merge([
            'id' => 'seg',
            'kind' => 'fact',
            'recency_rank' => 0,
            'token_estimate' => 10,
            'has_evidence_ref' => false,
            'links_decision_or_blocker' => false,
            'dup_group' => null,
        ], $overrides);
    }

    public function testSchemaVersionAndBudgetAreStable(): void
    {
        $result = $this->ranker->select([], 500);

        $this->assertSame('atlas.aaeos.segment_importance_ranking.v1', $result['schema_version']);
        $this->assertSame(500, $result['token_budget']);
        $this->assertSame([], $result['ranked']);
        $this->assertSame(0, $result['kept_count']);
        $this->assertSame(0, $result['dropped_count']);
    }

    /**
     * (1) low-weight 'fact' recency_rank=0 vs 'decision' recency_rank=5
     *     -> decision ranks ABOVE fact (composite score, not pure recency).
     *     fact   = 0.4 + 0.5/1            = 0.9000
     *     decision = 1.0 + 0.5/6          = 1.0833
     */
    public function testDecisionOutranksNewerFactByComposite(): void
    {
        $fact = $this->segment(['id' => 'fact', 'kind' => 'fact', 'recency_rank' => 0]);
        $decision = $this->segment(['id' => 'decision', 'kind' => 'decision', 'recency_rank' => 5]);

        $result = $this->ranker->select([$fact, $decision], 1000);

        $this->assertSame('decision', $result['ranked'][0]['id']);
        $this->assertSame('fact', $result['ranked'][1]['id']);
        $this->assertSame(1.0833, $result['ranked'][0]['score']);
        $this->assertSame(0.9, $result['ranked'][1]['score']);
        $this->assertTrue($result['ranked'][0]['score'] > $result['ranked'][1]['score']);
    }

    /**
     * (2) two segments same dup_group, identical kind/recency:
     *     second member dedup_penalty == -0.5, ranks strictly below first,
     *     and first.score == second.score + 0.5.
     *     base = 0.1(duplicate) + 0.5/2 = 0.35; second = 0.35 - 0.5 = -0.15.
     */
    public function testSecondDupGroupMemberIsPenalisedByHalf(): void
    {
        $first = $this->segment([
            'id' => 'dup-first',
            'kind' => 'duplicate',
            'recency_rank' => 1,
            'dup_group' => 'g1',
        ]);
        $second = $this->segment([
            'id' => 'dup-second',
            'kind' => 'duplicate',
            'recency_rank' => 1,
            'dup_group' => 'g1',
        ]);

        // Pass second first in input: dedup penalty is assigned in INPUT order,
        // so 'dup-first' must still be the unpenalised member.
        $result = $this->ranker->select([$first, $second], 1000);

        $firstRow = $this->rowById($result['ranked'], 'dup-first');
        $secondRow = $this->rowById($result['ranked'], 'dup-second');

        $this->assertSame(0.0, $firstRow['dedup_penalty']);
        $this->assertSame(-0.5, $secondRow['dedup_penalty']);
        $this->assertSame(0.35, $firstRow['score']);
        $this->assertSame(-0.15, $secondRow['score']);
        $this->assertSame($firstRow['score'], $secondRow['score'] + 0.5);
        $this->assertSame('dup-first', $result['ranked'][0]['id']);
        $this->assertSame('dup-second', $result['ranked'][1]['id']);
    }

    /**
     * Third+ dup_group members keep accumulating the -0.5 step, capped at -1.0.
     */
    public function testDedupPenaltyAccumulatesAndCapsAtOne(): void
    {
        $rows = [
            $this->segment(['id' => 'd0', 'kind' => 'duplicate', 'recency_rank' => 2, 'dup_group' => 'gx']),
            $this->segment(['id' => 'd1', 'kind' => 'duplicate', 'recency_rank' => 2, 'dup_group' => 'gx']),
            $this->segment(['id' => 'd2', 'kind' => 'duplicate', 'recency_rank' => 2, 'dup_group' => 'gx']),
            $this->segment(['id' => 'd3', 'kind' => 'duplicate', 'recency_rank' => 2, 'dup_group' => 'gx']),
        ];

        $result = $this->ranker->select($rows, 1000);

        $this->assertSame(0.0, $this->rowById($result['ranked'], 'd0')['dedup_penalty']);
        $this->assertSame(-0.5, $this->rowById($result['ranked'], 'd1')['dedup_penalty']);
        $this->assertSame(-1.0, $this->rowById($result['ranked'], 'd2')['dedup_penalty']);
        $this->assertSame(-1.0, $this->rowById($result['ranked'], 'd3')['dedup_penalty']);
    }

    /**
     * (3) three token_estimate [40,40,40], budget=90 -> exactly top-2 keep
     *     (80<=90), 3rd drop/'budget_exceeded', boundary_index==2, tokens_kept==80.
     */
    public function testGreedyBudgetWalkKeepsTopTwoAndMarksBoundary(): void
    {
        $a = $this->segment(['id' => 'a', 'kind' => 'fact', 'recency_rank' => 0, 'token_estimate' => 40]);
        $b = $this->segment(['id' => 'b', 'kind' => 'fact', 'recency_rank' => 0, 'token_estimate' => 40]);
        $c = $this->segment(['id' => 'c', 'kind' => 'fact', 'recency_rank' => 0, 'token_estimate' => 40]);

        $result = $this->ranker->select([$a, $b, $c], 90);

        $this->assertSame('keep', $result['ranked'][0]['decision']);
        $this->assertSame('keep', $result['ranked'][1]['decision']);
        $this->assertSame('drop', $result['ranked'][2]['decision']);
        $this->assertSame('budget_exceeded', $result['ranked'][2]['drop_reason']);
        $this->assertNull($result['ranked'][0]['drop_reason']);
        $this->assertSame(2, $result['boundary_index']);
        $this->assertSame(80, $result['tokens_kept']);
        $this->assertSame(['a', 'b'], $result['kept_ids']);
        $this->assertSame(['c'], $result['dropped_ids']);
        $this->assertSame(2, $result['kept_count']);
        $this->assertSame(1, $result['dropped_count']);
        $this->assertSame(10, $result['tokens_available']);
    }

    /**
     * (4) highest-scored segment token_estimate=1000 > budget=100 is dropped as
     *     'oversized_segment' yet a lower-ranked 50-token segment after it still
     *     keeps (greedy-by-rank: ranking continues past the oversized drop).
     */
    public function testOversizedTopSegmentDropsButLowerRankedStillFits(): void
    {
        $oversized = $this->segment([
            'id' => 'oversized',
            'kind' => 'decision',
            'recency_rank' => 0,
            'token_estimate' => 1000,
            'has_evidence_ref' => true,
            'links_decision_or_blocker' => true,
        ]);
        $small = $this->segment([
            'id' => 'small',
            'kind' => 'fact',
            'recency_rank' => 3,
            'token_estimate' => 50,
        ]);

        $result = $this->ranker->select([$small, $oversized], 100);

        $this->assertSame('oversized', $result['ranked'][0]['id']);
        $this->assertSame('drop', $result['ranked'][0]['decision']);
        $this->assertSame('oversized_segment', $result['ranked'][0]['drop_reason']);
        $this->assertSame('small', $result['ranked'][1]['id']);
        $this->assertSame('keep', $result['ranked'][1]['decision']);
        $this->assertNull($result['ranked'][1]['drop_reason']);
        $this->assertSame(['small'], $result['kept_ids']);
        $this->assertSame(['oversized'], $result['dropped_ids']);
        $this->assertSame(50, $result['tokens_kept']);
    }

    /**
     * (5) two 'fact' segments identical except one has has_evidence_ref +
     *     links_decision_or_blocker -> scores exactly +0.50 higher and outranks.
     *     plain    = 0.4 + 0.5/3                 = 0.5667
     *     enriched = 0.4 + 0.5/3 + 0.20 + 0.30   = 1.0667
     */
    public function testEvidenceAndLinkageAddExactlyHalfAndOutrank(): void
    {
        $plain = $this->segment(['id' => 'plain', 'kind' => 'fact', 'recency_rank' => 2]);
        $enriched = $this->segment([
            'id' => 'enriched',
            'kind' => 'fact',
            'recency_rank' => 2,
            'has_evidence_ref' => true,
            'links_decision_or_blocker' => true,
        ]);

        $result = $this->ranker->select([$plain, $enriched], 1000);

        $plainScore = $this->rowById($result['ranked'], 'plain')['score'];
        $enrichedScore = $this->rowById($result['ranked'], 'enriched')['score'];

        $this->assertSame(0.5667, $plainScore);
        $this->assertSame(1.0667, $enrichedScore);
        $this->assertSame(0.5, round($enrichedScore - $plainScore, 4));
        $this->assertSame('enriched', $result['ranked'][0]['id']);
        $this->assertSame('plain', $result['ranked'][1]['id']);
    }

    /**
     * (6) identical-score tie-break by recency_rank ASC then id ASC, and select()
     *     twice on shuffled input yields the byte-identical kept_ids order.
     *
     * Three segments engineered to the same composite score 1.6000 but with
     * distinct recency_rank / id, so only the tie-break chain decides order:
     *   A: rank=0, evidence(0.6) +ev0.20 +lk0.30 + 0.5/1 = 1.6
     *   B: rank=4, decision(1.0) +ev0.20 +lk0.30 + 0.5/5 = 1.6
     *   C: rank=4, decision(1.0) +ev0.20 +lk0.30 + 0.5/5 = 1.6
     * Expected order: A (rank 0) first, then B,C by id asc -> A, seg-b, seg-c.
     */
    public function testIdenticalScoreTieBreakByRecencyThenIdIsDeterministic(): void
    {
        $a = $this->segment([
            'id' => 'seg-a',
            'kind' => 'evidence',
            'recency_rank' => 0,
            'token_estimate' => 5,
            'has_evidence_ref' => true,
            'links_decision_or_blocker' => true,
        ]);
        $b = $this->segment([
            'id' => 'seg-b',
            'kind' => 'decision',
            'recency_rank' => 4,
            'token_estimate' => 5,
            'has_evidence_ref' => true,
            'links_decision_or_blocker' => true,
        ]);
        $c = $this->segment([
            'id' => 'seg-c',
            'kind' => 'decision',
            'recency_rank' => 4,
            'token_estimate' => 5,
            'has_evidence_ref' => true,
            'links_decision_or_blocker' => true,
        ]);

        $resultOne = $this->ranker->select([$a, $b, $c], 10000);

        // All three share the same composite score, proving the ordering below
        // is decided purely by the tie-break chain.
        $this->assertSame(1.6, $resultOne['ranked'][0]['score']);
        $this->assertSame(1.6, $resultOne['ranked'][1]['score']);
        $this->assertSame(1.6, $resultOne['ranked'][2]['score']);

        $this->assertSame(['seg-a', 'seg-b', 'seg-c'], $resultOne['kept_ids']);

        // Shuffled input must yield the byte-identical kept_ids order.
        $resultTwo = $this->ranker->select([$c, $a, $b], 10000);
        $this->assertSame($resultOne['kept_ids'], $resultTwo['kept_ids']);

        // recency_rank ASC: the rank-0 segment leads the two rank-4 segments.
        $this->assertSame(0, $resultOne['ranked'][0]['recency_rank']);
        $this->assertSame(4, $resultOne['ranked'][1]['recency_rank']);
        $this->assertSame(4, $resultOne['ranked'][2]['recency_rank']);
    }

    /**
     * @param  list<array<string,mixed>>  $ranked
     * @return array<string,mixed>
     */
    private function rowById(array $ranked, string $id): array
    {
        foreach ($ranked as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }

        $this->fail("ranked row not found for id {$id}");
    }
}
