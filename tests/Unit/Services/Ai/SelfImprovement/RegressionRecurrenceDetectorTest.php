<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfImprovement;

use App\Services\Ai\SelfImprovement\RegressionRecurrenceDetector;
use PHPUnit\Framework\TestCase;

final class RegressionRecurrenceDetectorTest extends TestCase
{
    private RegressionRecurrenceDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new RegressionRecurrenceDetector();
    }

    public function testMetricInThreeDistinctCyclesIsChronic(): void
    {
        $result = $this->detector->detect([
            ['grade' => 'fail', 'regressed_metrics' => ['runtime_safety']],
            ['grade' => 'fail', 'regressed_metrics' => ['runtime_safety']],
            ['grade' => 'fail', 'regressed_metrics' => ['runtime_safety']],
        ]);

        $this->assertSame('runtime_safety', $result['top_metric']);
        $this->assertSame(3, $result['top_recurrence']);
        $this->assertTrue($result['chronic']);
        $this->assertSame(3, $result['scanned_cycles']);
        $this->assertSame(['runtime_safety' => 3], $result['recurrence_by_metric']);
    }

    public function testMetricDuplicatedWithinOneEntryCountedOnce(): void
    {
        $result = $this->detector->detect([
            ['grade' => 'fail', 'regressed_metrics' => ['governance_integrity', 'governance_integrity', 'governance_integrity']],
        ]);

        $this->assertSame(1, $result['top_recurrence']);
        $this->assertSame('governance_integrity', $result['top_metric']);
        $this->assertSame(['governance_integrity' => 1], $result['recurrence_by_metric']);
        $this->assertFalse($result['chronic']);
    }

    public function testNonAllowlistedMetricIsIgnored(): void
    {
        $result = $this->detector->detect([
            ['grade' => 'fail', 'regressed_metrics' => ['velocity', 'doc_health', 'business_rule_alignment']],
            ['grade' => 'fail', 'regressed_metrics' => ['velocity', 'velocity']],
        ]);

        $this->assertSame(['business_rule_alignment' => 1], $result['recurrence_by_metric']);
        $this->assertSame('business_rule_alignment', $result['top_metric']);
        $this->assertSame(1, $result['top_recurrence']);
        $this->assertArrayNotHasKey('velocity', $result['recurrence_by_metric']);
        $this->assertArrayNotHasKey('doc_health', $result['recurrence_by_metric']);
        $this->assertSame(2, $result['scanned_cycles']);
    }

    public function testEmptyEntriesYieldNullTopMetric(): void
    {
        $result = $this->detector->detect([]);

        $this->assertNull($result['top_metric']);
        $this->assertSame(0, $result['top_recurrence']);
        $this->assertFalse($result['chronic']);
        $this->assertSame([], $result['recurrence_by_metric']);
        $this->assertSame(0, $result['scanned_cycles']);
    }

    public function testTieIsBrokenByDeclarationOrder(): void
    {
        // business_rule_alignment is declared AFTER governance_integrity in
        // HARD_REGRESSION_METRICS, yet appears first in the entry input. The
        // tie of count=2 must resolve to governance_integrity by declaration order.
        $result = $this->detector->detect([
            ['grade' => 'fail', 'regressed_metrics' => ['business_rule_alignment', 'governance_integrity']],
            ['grade' => 'fail', 'regressed_metrics' => ['business_rule_alignment', 'governance_integrity']],
        ]);

        $this->assertSame('governance_integrity', $result['top_metric']);
        $this->assertSame(2, $result['top_recurrence']);
        $this->assertSame(2, $result['recurrence_by_metric']['governance_integrity']);
        $this->assertSame(2, $result['recurrence_by_metric']['business_rule_alignment']);
        $this->assertFalse($result['chronic']);
    }
}
