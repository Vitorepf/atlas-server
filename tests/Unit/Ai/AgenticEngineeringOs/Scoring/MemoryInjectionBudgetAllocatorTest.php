<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs\Scoring;

use App\Services\Ai\AgenticEngineeringOs\Scoring\MemoryInjectionBudgetAllocator;
use PHPUnit\Framework\TestCase;

final class MemoryInjectionBudgetAllocatorTest extends TestCase
{
    private MemoryInjectionBudgetAllocator $allocator;

    protected function setUp(): void
    {
        $this->allocator = new MemoryInjectionBudgetAllocator();
    }

    public function testGreedyPackingAdmitsTwoCapsSecondAndDropsThird(): void
    {
        $result = $this->allocator->allocate(
            [
                ['ref' => 'a', 'priority' => 90, 'estimated_chars' => 300],
                ['ref' => 'b', 'priority' => 50, 'estimated_chars' => 300],
                ['ref' => 'c', 'priority' => 10, 'estimated_chars' => 300],
            ],
            500,
            300,
            40,
        );

        $this->assertSame('atlas.aaeos.memory_injection_budget_allocation.v1', $result['schema_version']);

        $admittedRefs = array_map(static fn (array $row): string => $row['ref'], $result['admitted']);
        $this->assertSame(['a', 'b'], $admittedRefs);

        $this->assertSame(300, $result['admitted'][0]['allocated_chars']);
        $this->assertFalse($result['admitted'][0]['capped']);

        $this->assertSame(200, $result['admitted'][1]['allocated_chars']);
        $this->assertTrue($result['admitted'][1]['capped']);

        $this->assertSame(500, $result['used_chars']);
        $this->assertSame(0, $result['remaining_chars']);
        $this->assertTrue($result['truncated']);

        $this->assertSame(1, $result['dropped_count']);
        $this->assertSame('c', $result['dropped'][0]['ref']);
        $this->assertSame('budget_exhausted', $result['dropped'][0]['reason']);
    }

    public function testEstimatedBelowFloorIsDroppedWhileExactlyAtFloorIsAdmitted(): void
    {
        $result = $this->allocator->allocate(
            [
                ['ref' => 'tiny', 'priority' => 80, 'estimated_chars' => 20],
                ['ref' => 'exact', 'priority' => 60, 'estimated_chars' => 40],
            ],
            1000,
            300,
            40,
        );

        $admittedRefs = array_map(static fn (array $row): string => $row['ref'], $result['admitted']);
        $this->assertSame(['exact'], $admittedRefs);
        $this->assertSame(40, $result['admitted'][0]['allocated_chars']);
        $this->assertFalse($result['admitted'][0]['capped']);

        $this->assertSame(1, $result['dropped_count']);
        $this->assertSame('tiny', $result['dropped'][0]['ref']);
        $this->assertSame('below_min_excerpt', $result['dropped'][0]['reason']);

        $this->assertSame(40, $result['used_chars']);
        $this->assertSame(960, $result['remaining_chars']);
    }

    public function testHigherPriorityPresentedAfterLowerStillWinsAdmission(): void
    {
        $result = $this->allocator->allocate(
            [
                ['ref' => 'low', 'priority' => 10, 'estimated_chars' => 300],
                ['ref' => 'high', 'priority' => 99, 'estimated_chars' => 300],
            ],
            300,
            300,
            40,
        );

        $admittedRefs = array_map(static fn (array $row): string => $row['ref'], $result['admitted']);
        $this->assertSame(['high'], $admittedRefs);
        $this->assertSame(1, $result['admitted'][0]['rank']);

        $this->assertSame('low', $result['dropped'][0]['ref']);
        $this->assertSame('budget_exhausted', $result['dropped'][0]['reason']);
    }

    public function testZeroEstimatedCharsIsDroppedWithDedicatedReason(): void
    {
        $result = $this->allocator->allocate(
            [
                ['ref' => 'empty', 'priority' => 70, 'estimated_chars' => 0],
                ['ref' => 'real', 'priority' => 30, 'estimated_chars' => 120],
            ],
            500,
            300,
            40,
        );

        $admittedRefs = array_map(static fn (array $row): string => $row['ref'], $result['admitted']);
        $this->assertSame(['real'], $admittedRefs);
        $this->assertSame(120, $result['admitted'][0]['allocated_chars']);

        $this->assertSame('empty', $result['dropped'][0]['ref']);
        $this->assertSame('zero_estimated_chars', $result['dropped'][0]['reason']);
        $this->assertSame(0, $result['dropped'][0]['requested_chars']);
    }

