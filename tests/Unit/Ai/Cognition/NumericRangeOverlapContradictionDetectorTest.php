<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition;

use App\Services\Ai\Cognition\NumericRangeOverlapContradictionDetector;
use PHPUnit\Framework\TestCase;

final class NumericRangeOverlapContradictionDetectorTest extends TestCase
{
    private NumericRangeOverlapContradictionDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new NumericRangeOverlapContradictionDetector();
    }

    public function testInvertedBoundsOnFirstRangeIsInvalid(): void
    {
        $this->assertSame('invalid', $this->detector->detect(10.0, 5.0, 0.0, 20.0));
    }

    public function testInvertedBoundsOnSecondRangeIsInvalid(): void
    {
        $this->assertSame('invalid', $this->detector->detect(0.0, 20.0, 8.0, 3.0));
    }

    public function testIdenticalRangesAreEqual(): void
    {
        $this->assertSame('equal', $this->detector->detect(2.5, 7.5, 2.5, 7.5));
    }

    public function testSeparatedRangesAreDisjoint(): void
    {
        // a entirely left of b: aMax (3.0) < bMin (5.0)
        $this->assertSame('disjoint', $this->detector->detect(1.0, 3.0, 5.0, 9.0));
    }

    public function testSeparatedRangesAreDisjointWhenSecondIsLeft(): void
    {
        // b entirely left of a: bMax (4.0) < aMin (6.0)
        $this->assertSame('disjoint', $this->detector->detect(6.0, 10.0, 1.0, 4.0));
    }

    public function testEdgeTouchingRangesAreTouchingNotOverlap(): void
    {
        // aMax (5.0) === bMin (5.0): shared boundary classified before overlap
        $this->assertSame('touching', $this->detector->detect(1.0, 5.0, 5.0, 9.0));
    }

    public function testEdgeTouchingRangesAreTouchingWhenSecondMeetsFirst(): void
    {
        // bMax (2.0) === aMin (2.0)
        $this->assertSame('touching', $this->detector->detect(2.0, 8.0, -3.0, 2.0));
    }

    public function testFirstRangeFullyContainingSecondIsAContainsB(): void
    {
        $this->assertSame('a_contains_b', $this->detector->detect(0.0, 20.0, 5.0, 12.0));
    }

    public function testSecondRangeFullyContainingFirstIsBContainsA(): void
    {
        $this->assertSame('b_contains_a', $this->detector->detect(5.0, 12.0, 0.0, 20.0));
    }

    public function testPartialOverlapIsOverlap(): void
    {
        // a=[1,6], b=[4,9]: overlap on [4,6], neither contains the other
        $this->assertSame('overlap', $this->detector->detect(1.0, 6.0, 4.0, 9.0));
    }

    public function testPartialOverlapIsOverlapWhenSecondStartsLeft(): void
    {
        // a=[4,9], b=[1,6]: overlap on [4,6], neither contains the other
        $this->assertSame('overlap', $this->detector->detect(4.0, 9.0, 1.0, 6.0));
    }

    public function testContainmentWithSharedLowerBoundIsAContainsB(): void
    {
        // shared aMin===bMin but a extends further: a contains b, not equal
        $this->assertSame('a_contains_b', $this->detector->detect(0.0, 10.0, 0.0, 4.0));
    }
}
