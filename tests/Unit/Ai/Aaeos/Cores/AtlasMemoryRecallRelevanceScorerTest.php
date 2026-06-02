<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Cores;

use App\Services\Ai\Aaeos\Cores\AtlasMemoryRecallRelevanceScorer;
use PHPUnit\Framework\TestCase;

final class AtlasMemoryRecallRelevanceScorerTest extends TestCase
{
    private AtlasMemoryRecallRelevanceScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new AtlasMemoryRecallRelevanceScorer();
    }

    public function testSchemaVersionConstantMatchesByteForByte(): void
    {
        $this->assertSame(
            'atlas.aaeos.memory_recall_ranking.v1',
            AtlasMemoryRecallRelevanceScorer::SCHEMA_VERSION,
        );
    }

    public function testExactRegistryFormulaScore(): void
    {
        $score = $this->scorer->score([
            'priority' => 50,
            'importance' => 3,
            'confidence' => 0.7,
            'hybrid_score' => 0,
            'scope_type' => 'global',
            'type' => 'memory',
        ]);

        // 50 + 30 + 7 + 0 + scopeWeight('global')=4 + typeWeight('memory')=5 = 96.0
        $this->assertSame(96.0, $score);
    }

    public function testHighSignalRegistryRowScoresStrictlyHigherAndRanksFirst(): void
    {
        $strong = [
            'title' => 'StrongDecision',
            'type' => 'decision',
            'scope_type' => 'task',
            'priority' => 80,
            'importance' => 5,
            'confidence' => 0.9,
            'hybrid_score' => 0.5,
        ];

        $weak = [
            'title' => 'WeakPreference',
            'type' => 'preference',
            'scope_type' => 'user',
            'priority' => 40,
            'importance' => 2,
            'confidence' => 0.5,
            'hybrid_score' => 0,
        ];

        $strongScore = $this->scorer->score($strong);
        $weakScore = $this->scorer->score($weak);

        // strong: 80 + 50 + 9 + 15 + 22 + 16 = 192
        $this->assertSame(192.0, $strongScore);
        // weak: 40 + 20 + 5 + 0 + 8 + 8 = 81
        $this->assertSame(81.0, $weakScore);
        $this->assertTrue($strongScore > $weakScore);

        $ranked = $this->scorer->rank([$weak, $strong]);

        $this->assertSame('StrongDecision', $ranked[0]['title']);
        $this->assertSame(1, $ranked[0]['rank']);
        $this->assertSame(192.0, $ranked[0]['relevance_score']);
        $this->assertSame('WeakPreference', $ranked[1]['title']);
        $this->assertSame(2, $ranked[1]['rank']);
    }

    public function testScopeWeightTable(): void
    {
        $this->assertSame(22, $this->scorer->scopeWeight('task'));
        $this->assertSame(4, $this->scorer->scopeWeight('unknown_scope'));
    }

    public function testTypeWeightTable(): void
    {
        $this->assertSame(16, $this->scorer->typeWeight('decision'));
        $this->assertSame(5, $this->scorer->typeWeight('xyz'));
    }

    public function testTiebreakRegistryBeforeVerbatimOnEqualScore(): void
    {
        // Both rows are engineered to score exactly 91.0:
        //   registry: 45 + 30 + 7 + 0 + scopeWeight('global')=4 + typeWeight('memory')=5 = 91
        //   verbatim: 82 + 0 + scopeWeight('global')=4 + typeWeight('memory')=5 = 91
        $registry = [
            'source' => 'registry',
            'title' => 'SharedTitle',
            'type' => 'memory',
            'scope_type' => 'global',
            'priority' => 45,
            'importance' => 3,
            'confidence' => 0.7,
            'hybrid_score' => 0,
        ];

        $verbatim = [
            'source' => 'verbatim',
            'title' => 'SharedTitle',
            'type' => 'memory',
            'scope_type' => 'global',
            'hybrid_score' => 0,
        ];

        $this->assertSame(91.0, $this->scorer->score($registry));
        $this->assertSame(91.0, $this->scorer->score($verbatim));

        $this->assertSame(-1, $this->scorer->compare($registry, $verbatim));
        $this->assertSame(1, $this->scorer->compare($verbatim, $registry));

        $ranked = $this->scorer->rank([$verbatim, $registry]);
        $this->assertSame('registry', $ranked[0]['source']);
        $this->assertSame('verbatim', $ranked[1]['source']);
    }

    public function testTiebreakEqualSourceAndScoreOrdersByTitleStrcmp(): void
    {
        $alpha = [
            'source' => 'registry',
            'title' => 'Alpha',
            'type' => 'memory',
            'scope_type' => 'global',
            'priority' => 50,
            'importance' => 3,
            'confidence' => 0.7,
            'hybrid_score' => 0,
        ];

        $beta = [
            'source' => 'registry',
            'title' => 'beta',
            'type' => 'memory',
            'scope_type' => 'global',
            'priority' => 50,
            'importance' => 3,
            'confidence' => 0.7,
            'hybrid_score' => 0,
        ];

        // Equal score (96.0), equal source: title strcmp decides, 'Alpha' before 'beta'.
        $this->assertSame($this->scorer->score($alpha), $this->scorer->score($beta));
        $this->assertSame(-1, $this->scorer->compare($alpha, $beta));
        $this->assertSame(1, $this->scorer->compare($beta, $alpha));

        $ranked = $this->scorer->rank([$beta, $alpha]);
        $this->assertSame('Alpha', $ranked[0]['title']);
        $this->assertSame('beta', $ranked[1]['title']);
    }

    public function testCompareReturnsZeroForIdenticalRows(): void
    {
        $row = [
            'source' => 'registry',
            'title' => 'Same',
            'type' => 'memory',
            'scope_type' => 'global',
            'priority' => 50,
            'importance' => 3,
            'confidence' => 0.7,
            'hybrid_score' => 0,
        ];

        $this->assertSame(0, $this->scorer->compare($row, $row));
    }

    public function testSemanticBranchUsesScoreTimesHundredPlusTypeWeight(): void
    {
        $semantic = [
            'source' => 'semantic',
            'title' => 'Note',
            'type' => 'decision',
            'score' => 0.8,
        ];

        // 0.8 * 100 + typeWeight('decision')=16 = 96.0
        $this->assertSame(96.0, $this->scorer->score($semantic));
    }

    public function testVerbatimBranchUsesEightyTwoBasePlusHybridWeighting(): void
    {
        $verbatim = [
            'source' => 'verbatim',
            'title' => 'Snippet',
            'type' => 'decision',
            'scope_type' => 'task',
            'hybrid_score' => 0.5,
        ];

        // 82 + 0.5 * 24 + scopeWeight('task')=22 + typeWeight('decision')=16 = 132.0
        $this->assertSame(132.0, $this->scorer->score($verbatim));
    }

    public function testRankIsDeterministicAcrossShuffledInput(): void
    {
        $a = [
            'source' => 'registry',
            'title' => 'Aaa',
            'type' => 'decision',
            'scope_type' => 'task',
            'priority' => 80,
            'importance' => 5,
            'confidence' => 0.9,
            'hybrid_score' => 0.5,
        ];
        $b = [
            'source' => 'verbatim',
            'title' => 'Bbb',
            'type' => 'memory',
            'scope_type' => 'project',
            'hybrid_score' => 0.2,
        ];
        $c = [
            'source' => 'registry',
            'title' => 'Ccc',
            'type' => 'preference',
            'scope_type' => 'user',
            'priority' => 40,
            'importance' => 2,
            'confidence' => 0.5,
            'hybrid_score' => 0,
        ];
        $d = [
            'source' => 'semantic',
            'title' => 'Ddd',
            'type' => 'evidence',
            'score' => 0.6,
        ];

        $firstPass = $this->scorer->rank([$a, $b, $c, $d]);
        $secondPass = $this->scorer->rank([$d, $b, $c, $a]);

        $firstOrder = array_map(static fn (array $row): string => $row['title'], $firstPass);
        $secondOrder = array_map(static fn (array $row): string => $row['title'], $secondPass);

        $this->assertSame($firstOrder, $secondOrder);

        $firstRanks = array_map(static fn (array $row): int => $row['rank'], $firstPass);
        $this->assertSame([1, 2, 3, 4], $firstRanks);
    }
}
