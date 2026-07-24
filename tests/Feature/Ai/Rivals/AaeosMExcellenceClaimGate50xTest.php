<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Rivals;

use App\Services\Ai\Rivals\Core\RivalsClaimAuthority;
use App\Services\Ai\Rivals\Core\RivalsCurriculumLadder;
use App\Services\Ai\Rivals\Core\RivalsExcellenceMeasure;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * P2g-MEAS: M≥50 claim only with valid dual-arm frontier measure.
 */
final class AaeosMExcellenceClaimGate50xTest extends TestCase
{
    public function test_m50_claim_allowed_when_all_predicates_hold(): void
    {
        $report = RivalsExcellenceMeasure::report([
            'n_raw' => 0.01,
            'n_atlas' => 0.55,
            'epsilon' => 0.02,
            'dual_arm' => true,
            'same_mu' => true,
            'curriculum_role' => RivalsCurriculumLadder::ROLE_FRONTIER,
            'curriculum_level_id' => 'L1_frontier_engineering',
            'frontier_saturated' => false,
            'sample_n' => 30,
            'min_sample_n' => 10,
            'claim_m_threshold' => 50.0,
            'r104_transport_open' => false,
            'r104_symmetry_attested' => true,
        ]);

        // 0.55 / max(0.01, 0.02) = 27.5 — below 50; adjust rates for true ≥50
        $report = RivalsExcellenceMeasure::report([
            'n_raw' => 0.01,
            'n_atlas' => 1.0,
            'epsilon' => 0.02,
            'dual_arm' => true,
            'same_mu' => true,
            'curriculum_role' => RivalsCurriculumLadder::ROLE_FRONTIER,
            'curriculum_level_id' => 'L1_frontier_engineering',
            'frontier_saturated' => false,
            'sample_n' => 30,
            'min_sample_n' => 10,
            'claim_m_threshold' => 50.0,
            'r104_transport_open' => false,
            'r104_symmetry_attested' => true,
        ]);

        $this->assertEqualsWithDelta(50.0, $report['M_excellence'], 0.0001);
        $this->assertTrue($report['claim_allowed']);
        $this->assertSame([], $report['claim_blockers']);
    }

    public function test_asymmetric_or_open_r104_blocks_50x_claim(): void
    {
        $report = RivalsExcellenceMeasure::report([
            'n_raw' => 0.01,
            'n_atlas' => 1.0,
            'epsilon' => 0.02,
            'dual_arm' => true,
            'same_mu' => true,
            'curriculum_role' => RivalsCurriculumLadder::ROLE_FRONTIER,
            'frontier_saturated' => false,
            'sample_n' => 30,
            'r104_transport_open' => true,
            'r104_symmetry_attested' => false,
        ]);

        $this->assertFalse($report['claim_allowed']);
        $this->assertContains('r104_symmetry_not_attested', $report['claim_blockers']);
    }

    public function test_claim_authority_issues_when_measure_and_curriculum_pass(): void
    {
        $report = RivalsExcellenceMeasure::report([
            'n_raw' => 0.01,
            'n_atlas' => 1.0,
            'epsilon' => 0.02,
            'dual_arm' => true,
            'same_mu' => true,
            'curriculum_role' => RivalsCurriculumLadder::ROLE_FRONTIER,
            'frontier_saturated' => false,
            'sample_n' => 30,
            'r104_transport_open' => false,
            'r104_symmetry_attested' => true,
        ]);
        $this->assertTrue($report['claim_allowed']);

        $claim = (new RivalsClaimAuthority)->issue([
            'adjudication_status' => 'passed',
            'claim_level' => 'multiplier_proven',
            'run_state' => 'bundled',
            'metric_weights' => ['quality_loss' => 1.0, 'cost' => 0.0],
            'scope' => [
                'suite' => 'atlas-bench',
                'mode' => 'atlas',
                'risk' => 'R3',
                'duration' => 'durable_task',
                'stack' => 'php_laravel',
                'unit_population' => 'atlas_bench_cases',
                'cases' => ['c1', 'c2', 'c3'],
                'curriculum_role' => RivalsCurriculumLadder::ROLE_FRONTIER,
                'frontier_saturated' => false,
            ],
            'baseline_hash' => str_repeat('a', 64),
            'evidence_pack_hash' => str_repeat('b', 64),
            'experiment_hash' => str_repeat('c', 64),
            'effect' => 50.0,
            'ci_low' => 40.0,
            'ci_high' => 60.0,
            'exposure' => ['campaigns' => 3, 'attempts' => 300, 'distinct_cases' => 3],
            'issued_at' => '2026-07-12T00:00:00Z',
            'expires_at' => '2026-10-10T00:00:00Z',
            'invalidators' => ['frontier_change', 'late_adverse_outcome'],
            'evidence_refs' => ['rivals://evidence-pack/b', 'ledger://experiment/c'],
            'm_excellence_claim' => true,
            'm_excellence' => $report['M_excellence'],
            'curriculum_role' => RivalsCurriculumLadder::ROLE_FRONTIER,
            'frontier_saturated' => false,
        ]);

        $this->assertSame('issued', $claim['status']);
    }

    public function test_claim_authority_still_rejects_without_frontier_curriculum(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RivalsClaimAuthority)->issue([
            'adjudication_status' => 'passed',
            'claim_level' => 'multiplier_proven',
            'run_state' => 'bundled',
            'metric_weights' => ['quality_loss' => 1.0, 'cost' => 0.0],
            'scope' => [
                'suite' => 'atlas-bench',
                'mode' => 'atlas',
                'risk' => 'R3',
                'duration' => 'durable_task',
                'stack' => 'php_laravel',
                'unit_population' => 'atlas_bench_cases',
                'cases' => ['c1', 'c2', 'c3'],
            ],
            'baseline_hash' => str_repeat('a', 64),
            'evidence_pack_hash' => str_repeat('b', 64),
            'experiment_hash' => str_repeat('c', 64),
            'effect' => 50.0,
            'ci_low' => 40.0,
            'ci_high' => 60.0,
            'exposure' => ['campaigns' => 3, 'attempts' => 300, 'distinct_cases' => 3],
            'issued_at' => '2026-07-12T00:00:00Z',
            'expires_at' => '2026-10-10T00:00:00Z',
            'invalidators' => ['frontier_change', 'late_adverse_outcome'],
            'evidence_refs' => ['rivals://evidence-pack/b', 'ledger://experiment/c'],
            'm_excellence' => 50.0,
        ]);
    }
}
