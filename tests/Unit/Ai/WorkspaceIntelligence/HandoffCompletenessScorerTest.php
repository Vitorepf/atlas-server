<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\WorkspaceIntelligence;

use App\Services\Ai\WorkspaceIntelligence\HandoffCompletenessScorer;
use PHPUnit\Framework\TestCase;

final class HandoffCompletenessScorerTest extends TestCase
{
    private HandoffCompletenessScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new HandoffCompletenessScorer();
    }

    public function testAllRequiredPresentScoresFullyReady(): void
    {
        $result = $this->scorer->score(
            ['workspace', 'memory', 'evidence', 'receipt'],
            ['workspace', 'memory', 'evidence', 'receipt'],
        );

        $this->assertEqualsWithDelta(1.0, $result['ratio'], 0.0);
        $this->assertSame('ready', $result['status']);
    }

    public function testTwoOfFourRequiredPresentScoresHalfBlocked(): void
    {
        $result = $this->scorer->score(
            ['workspace', 'memory'],
            ['workspace', 'memory', 'evidence', 'receipt'],
        );

        $this->assertEqualsWithDelta(0.5, $result['ratio'], 0.0);
        $this->assertSame('blocked', $result['status']);
    }

    public function testEmptyRequiredAndEmptyPresentIsVacuouslyReady(): void
    {
        $result = $this->scorer->score([], []);

        $this->assertEqualsWithDelta(1.0, $result['ratio'], 0.0);
        $this->assertSame('ready', $result['status']);
    }

    public function testExtraPresentTypesNeverExceedFullRatio(): void
    {
        $result = $this->scorer->score(
            ['workspace', 'memory', 'evidence', 'receipt', 'ledger'],
            ['workspace', 'memory', 'evidence', 'receipt'],
        );

        $this->assertEqualsWithDelta(1.0, $result['ratio'], 0.0);
        $this->assertSame('ready', $result['status']);
    }

    public function testSingleMissingTypeScoresBelowFullAndBlocked(): void
    {
        $result = $this->scorer->score(
            ['workspace', 'memory', 'evidence'],
            ['workspace', 'memory', 'evidence', 'receipt'],
        );

        $this->assertLessThan(1.0, $result['ratio']);
        $this->assertEqualsWithDelta(0.75, $result['ratio'], 0.0);
        $this->assertSame('blocked', $result['status']);
    }

    public function testMatchingIsExactAfterTrim(): void
    {
        $result = $this->scorer->score(
            ['  workspace  ', 'memory'],
            ['workspace', 'memory'],
        );

        $this->assertEqualsWithDelta(1.0, $result['ratio'], 0.0);
        $this->assertSame('ready', $result['status']);
    }

    public function testDuplicateRequiredCollapseToDistinct(): void
    {
        $result = $this->scorer->score(
            ['workspace', 'memory'],
            ['workspace', 'workspace', 'memory', 'memory'],
        );

        $this->assertEqualsWithDelta(1.0, $result['ratio'], 0.0);
        $this->assertSame('ready', $result['status']);
    }
}
