<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\LongRunQualityDriftPromotionGate;
use PHPUnit\Framework\TestCase;

final class LongRunQualityDriftPromotionGateTest extends TestCase
{
    private LongRunQualityDriftPromotionGate $gate;

    protected function setUp(): void
    {
        $this->gate = new LongRunQualityDriftPromotionGate();
    }

    public function testStopPromotionTrueBlocksEveryRungAboveHighestPassed(): void
    {
        $ladder = [
            ['rung' => 'L0', 'status' => 'passed'],
            ['rung' => 'L1', 'status' => 'passed'],
            ['rung' => 'L2', 'status' => 'pending'],
            ['rung' => 'L3', 'status' => 'pending'],
        ];

        $result = $this->gate->apply($ladder, [
            'stop_promotion' => true,
            'highest_passed' => 'L1',
        ]);

        $this->assertSame('atlas.loop.long_run_quality_drift_promotion_gate.v1', $result['schema_version']);
        $this->assertTrue($result['changed']);
        $this->assertSame(['L2', 'L3'], $result['blocked_rungs']);
        $this->assertSame('quality_drift_stop_promotion', $result['next_blocker']);
        $this->assertSame($ladder[0], $result['ladder'][0]);
        $this->assertSame($ladder[1], $result['ladder'][1]);
        $this->assertTrue($result['ladder'][2]['blocked']);
        $this->assertSame('quality_drift_stop_promotion', $result['ladder'][2]['block_reason']);
    }

    public function testStopPromotionFalseReturnsByteEquivalentLadder(): void
    {
        $ladder = [
            ['rung' => 0, 'status' => 'passed'],
            ['rung' => 1, 'status' => 'pending'],
        ];

        $result = $this->gate->apply($ladder, [
            'stop_promotion' => false,
            'highest_passed' => 0,
        ]);

        $this->assertSame($ladder, $result['ladder']);
        $this->assertFalse($result['changed']);
        $this->assertSame([], $result['blocked_rungs']);
        $this->assertNull($result['next_blocker']);
    }

    public function testMissingReportReturnsUnchanged(): void
    {
        $ladder = [['rung' => 'L0', 'status' => 'passed']];

        $result = $this->gate->apply($ladder, []);

        $this->assertSame($ladder, $result['ladder']);
        $this->assertFalse($result['changed']);
        $this->assertSame([], $result['blocked_rungs']);
        $this->assertNull($result['next_blocker']);
    }

    public function testAlreadyBlockedRungKeepsItsBlocker(): void
    {
        $ladder = [
            ['rung' => 'L0', 'status' => 'passed'],
            ['rung' => 'L1', 'status' => 'pending', 'blocked' => true, 'blocker' => 'operator_hold'],
        ];

        $result = $this->gate->apply($ladder, [
            'stop_promotion' => true,
            'highest_passed' => 'L0',
        ]);

        $this->assertSame(['L1'], $result['blocked_rungs']);
        $this->assertTrue($result['ladder'][1]['blocked']);
        $this->assertSame('operator_hold', $result['ladder'][1]['blocker']);
        $this->assertArrayNotHasKey('block_reason', $result['ladder'][1]);
    }

    public function testHighestPassedItselfStaysPassed(): void
    {
        $ladder = [
            ['rung' => 0, 'status' => 'passed'],
            ['rung' => 1, 'status' => 'passed'],
            ['rung' => 2, 'status' => 'pending'],
        ];

        $result = $this->gate->apply($ladder, [
            'stop_promotion' => true,
            'highest_passed' => 1,
        ]);

        $this->assertSame($ladder[1], $result['ladder'][1]);
        $this->assertSame([2], $result['blocked_rungs']);
    }

    public function testStopPromotionWithoutHighestPassedBlocksAllRungs(): void
    {
        $ladder = [
            ['rung' => 'L0'],
            ['rung' => 'L1'],
        ];

        $result = $this->gate->apply($ladder, ['stop_promotion' => true]);

        $this->assertSame(['L0', 'L1'], $result['blocked_rungs']);
        $this->assertTrue($result['changed']);
    }
}
