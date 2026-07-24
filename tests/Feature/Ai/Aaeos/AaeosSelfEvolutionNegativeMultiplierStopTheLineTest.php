<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Aaeos\Control\AaeosSelfEvolutionQualityLoop;
use App\Services\Ai\Rivals\Core\RivalsCurriculumLadder;
use App\Services\Ai\Rivals\Core\RivalsExcellenceMeasure;
use Tests\TestCase;

/**
 * P2g-EVOL: M<1 → stop-the-line repair priority before ambition cosmetics.
 */
final class AaeosSelfEvolutionNegativeMultiplierStopTheLineTest extends TestCase
{
    public function test_negative_m_enqueues_stop_the_line_repair_first(): void
    {
        $measure = RivalsExcellenceMeasure::report([
            'n_raw' => 0.80,
            'n_atlas' => 0.40,
            'dual_arm' => true,
            'same_mu' => true,
            'curriculum_role' => RivalsCurriculumLadder::ROLE_FRONTIER,
            'frontier_saturated' => false,
            'sample_n' => 40,
            'r104_transport_open' => true,
            'r104_symmetry_attested' => false,
            'claim_m_threshold' => 50.0,
        ]);

        $this->assertTrue($measure['multiplier_negative']);
        $this->assertTrue($measure['stop_the_line']);

        $plan = AaeosSelfEvolutionQualityLoop::planFromMeasure($measure, [
            'frontier_saturated' => true, // even if saturated, stop-the-line wins
            'domain_pass_rate' => 0.99,
            'stable_window' => true,
        ]);

        $this->assertSame(AaeosSelfEvolutionQualityLoop::DISPOSITION_STOP_THE_LINE, $plan['disposition']);
        $this->assertSame(AaeosSelfEvolutionQualityLoop::PRIORITY_STOP_THE_LINE, $plan['priority']);
        $this->assertTrue($plan['enqueue_allowed']);
        $this->assertCount(1, $plan['enqueue_intents']);
        $this->assertSame('channel_or_path_repair', $plan['enqueue_intents'][0]['kind']);
        $this->assertGreaterThanOrEqual(
            AaeosSelfEvolutionQualityLoop::PRIORITY_PROMOTE,
            $plan['enqueue_intents'][0]['priority'],
        );
        $this->assertStringContainsString('Stop-the-line', $plan['enqueue_intents'][0]['objective']);
        $this->assertSame(0, $plan['operator_task_causal_count']);
        $this->assertFalse($plan['operator_eng_required']);
    }

    public function test_operator_causal_actions_block_enqueue(): void
    {
        $measure = RivalsExcellenceMeasure::report([
            'n_raw' => 0.80,
            'n_atlas' => 0.40,
            'dual_arm' => true,
            'same_mu' => true,
            'curriculum_role' => RivalsCurriculumLadder::ROLE_FRONTIER,
            'sample_n' => 10,
            'r104_transport_open' => false,
            'r104_symmetry_attested' => true,
        ]);

        $plan = AaeosSelfEvolutionQualityLoop::planFromMeasure($measure, [
            'operator_task_causal_count' => 1,
        ]);

        $this->assertFalse($plan['enqueue_allowed']);
        $this->assertContains('operator_task_causal_forbidden', $plan['blockers']);
        // Still records disposition for observability, but intents empty.
        $this->assertSame(AaeosSelfEvolutionQualityLoop::DISPOSITION_STOP_THE_LINE, $plan['disposition']);
        $this->assertSame([], $plan['enqueue_intents']);
    }
}
