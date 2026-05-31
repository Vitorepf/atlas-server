<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\QuarantineEligibilityScorer;
use PHPUnit\Framework\TestCase;

final class QuarantineEligibilityScorerTest extends TestCase
{
    private QuarantineEligibilityScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new QuarantineEligibilityScorer();
    }

    public function testPermanentSeverityBaselineScore(): void
    {
        $result = $this->scorer->score('permanent', 0, false, false);

        $this->assertSame(70, $result['score']);
        $this->assertSame('permanent', $result['severity']);
        $this->assertSame([
            'severity_points' => 70,
            'recurrence_points' => 0,
            'repair_points' => 0,
            'work_relief' => 0,
        ], $result['components']);
    }

    public function testPostRepairSeverityPoints(): void
    {
        $result = $this->scorer->score('post_repair', 0, false, false);

        $this->assertSame(35, $result['score']);
        $this->assertSame(35, $result['components']['severity_points']);
    }

    public function testTransientSeverityPoints(): void
    {
        $result = $this->scorer->score('transient', 0, false, false);

        $this->assertSame(5, $result['score']);
        $this->assertSame(5, $result['components']['severity_points']);
    }

    public function testUnknownSeverityDefaultsToTwentyPoints(): void
    {
        $result = $this->scorer->score('unknown', 0, false, false);

        $this->assertSame(20, $result['score']);
        $this->assertSame(20, $result['components']['severity_points']);
    }

    public function testUnrecognizedSeverityUsesUnknownPoints(): void
    {
        $result = $this->scorer->score('custom', 0, false, false);

        $this->assertSame(20, $result['components']['severity_points']);
    }

    public function testRecurrencePointsScaleWithCountUpToFive(): void
    {
        $result = $this->scorer->score('transient', 3, false, false);

        $this->assertSame(23, $result['score']);
        $this->assertSame(18, $result['components']['recurrence_points']);
    }

    public function testRecurrenceCountClampedAtFive(): void
    {
        $result = $this->scorer->score('transient', 10, false, false);

        $this->assertSame(35, $result['score']);
        $this->assertSame(30, $result['components']['recurrence_points']);
    }

    public function testNegativeRecurrenceCountNormalizedToZero(): void
    {
        $result = $this->scorer->score('transient', -3, false, false);

        $this->assertSame(5, $result['score']);
        $this->assertSame(0, $result['components']['recurrence_points']);
    }

    public function testRepairExhaustedAddsTwentyPoints(): void
    {
        $result = $this->scorer->score('transient', 0, true, false);

        $this->assertSame(25, $result['score']);
        $this->assertSame(20, $result['components']['repair_points']);
    }

    public function testWorkProducedAppliesFifteenPointRelief(): void
    {
        $result = $this->scorer->score('transient', 0, false, true);

        $this->assertSame(0, $result['score']);
        $this->assertSame(-15, $result['components']['work_relief']);
    }

    public function testScoreClampedAtOneHundred(): void
    {
        $result = $this->scorer->score('permanent', 5, true, false);

        $this->assertSame(100, $result['score']);
        $this->assertSame(70, $result['components']['severity_points']);
        $this->assertSame(30, $result['components']['recurrence_points']);
        $this->assertSame(20, $result['components']['repair_points']);
    }

    public function testScoreClampedAtZero(): void
    {
        $result = $this->scorer->score('transient', 0, false, true);

        $this->assertSame(0, $result['score']);
    }

    public function testCombinedComponentsSumBeforeClamp(): void
    {
        $result = $this->scorer->score('post_repair', 2, true, true);

        $this->assertSame(52, $result['score']);
        $this->assertSame([
            'severity_points' => 35,
            'recurrence_points' => 12,
            'repair_points' => 20,
            'work_relief' => -15,
        ], $result['components']);
    }

    public function testResultContainsAllRequiredKeys(): void
    {
        $result = $this->scorer->score('permanent', 1, false, false);

        $this->assertArrayHasKey('score', $result);
        $this->assertArrayHasKey('severity', $result);
        $this->assertArrayHasKey('components', $result);
        $this->assertArrayHasKey('severity_points', $result['components']);
        $this->assertArrayHasKey('recurrence_points', $result['components']);
        $this->assertArrayHasKey('repair_points', $result['components']);
        $this->assertArrayHasKey('work_relief', $result['components']);
    }
}
