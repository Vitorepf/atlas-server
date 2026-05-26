<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\AutonomousWorkExecutionOs;
use Tests\TestCase;

final class AutonomousWorkExecutionOsTest extends TestCase
{
    private AutonomousWorkExecutionOs $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new AutonomousWorkExecutionOs;
    }

    public function test_l0_cycle_proceeds_without_consent(): void
    {
        $r = $this->svc->evaluateCycle([
            'goal' => 'list files',
            'autonomy_level' => 'L0',
        ]);
        $this->assertTrue($r['may_proceed']);
        $this->assertSame([], $r['blocking_reasons']);
    }

    public function test_l4_requires_operator_consent(): void
    {
        $r = $this->svc->evaluateCycle([
            'goal' => 'auto-refactor',
            'autonomy_level' => 'L4',
        ]);
        $this->assertFalse($r['may_proceed']);
        $this->assertStringContainsString('operator_consent_present=true', implode(' ', $r['blocking_reasons']));
    }

    public function test_l4_passes_with_operator_consent(): void
    {
        $r = $this->svc->evaluateCycle([
            'goal' => 'auto-refactor',
            'autonomy_level' => 'L4',
            'operator_consent_present' => true,
        ]);
        $this->assertTrue($r['may_proceed']);
    }

    public function test_l6_blocks_when_prior_failures_exist(): void
    {
        $r = $this->svc->evaluateCycle([
            'goal' => 'self-evolve',
            'autonomy_level' => 'L6',
            'operator_consent_present' => true,
            'prior_failure_signatures' => ['sig1'],
        ]);
        $this->assertFalse($r['may_proceed']);
    }

    public function test_l7_blocks_when_prior_failures_exist(): void
    {
        $r = $this->svc->evaluateCycle([
            'goal' => 'self-evolve',
            'autonomy_level' => 'L7',
            'operator_consent_present' => true,
            'prior_failure_signatures' => ['sig1'],
        ]);
        $this->assertFalse($r['may_proceed']);
    }

    public function test_invalid_autonomy_level_blocks(): void
    {
        $r = $this->svc->evaluateCycle([
            'goal' => 'x',
            'autonomy_level' => 'L99',
        ]);
        $this->assertFalse($r['may_proceed']);
    }

    public function test_empty_goal_blocks(): void
    {
        $r = $this->svc->evaluateCycle([
            'goal' => '   ',
            'autonomy_level' => 'L0',
        ]);
        $this->assertFalse($r['may_proceed']);
    }

    public function test_cycle_carries_canonical_stages(): void
    {
        $r = $this->svc->evaluateCycle([
            'goal' => 'x',
            'autonomy_level' => 'L0',
        ]);
        $this->assertCount(6, $r['stages']);
        $this->assertSame('goal_recorded', $r['stages'][0]['stage']);
        $this->assertSame('learning_extracted', $r['stages'][5]['stage']);
    }

    public function test_transition_stage_updates_status(): void
    {
        $cycle = $this->svc->evaluateCycle([
            'goal' => 'x',
            'autonomy_level' => 'L0',
        ]);
        $updated = $this->svc->transitionStage($cycle, 'cycle_planned', 'succeeded');
        $stage = array_values(array_filter($updated['stages'], static fn ($s) => $s['stage'] === 'cycle_planned'))[0];
        $this->assertSame('succeeded', $stage['status']);
    }

    public function test_transition_rejects_unknown_stage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->transitionStage(['stages' => []], 'wibble', 'succeeded');
    }

    public function test_transition_rejects_unknown_status(): void
    {
        $cycle = $this->svc->evaluateCycle(['goal' => 'x', 'autonomy_level' => 'L0']);
        $this->expectException(\InvalidArgumentException::class);
        $this->svc->transitionStage($cycle, 'goal_recorded', 'maybe');
    }
}
