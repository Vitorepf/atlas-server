<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Aaeos\Control\AaeosSelfEvolutionQualityLoop;
use App\Services\Ai\Rivals\Core\RivalsCurriculumLadder;
use App\Services\Ai\Rivals\Core\RivalsExcellenceMeasure;
use Tests\TestCase;

/**
 * P2g-EVOL: frontier saturation → curriculum promote + real work enqueue.
 */
final class AaeosSelfEvolutionFrontierPromoteEnqueuesWorkTest extends TestCase
{
    public function test_saturation_promotes_school_and_enqueues_next_level_work(): void
    {
        $measure = RivalsExcellenceMeasure::report([
            'n_raw' => 0.9,
            'n_atlas' => 0.95,
            'dual_arm' => true,
            'same_mu' => true,
            'curriculum_role' => RivalsCurriculumLadder::ROLE_FRONTIER,
            'curriculum_level_id' => 'L1_frontier_engineering',
            'frontier_saturated' => true,
            'sample_n' => 40,
            'r104_transport_open' => false,
            'r104_symmetry_attested' => true,
            'claim_m_threshold' => 50.0,
        ]);

        $plan = AaeosSelfEvolutionQualityLoop::planFromMeasure($measure, [
            'level_id' => 'L1_frontier_engineering',
            'next_level_id' => 'L2_horizon_unsolved',
            'domain_pass_rate' => 0.95,
            'promotion_bar' => 0.90,
            'stable_window' => true,
            'operator_task_causal_count' => 0,
        ]);

        $this->assertSame(AaeosSelfEvolutionQualityLoop::SCHEMA, $plan['schema']);
        $this->assertSame(AaeosSelfEvolutionQualityLoop::DISPOSITION_PROMOTE_CURRICULUM, $plan['disposition']);
        $this->assertTrue($plan['enqueue_allowed']);
        $this->assertNotEmpty($plan['enqueue_intents']);
        $this->assertSame(0, $plan['operator_task_causal_count']);
        $this->assertFalse($plan['operator_eng_required']);
        $this->assertTrue($plan['acde_forbidden']);
        $this->assertTrue($plan['uses_existing_qos_path']);

        $intent = $plan['enqueue_intents'][0];
        $this->assertSame('next_level_excellence', $intent['kind']);
        $this->assertStringContainsString('L2_horizon_unsolved', $intent['objective']);
        $this->assertFalse(AaeosSelfEvolutionQualityLoop::isProxyIntent($intent));
        $this->assertTrue((bool) ($plan['curriculum_promotion']['promoted'] ?? false));
        $this->assertSame('curriculum_level_promoted', $plan['curriculum_promotion']['event'] ?? null);
        $this->assertTrue($plan['remeasure']['required']);
    }

    public function test_no_saturation_and_high_m_holds_without_fake_work(): void
    {
        $measure = RivalsExcellenceMeasure::report([
            'n_raw' => 0.01,
            'n_atlas' => 1.0,
            'epsilon' => 0.02,
            'dual_arm' => true,
            'same_mu' => true,
            'curriculum_role' => RivalsCurriculumLadder::ROLE_FRONTIER,
            'frontier_saturated' => false,
            'sample_n' => 40,
            'r104_transport_open' => false,
            'r104_symmetry_attested' => true,
            'claim_m_threshold' => 50.0,
        ]);

        $plan = AaeosSelfEvolutionQualityLoop::planFromMeasure($measure, [
            'ambition_m_threshold' => 50.0,
            'domain_pass_rate' => 0.40,
            'stable_window' => false,
        ]);

        // M_excellence = 50 → meets threshold → hold (no vanity enqueue)
        $this->assertSame(AaeosSelfEvolutionQualityLoop::DISPOSITION_HOLD, $plan['disposition']);
        $this->assertFalse($plan['enqueue_allowed']);
        $this->assertSame([], $plan['enqueue_intents']);
    }
}
