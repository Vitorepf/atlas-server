<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Evidence;

use App\Services\Ai\Evidence\EvidenceStrengthScorer;
use PHPUnit\Framework\TestCase;

final class EvidenceStrengthScorerTest extends TestCase
{
    private EvidenceStrengthScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new EvidenceStrengthScorer();
    }

    public function testReturnShapeMatchesSchema(): void
    {
        $result = $this->scorer->score([['kind' => 'doc']]);

        $this->assertSame('atlas.aaeos.evidence_strength.v1', $result['schema_version']);
        $this->assertSame(1, $result['score']);
        $this->assertSame('weak', $result['tier']);
        $this->assertSame(['doc' => 1], $result['kind_breakdown']);
    }

    public function testFourDocLinksNoLongerReachStrong(): void
    {
        $result = $this->scorer->score([
            ['kind' => 'doc'],
            ['kind' => 'doc'],
            ['kind' => 'doc'],
            ['kind' => 'doc'],
        ]);

        $this->assertSame(4, $result['score']);
        $this->assertSame('moderate', $result['tier']);
        $this->assertSame(['doc' => 4], $result['kind_breakdown']);
    }

    public function testSingleGreenGateRunBeatsOldCountOnlyWeak(): void
    {
        $result = $this->scorer->score([['kind' => 'gate_run']]);

        $this->assertSame(5, $result['score']);
        $this->assertSame('moderate', $result['tier']);
        $this->assertSame(['gate_run' => 1], $result['kind_breakdown']);
    }

    public function testEmptyRefsAreInvalid(): void
    {
        $result = $this->scorer->score([]);

        $this->assertSame(0, $result['score']);
        $this->assertSame('invalid', $result['tier']);
        $this->assertSame([], $result['kind_breakdown']);
    }

    public function testUnknownKindBlankStringAndNonRefAllContributeZero(): void
    {
        $result = $this->scorer->score([['kind' => 'unknown'], '', 123]);

        $this->assertSame(0, $result['score']);
        $this->assertSame('invalid', $result['tier']);
        // The blank string and the non-ref int are SKIPPED (not counted): only the
        // explicit unknown-kind array appears in the breakdown. This discriminates
        // "skipped" from "counted with 0 weight" and proves the skip rule.
        $this->assertSame(['unknown' => 1], $result['kind_breakdown']);
    }

    public function testSingleReceiptIsWeakLowerOfRange(): void
    {
        $result = $this->scorer->score([['kind' => 'receipt']]);

        $this->assertSame(2, $result['score']);
        $this->assertSame('weak', $result['tier']);
    }

    public function testSingleCommitHashIsModerateLowerEdge(): void
    {
        $result = $this->scorer->score([['kind' => 'commit_hash']]);

        $this->assertSame(3, $result['score']);
        $this->assertSame('moderate', $result['tier']);
    }

    public function testTestResultPlusCommitHashIsStrong(): void
    {
        $result = $this->scorer->score([
            ['kind' => 'test_result'],
            ['kind' => 'commit_hash'],
        ]);

        $this->assertSame(8, $result['score']);
        $this->assertSame('strong', $result['tier']);
    }

    public function testStrongLowerEdgeIsScoreSeven(): void
    {
        $result = $this->scorer->score([
            ['kind' => 'benchmark_weight'],
            ['kind' => 'commit_hash'],
        ]);

        $this->assertSame(7, $result['score']);
        $this->assertSame('strong', $result['tier']);
    }

    public function testModerateUpperEdgeIsScoreSix(): void
    {
        $result = $this->scorer->score([
            ['kind' => 'benchmark_weight'],
            ['kind' => 'receipt'],
        ]);

        $this->assertSame(6, $result['score']);
        $this->assertSame('moderate', $result['tier']);
    }

    public function testKindBreakdownCountsOccurrencesAndUsesTypeFallback(): void
    {
        $result = $this->scorer->score([
            ['type' => 'test_result'],
            ['kind' => 'test_result'],
            ['kind' => 'doc'],
        ]);

        $this->assertSame(['test_result' => 2, 'doc' => 1], $result['kind_breakdown']);
        $this->assertSame(11, $result['score']);
        $this->assertSame('strong', $result['tier']);
    }

    public function testLegacyStringRefIsTreatedAsUnknownKindAndContributesZero(): void
    {
        $result = $this->scorer->score(['some/legacy/ref.md']);

        $this->assertSame(0, $result['score']);
        $this->assertSame('invalid', $result['tier']);
        $this->assertSame(['unknown' => 1], $result['kind_breakdown']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $refs = [
            ['kind' => 'test_result'],
            ['kind' => 'commit_hash'],
            ['kind' => 'doc'],
        ];

        $first = $this->scorer->score($refs);
        $second = $this->scorer->score($refs);

        $this->assertSame($first, $second);
    }
}
