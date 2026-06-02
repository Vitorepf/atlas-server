<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\CompoundingAxisCoverageScorer;
use PHPUnit\Framework\TestCase;

final class CompoundingAxisCoverageScorerTest extends TestCase
{
    private CompoundingAxisCoverageScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new CompoundingAxisCoverageScorer();
    }

    public function testTwoLeapsLiftingOnlySpeedFullyCoverSpeedAndLeaveOthersZero(): void
    {
        $result = $this->scorer->score([
            ['counts_as_leap' => true, 'impact_axes' => ['speed' => 1.0]],
            ['counts_as_leap' => true, 'impact_axes' => ['speed' => 1.0]],
        ]);

        $this->assertSame(2, $result['leap_count']);
        $this->assertSame(1.0, $result['axes']['speed']);
        $this->assertSame(0.0, $result['axes']['memory']);
        // Lowest coverage is 0.0 across every non-speed axis; most_neglected_axis
        // is one of those zero-coverage axes and must itself read 0.0.
        $this->assertSame(0.0, $result['axes'][$result['most_neglected_axis']]);
        $this->assertFalse($result['fully_covered']);
    }

    public function testTiedZeroCoverageBreaksToMemoryBeforeQuality(): void
    {
        $result = $this->scorer->score([
            [
                'counts_as_leap' => true,
                'impact_axes' => [
                    'context' => 1.0,
                    'agents' => 1.0,
                    'speed' => 1.0,
                    'robustness' => 1.0,
                ],
            ],
        ]);

        $this->assertSame(0.0, $result['axes']['memory']);
        $this->assertSame(0.0, $result['axes']['quality']);
        // memory precedes quality in canonical order, so the tie resolves to memory.
        $this->assertSame('memory', $result['most_neglected_axis']);
    }

    public function testNonLeapCarryingImpactAxesDoesNotAddCoverage(): void
    {
        $result = $this->scorer->score([
            ['counts_as_leap' => true, 'impact_axes' => ['context' => 1.0]],
            ['counts_as_leap' => false, 'impact_axes' => ['robustness' => 1.0]],
        ]);

        $this->assertSame(1, $result['leap_count']);
        $this->assertSame(0.0, $result['axes']['robustness']);
        $this->assertSame(1.0, $result['axes']['context']);
    }

    public function testEmptyInputYieldsZeroLeapsAllZeroAndContextNeglected(): void
    {
        $result = $this->scorer->score([]);

        $this->assertSame(0, $result['leap_count']);
        $this->assertSame(0.0, $result['axes']['context']);
        $this->assertSame(0.0, $result['axes']['memory']);
        $this->assertSame(0.0, $result['axes']['quality']);
        $this->assertSame(0.0, $result['axes']['agents']);
        $this->assertSame(0.0, $result['axes']['speed']);
        $this->assertSame(0.0, $result['axes']['robustness']);
        $this->assertSame('context', $result['most_neglected_axis']);
        $this->assertFalse($result['fully_covered']);
    }

    public function testLeapsCoveringAllSixAxesAreFullyCovered(): void
    {
        $result = $this->scorer->score([
            [
                'counts_as_leap' => true,
                'impact_axes' => [
                    'context' => 0.5,
                    'memory' => 0.5,
                    'quality' => 0.5,
                    'agents' => 0.5,
                    'speed' => 0.5,
                    'robustness' => 0.5,
                ],
            ],
        ]);

        $this->assertTrue($result['fully_covered']);
        $this->assertGreaterThan(0.0, $result['axes']['context']);
        $this->assertGreaterThan(0.0, $result['axes']['memory']);
        $this->assertGreaterThan(0.0, $result['axes']['quality']);
        $this->assertGreaterThan(0.0, $result['axes']['agents']);
        $this->assertGreaterThan(0.0, $result['axes']['speed']);
        $this->assertGreaterThan(0.0, $result['axes']['robustness']);
    }
}
