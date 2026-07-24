<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Rivals;

use App\Services\Ai\Rivals\Core\RivalsClaimAuthority;
use App\Services\Ai\Rivals\Core\RivalsCurriculumLadder;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * P2g-CURR: 50× / strong multiplier claim blocked on sanity or saturated frontier.
 */
final class AaeosRivalsSaturatedFrontierBlocks50xClaimTest extends TestCase
{
    public function test_law_blocks_multiplier_on_sanity(): void
    {
        $blockers = RivalsCurriculumLadder::claimBlockers([
            'curriculum_role' => RivalsCurriculumLadder::ROLE_SANITY,
            'claim_level' => 'multiplier_proven',
            'm_excellence' => 50.0,
        ]);

        $this->assertContains('claim_on_sanity_forbidden', $blockers);
    }

    public function test_law_blocks_multiplier_on_saturated_frontier(): void
    {
        $blockers = RivalsCurriculumLadder::claimBlockers([
            'curriculum_role' => RivalsCurriculumLadder::ROLE_FRONTIER,
            'frontier_saturated' => true,
            'm_excellence_claim' => true,
            'm_excellence' => 50.0,
        ]);

        $this->assertContains('claim_on_saturated_frontier_forbidden', $blockers);
    }

    public function test_anti_ceiling_fallacy_blocked(): void
    {
        $blockers = RivalsCurriculumLadder::claimBlockers([
            'curriculum_role' => RivalsCurriculumLadder::ROLE_FRONTIER,
            'high_score_on_easy_as_max_multiplier' => true,
            'm_excellence' => 1.0,
        ]);

        $this->assertContains('anti_ceiling_fallacy', $blockers);
    }

    public function test_claim_authority_rejects_sanity_multiplier_proven(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('claim_on_sanity_forbidden');

        (new RivalsClaimAuthority)->issue($this->multiplierEvidence([
            'curriculum_role' => RivalsCurriculumLadder::ROLE_SANITY,
            'frontier_saturated' => false,
        ]));
    }

    public function test_claim_authority_rejects_saturated_frontier_multiplier(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('claim_on_saturated_frontier_forbidden');

        (new RivalsClaimAuthority)->issue($this->multiplierEvidence([
            'curriculum_role' => RivalsCurriculumLadder::ROLE_FRONTIER,
            'frontier_saturated' => true,
        ]));
    }

    public function test_claim_authority_issues_frontier_multiplier_when_not_saturated(): void
    {
        $claim = (new RivalsClaimAuthority)->issue($this->multiplierEvidence([
            'curriculum_role' => RivalsCurriculumLadder::ROLE_FRONTIER,
            'level_id' => 'L1_frontier_engineering',
            'frontier_saturated' => false,
        ]));

        $this->assertSame('issued', $claim['status']);
        $this->assertSame('multiplier_proven', $claim['level']);
        $this->assertTrue($claim['claim_eligible']);
    }

    /**
     * @param  array<string,mixed>  $curriculum
     * @return array<string,mixed>
     */
    private function multiplierEvidence(array $curriculum): array
    {
        return array_merge([
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
            'm_excellence_claim' => true,
            'm_excellence' => 50.0,
        ], $curriculum);
    }
}
