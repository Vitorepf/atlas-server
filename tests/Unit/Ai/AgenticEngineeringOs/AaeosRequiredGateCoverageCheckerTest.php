<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\AaeosRequiredGateCoverageChecker;
use Tests\TestCase;

final class AaeosRequiredGateCoverageCheckerTest extends TestCase
{
    private AaeosRequiredGateCoverageChecker $checker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checker = new AaeosRequiredGateCoverageChecker();
    }

    public function testEmptyRequiredGatesYieldNoGateAndSatisfied(): void
    {
        $result = $this->checker->check([], ['build', 'lint']);

        $this->assertSame('no_gate', $result['coverage']);
        $this->assertSame([], $result['missing']);
        $this->assertTrue($result['satisfied']);
    }

    public function testRequiredEntriesThatAllNormalizeAwayCollapseToNoGate(): void
    {
        $result = $this->checker->check(['   ', '', 42, null, false], ['build']);

        $this->assertSame('no_gate', $result['coverage']);
        $this->assertSame([], $result['missing']);
        $this->assertTrue($result['satisfied']);
    }

    public function testPartialOverlapIsIncompleteWithMissingInRequiredOrder(): void
    {
        $result = $this->checker->check(
            ['build', 'lint', 'typecheck', 'security'],
            ['lint', 'security'],
        );

        $this->assertSame('incomplete', $result['coverage']);
        $this->assertSame(['build', 'typecheck'], $result['missing']);
        $this->assertFalse($result['satisfied']);
    }

    public function testMissingPreservesRequiredOrderNotPassedOrder(): void
    {
        $result = $this->checker->check(
            ['security', 'build', 'lint', 'typecheck'],
            ['lint'],
        );

        $this->assertSame('incomplete', $result['coverage']);
        $this->assertSame(['security', 'build', 'typecheck'], $result['missing']);
        $this->assertFalse($result['satisfied']);
    }

    public function testFullCoverageIsCompleteWithEmptyMissingAndSatisfied(): void
    {
        $result = $this->checker->check(
            ['build', 'lint', 'typecheck'],
            ['typecheck', 'build', 'lint'],
        );

        $this->assertSame('complete', $result['coverage']);
        $this->assertSame([], $result['missing']);
        $this->assertTrue($result['satisfied']);
    }

    public function testExactSingleGateCoverageIsComplete(): void
    {
        $result = $this->checker->check(['build'], ['build']);

        $this->assertSame('complete', $result['coverage']);
        $this->assertSame([], $result['missing']);
        $this->assertTrue($result['satisfied']);
    }

    public function testDuplicateAndBlankEntriesAreNormalizedAwayBeforeDiff(): void
    {
        $result = $this->checker->check(
            ['build', '  build  ', '', '   ', 'lint', 'build'],
            ['  lint  ', 'lint'],
        );

        $this->assertSame('incomplete', $result['coverage']);
        $this->assertSame(['build'], $result['missing']);
        $this->assertFalse($result['satisfied']);
    }

    public function testDuplicateRequiredGateMissingAppearsOnlyOnce(): void
    {
        $result = $this->checker->check(
            ['build', 'build', 'lint', 'build'],
            ['lint'],
        );

        $this->assertSame('incomplete', $result['coverage']);
        $this->assertSame(['build'], $result['missing']);
        $this->assertFalse($result['satisfied']);
    }

    public function testExtraPassedGatesBeyondRequiredDoNotBreakCompleteness(): void
    {
        $result = $this->checker->check(
            ['build', 'lint'],
            ['build', 'lint', 'typecheck', 'security', 'release'],
        );

        $this->assertSame('complete', $result['coverage']);
        $this->assertSame([], $result['missing']);
        $this->assertTrue($result['satisfied']);
    }

    public function testNonStringPassedEntriesAreIgnoredSoStillIncomplete(): void
    {
        $result = $this->checker->check(
            ['build', 'lint'],
            ['build', 999, null, ['lint'], false],
        );

        $this->assertSame('incomplete', $result['coverage']);
        $this->assertSame(['lint'], $result['missing']);
        $this->assertFalse($result['satisfied']);
    }

    public function testWhitespaceTrimmingAlignsRequiredAndPassedForCompleteness(): void
    {
        $result = $this->checker->check(
            ['  build  ', "\tlint\n"],
            ['build', 'lint'],
        );

        $this->assertSame('complete', $result['coverage']);
        $this->assertSame([], $result['missing']);
        $this->assertTrue($result['satisfied']);
    }

    public function testMatchIsCaseSensitiveExactString(): void
    {
        $result = $this->checker->check(['Build'], ['build']);

        $this->assertSame('incomplete', $result['coverage']);
        $this->assertSame(['Build'], $result['missing']);
        $this->assertFalse($result['satisfied']);
    }

    public function testEmptyPassedAgainstNonEmptyRequiredIsFullyMissing(): void
    {
        $result = $this->checker->check(['build', 'lint', 'typecheck'], []);

        $this->assertSame('incomplete', $result['coverage']);
        $this->assertSame(['build', 'lint', 'typecheck'], $result['missing']);
        $this->assertFalse($result['satisfied']);
    }

    public function testMissingIsAZeroIndexedList(): void
    {
        $result = $this->checker->check(
            ['build', 'lint', 'typecheck'],
            ['lint'],
        );

        $this->assertSame([0, 1], array_keys($result['missing']));
        $this->assertSame(['build', 'typecheck'], $result['missing']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $required = ['build', 'lint', 'typecheck'];
        $passed = ['lint'];

        $first = $this->checker->check($required, $passed);
        $second = $this->checker->check($required, $passed);

        $this->assertSame($first, $second);
    }
}
