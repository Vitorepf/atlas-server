<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Defect;

use App\Services\Ai\AutonomousEvolution\Defect\AtlasLoopDefectFalsificationGate;
use PHPUnit\Framework\TestCase;

/**
 * Proves the defect falsification gate: admit only a falsifiable-RED candidate (red_command + red_observed +
 * positive delta), and name each failed condition — with the no_red_command fail-closed floor.
 */
final class AtlasLoopDefectFalsificationGateTest extends TestCase
{
    private function gate(): AtlasLoopDefectFalsificationGate
    {
        return new AtlasLoopDefectFalsificationGate;
    }

    public function test_admits_a_falsifiable_red_candidate(): void
    {
        $verdict = $this->gate()->admit([
            'hypothesis' => 'X mishandles empty input',
            'red_command' => 'phpunit --filter XTest::test_empty',
            'red_observed' => true,
            'behavior_delta_after_fix' => 3,
        ]);

        $this->assertTrue($verdict['admitted']);
        $this->assertSame([], $verdict['blocking_reasons']);
        $this->assertSame('atlas.loop.defect_falsification.v1', $verdict['schema']);
    }

    public function test_no_red_command_is_the_fail_closed_floor(): void
    {
        $verdict = $this->gate()->admit([
            'hypothesis' => 'looks buggy',
            'red_observed' => true,
            'behavior_delta_after_fix' => 5,
        ]);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('no_red_command', $verdict['blocking_reasons']);
    }

    public function test_not_reproduced_is_blocked(): void
    {
        $verdict = $this->gate()->admit([
            'red_command' => 'phpunit --filter XTest',
            'red_observed' => false,
            'behavior_delta_after_fix' => 2,
        ]);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('not_reproduced', $verdict['blocking_reasons']);
    }

    public function test_non_positive_delta_is_blocked(): void
    {
        $verdict = $this->gate()->admit([
            'red_command' => 'phpunit --filter XTest',
            'red_observed' => true,
            'behavior_delta_after_fix' => 0,
        ]);

        $this->assertFalse($verdict['admitted']);
        $this->assertContains('no_positive_delta', $verdict['blocking_reasons']);
    }

    public function test_empty_candidate_lists_all_three_reasons(): void
    {
        $verdict = $this->gate()->admit([]);

        $this->assertFalse($verdict['admitted']);
        $this->assertSame(['no_red_command', 'not_reproduced', 'no_positive_delta'], $verdict['blocking_reasons']);
    }
}