    public function testRanksAndCountsAreSequentialAcrossAdmittedItems(): void
    {
        $result = $this->allocator->allocate(
            [
                ['ref' => 'x1', 'priority' => 50, 'estimated_chars' => 100],
                ['ref' => 'x2', 'priority' => 40, 'estimated_chars' => 100],
                ['ref' => 'x3', 'priority' => 30, 'estimated_chars' => 100],
            ],
            1000,
            300,
            40,
        );

        $this->assertSame(3, $result['admitted_count']);
        $this->assertSame(0, $result['dropped_count']);
        $this->assertFalse($result['truncated']);

        $this->assertSame(1, $result['admitted'][0]['rank']);
        $this->assertSame(2, $result['admitted'][1]['rank']);
        $this->assertSame(3, $result['admitted'][2]['rank']);

        $this->assertSame(300, $result['used_chars']);
        $this->assertSame(700, $result['remaining_chars']);
    }

    public function testPerItemCapTruncatesAndMarksCappedWithRequestedPreserved(): void
    {
        $result = $this->allocator->allocate(
            [
                ['ref' => 'big', 'priority' => 90, 'estimated_chars' => 500],
            ],
            1000,
            150,
            40,
        );

        $this->assertSame(150, $result['admitted'][0]['allocated_chars']);
        $this->assertSame(500, $result['admitted'][0]['requested_chars']);
        $this->assertTrue($result['admitted'][0]['capped']);
        $this->assertSame(150, $result['used_chars']);
        $this->assertSame(850, $result['remaining_chars']);
    }

    public function testDefaultFloorIsMinOfCapAndInternalFloorWhenNullProvided(): void
    {
        $resultSmallCap = $this->allocator->allocate(
            [
                ['ref' => 'a', 'priority' => 50, 'estimated_chars' => 200],
            ],
            1000,
            30,
        );

        // cap 30 < internal floor 80 => effective floor clamps to 30.
        $this->assertSame(30, $resultSmallCap['min_excerpt_chars']);
        $this->assertSame(30, $resultSmallCap['admitted'][0]['allocated_chars']);

        $resultLargeCap = $this->allocator->allocate(
            [
                ['ref' => 'b', 'priority' => 50, 'estimated_chars' => 200],
            ],
            1000,
            300,
        );

        // cap 300 > internal floor 80 => effective floor is 80.
        $this->assertSame(80, $resultLargeCap['min_excerpt_chars']);
    }

    public function testTieBreaksByEstimatedAscThenRefAsc(): void
    {
        $result = $this->allocator->allocate(
            [
                ['ref' => 'zeta', 'priority' => 50, 'estimated_chars' => 100],
                ['ref' => 'alpha', 'priority' => 50, 'estimated_chars' => 100],
                ['ref' => 'beta', 'priority' => 50, 'estimated_chars' => 60],
            ],
            1000,
            300,
            40,
        );

        $admittedRefs = array_map(static fn (array $row): string => $row['ref'], $result['admitted']);
        // smaller estimated_chars first (beta=60), then ref asc among equal estimates (alpha < zeta).
        $this->assertSame(['beta', 'alpha', 'zeta'], $admittedRefs);
    }

    public function testPriorityReportedAsFloatAndDeterministicAcrossRuns(): void
    {
        $items = [
            ['ref' => 'p1', 'priority' => 12.5, 'estimated_chars' => 90],
            ['ref' => 'p2', 'priority' => 12.5, 'estimated_chars' => 90],
        ];

        $first = $this->allocator->allocate($items, 1000, 300, 40);
        $second = $this->allocator->allocate($items, 1000, 300, 40);

        $this->assertSame($first, $second);
        $this->assertSame(12.5, $first['admitted'][0]['priority']);
        $this->assertIsFloat($first['admitted'][0]['priority']);
    }

    public function testEmptyInputProducesEmptyUntruncatedReport(): void
    {
        $result = $this->allocator->allocate([], 500, 300, 40);

        $this->assertSame([], $result['admitted']);
        $this->assertSame([], $result['dropped']);
        $this->assertSame(0, $result['used_chars']);
        $this->assertSame(500, $result['remaining_chars']);
        $this->assertSame(0, $result['admitted_count']);
        $this->assertSame(0, $result['dropped_count']);
        $this->assertFalse($result['truncated']);
    }
}
