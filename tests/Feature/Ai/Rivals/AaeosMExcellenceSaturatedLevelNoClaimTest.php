<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Rivals;

use App\Services\Ai\Rivals\Core\RivalsCurriculumLadder;
use App\Services\Ai\Rivals\Core\RivalsExcellenceMeasure;
use Tests\TestCase;

/**
 * P2g-MEAS: saturated level cannot claim 50×; negative M stops the line.
 */
final class AaeosMExcellenceSaturatedLevelNoClaimTest extends TestCase
{
    public function test_saturated_frontier_blocks_even_with_huge_m(): void
    {
        $report = RivalsExcellenceMeasure::report([
            'n_raw' => 0.01,
            'n_atlas' => 1.0,
            'epsilon' => 0.02,
            'dual_arm' => true,
            'same_mu' => true,
            'curriculum_role' => RivalsCurriculumLadder::ROLE_FRONTIER,
            'frontier_saturated' => true,
            'sample_n' => 50,
            'r104_transport_open' => false,
            'r104_symmetry_attested' => true,
        ]);

        $this->assertGreaterThanOrEqual(50.0, $report['M_excellence']);
        $this->assertFalse($report['claim_allowed']);
        $this->assertContains('saturated_frontier_no_claim', $report['claim_blockers']);
    }

    public function test_sanity_role_cannot_claim_m_excellence(): void
    {
        $report = RivalsExcellenceMeasure::report([
            'n_raw' => 0.01,
            'n_atlas' => 1.0,
            'dual_arm' => true,
            'same_mu' => true,
            'curriculum_role' => RivalsCurriculumLadder::ROLE_SANITY,
            'frontier_saturated' => false,
            'sample_n' => 50,
            'r104_transport_open' => false,
            'r104_symmetry_attested' => true,
        ]);

        $this->assertFalse($report['claim_allowed']);
        $this->assertContains('frontier_curriculum_required', $report['claim_blockers']);
    }

    public function test_negative_multiplier_emits_stop_the_line(): void
    {
        $report = RivalsExcellenceMeasure::report([
            'n_raw' => 0.80,
            'n_atlas' => 0.40,
            'dual_arm' => true,
            'same_mu' => true,
            'curriculum_role' => RivalsCurriculumLadder::ROLE_FRONTIER,
            'frontier_saturated' => false,
            'sample_n' => 50,
            'r104_transport_open' => false,
            'r104_symmetry_attested' => true,
            'claim_m_threshold' => 50.0,
        ]);

        $this->assertTrue($report['multiplier_negative']);
        $this->assertTrue($report['stop_the_line']);
        $this->assertContains('multiplier_negative', $report['claim_blockers']);
        $this->assertFalse($report['claim_allowed']);
    }
}
