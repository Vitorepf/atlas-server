<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift\AtlasLoopAmbitionFacultyRecalibrator;
use App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift\AtlasLoopAmbitionFacultyReversalToken;
use App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift\AtlasLoopAmbitionFacultyTarget;
use App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift\AtlasLoopOperatorIntentDriftFact;
use Tests\TestCase;

final class AtlasLoopAmbitionFacultyRecalibratorTest extends TestCase
{
    public function test_recommendation_is_bounded_by_max_step_and_clamped_to_unit_interval(): void
    {
        config(['atlas.loop.quaternity.intent_drift.max_step' => 0.15]);
        $recalibrator = new AtlasLoopAmbitionFacultyRecalibrator;

        foreach ([0.0, 0.1, 0.5, 1.0, 5.0] as $overallL2) {
            foreach ([0.0, 0.5, 1.0] as $startingTarget) {
                $current = new AtlasLoopAmbitionFacultyTarget($startingTarget, ['ambition_target' => 1.0]);
                $result = $recalibrator->recommend($this->fact($overallL2, 1), $current);

                $this->assertArrayHasKey('target', $result);
                $this->assertArrayHasKey('reversal_token', $result);
                $this->assertInstanceOf(AtlasLoopAmbitionFacultyTarget::class, $result['target']);
                $this->assertInstanceOf(AtlasLoopAmbitionFacultyReversalToken::class, $result['reversal_token']);
                $this->assertLessThanOrEqual(0.150001, abs($result['target']->ambitionTarget - $startingTarget));
                $this->assertGreaterThanOrEqual(0.0, $result['target']->ambitionTarget);
                $this->assertLessThanOrEqual(1.0, $result['target']->ambitionTarget);
            }
        }
    }

    public function test_deadband_returns_noop_token_and_apply_preserves_current_instance(): void
    {
        config(['atlas.loop.quaternity.intent_drift.deadband' => 0.05]);
        $recalibrator = new AtlasLoopAmbitionFacultyRecalibrator;
        $current = new AtlasLoopAmbitionFacultyTarget(0.5, ['scope_pressure' => 0.8]);

        $result = $recalibrator->recommend($this->fact(0.01, 1), $current);
        $reverted = $recalibrator->apply($result['reversal_token'], $result['target']);

        $this->assertSame(AtlasLoopAmbitionFacultyReversalToken::MODE_NOOP, $result['reversal_token']->mode);
        $this->assertSame($current, $result['target']);
        $this->assertSame($current, $reverted);
        $this->assertSame($current->canonicalBytes(), $result['reversal_token']->priorTargetBytes);
    }

    public function test_recalibration_token_reverts_to_prior_target_byte_identical(): void
    {
        config([
            'atlas.loop.quaternity.intent_drift.deadband' => 0.05,
            'atlas.loop.quaternity.intent_drift.max_step' => 0.15,
        ]);
        $recalibrator = new AtlasLoopAmbitionFacultyRecalibrator;
        $current = new AtlasLoopAmbitionFacultyTarget(0.45, ['scope_pressure' => 0.8, 'novelty' => 0.2]);

        $result = $recalibrator->recommend($this->fact(0.5, 1), $current);
        $reverted = $recalibrator->apply($result['reversal_token'], $result['target']);

        $this->assertSame(AtlasLoopAmbitionFacultyReversalToken::MODE_RECALIBRATION, $result['reversal_token']->mode);
        $this->assertNotSame($current->canonicalBytes(), $result['target']->canonicalBytes());
        $this->assertSame($current->canonicalBytes(), $reverted->canonicalBytes());
    }

    public function test_recommend_and_apply_methods_have_required_signatures(): void
    {
        $recalibrator = new AtlasLoopAmbitionFacultyRecalibrator;
        $current = new AtlasLoopAmbitionFacultyTarget(0.5, []);
        $result = $recalibrator->recommend($this->fact(0.5, -1), $current);

        $this->assertIsArray($result);
        $this->assertInstanceOf(AtlasLoopAmbitionFacultyTarget::class, $result['target']);
        $this->assertInstanceOf(
            AtlasLoopAmbitionFacultyTarget::class,
            $recalibrator->apply($result['reversal_token'], $result['target']),
        );
    }

    private function fact(float $overallL2, int $direction): AtlasLoopOperatorIntentDriftFact
    {
        return new AtlasLoopOperatorIntentDriftFact(
            axisMagnitudes: ['ambition_target' => $overallL2],
            axisDirections: ['ambition_target' => $direction],
            overallL2Magnitude: $overallL2,
            windowSize: 4,
            dominantAxis: 'ambition_target',
        );
    }
}
