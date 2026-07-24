<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Rivals;

use App\Services\Ai\Rivals\Core\RivalsCurriculumLadder;
use App\Services\Ai\Rivals\Core\RivalsExcellenceMeasure;
use Tests\TestCase;

/**
 * P2g-MEAS: dual-arm same-μ report fields for M_excellence.
 */
final class AaeosMExcellenceDualArmReportTest extends TestCase
{
    public function test_report_computes_m_excellence_with_epsilon_floor(): void
    {
        $report = RivalsExcellenceMeasure::report([
            'n_raw' => 0.0,
            'n_atlas' => 0.5,
            'epsilon' => 0.02,
            'dual_arm' => true,
            'same_mu' => true,
            'curriculum_role' => RivalsCurriculumLadder::ROLE_FRONTIER,
            'curriculum_level_id' => 'L1_frontier_engineering',
            'frontier_saturated' => false,
            'sample_n' => 20,
            'r104_transport_open' => false,
            'r104_symmetry_attested' => true,
        ]);

        $this->assertSame(RivalsExcellenceMeasure::SCHEMA, $report['schema']);
        $this->assertSame(0.0, $report['N_raw']);
        $this->assertSame(0.5, $report['N_atlas']);
        $this->assertEqualsWithDelta(25.0, $report['M_excellence'], 0.0001);
        $this->assertSame('L1_frontier_engineering', $report['curriculum_level_id']);
        $this->assertFalse($report['multiplier_negative']);
    }

    public function test_missing_dual_arm_blocks_claim(): void
    {
        $report = RivalsExcellenceMeasure::report([
            'n_raw' => 0.02,
            'n_atlas' => 1.0,
            'dual_arm' => false,
            'same_mu' => true,
            'curriculum_role' => RivalsCurriculumLadder::ROLE_FRONTIER,
            'sample_n' => 10,
            'r104_transport_open' => false,
            'r104_symmetry_attested' => true,
        ]);

        $this->assertFalse($report['claim_allowed']);
        $this->assertContains('dual_arm_required', $report['claim_blockers']);
    }

    public function test_excellence_pass_conjunctive_for_raw_and_atlas(): void
    {
        $raw = RivalsExcellenceMeasure::excellencePass([
            'solution_applies' => true,
            'acceptance_criteria_pass' => true,
            'anti_fake_green' => true,
            'write_set_in_scope' => true,
            'arm' => 'raw',
        ]);
        $atlasFailCourt = RivalsExcellenceMeasure::excellencePass([
            'solution_applies' => true,
            'acceptance_criteria_pass' => true,
            'anti_fake_green' => true,
            'write_set_in_scope' => true,
            'arm' => 'atlas',
            'court_floor_promote' => false,
        ]);
        $atlasPass = RivalsExcellenceMeasure::excellencePass([
            'solution_applies' => true,
            'acceptance_criteria_pass' => true,
            'anti_fake_green' => true,
            'write_set_in_scope' => true,
            'arm' => 'atlas',
            'court_floor_promote' => true,
        ]);

        $this->assertTrue($raw);
        $this->assertFalse($atlasFailCourt);
        $this->assertTrue($atlasPass);
    }
}
