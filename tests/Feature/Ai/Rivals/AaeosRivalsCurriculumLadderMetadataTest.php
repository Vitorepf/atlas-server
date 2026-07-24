<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Rivals;

use App\Services\Ai\Rivals\Core\RivalsCurriculumLadder;
use Tests\TestCase;

/**
 * P2g-CURR: curriculum ladder metadata + promotion pure rule.
 */
final class AaeosRivalsCurriculumLadderMetadataTest extends TestCase
{
    public function test_config_declares_sanity_and_frontier_levels(): void
    {
        $levels = config('atlas_rivals.curriculum.levels', []);
        $this->assertIsArray($levels);
        $this->assertNotEmpty($levels);

        $roles = array_map(
            static fn (array $level): string => (string) ($level['curriculum_role'] ?? ''),
            $levels,
        );

        $this->assertContains(RivalsCurriculumLadder::ROLE_SANITY, $roles);
        $this->assertContains(RivalsCurriculumLadder::ROLE_FRONTIER, $roles);
        $this->assertContains(RivalsCurriculumLadder::ROLE_HORIZON, $roles);

        foreach ($levels as $level) {
            $this->assertArrayHasKey('level_id', $level);
            $this->assertArrayHasKey('curriculum_role', $level);
            $this->assertArrayHasKey('promotion_bar', $level);
            $this->assertArrayHasKey('predecessor_level_id', $level);
            $this->assertNotNull(RivalsCurriculumLadder::normalizeRole((string) $level['curriculum_role']));
        }
    }

    public function test_promotion_emits_curriculum_level_promoted_when_stable_bar_met(): void
    {
        $result = RivalsCurriculumLadder::evaluatePromotion([
            'curriculum_role' => RivalsCurriculumLadder::ROLE_FRONTIER,
            'level_id' => 'L1_frontier_engineering',
            'predecessor_level_id' => 'L0_sanity_smoke',
            'next_level_id' => 'L2_horizon_unsolved',
            'domain_pass_rate' => 0.93,
            'promotion_bar' => 0.90,
            'stable_window' => true,
        ]);

        $this->assertTrue($result['promoted']);
        $this->assertSame('curriculum_level_promoted', $result['event']);
        $this->assertSame('L2_horizon_unsolved', $result['next_level_id']);
    }

    public function test_promotion_holds_when_unstable_or_below_bar(): void
    {
        $below = RivalsCurriculumLadder::evaluatePromotion([
            'level_id' => 'L1_frontier_engineering',
            'domain_pass_rate' => 0.50,
            'promotion_bar' => 0.90,
            'stable_window' => true,
        ]);
        $unstable = RivalsCurriculumLadder::evaluatePromotion([
            'level_id' => 'L1_frontier_engineering',
            'domain_pass_rate' => 0.99,
            'promotion_bar' => 0.90,
            'stable_window' => false,
        ]);

        $this->assertFalse($below['promoted']);
        $this->assertFalse($unstable['promoted']);
        $this->assertSame('curriculum_level_hold', $below['event']);
    }

    public function test_evaluate_marks_raise_curriculum_not_ceiling(): void
    {
        $eval = RivalsCurriculumLadder::evaluate([
            'curriculum_role' => RivalsCurriculumLadder::ROLE_FRONTIER,
            'frontier_saturated' => false,
            'claim_level' => 'multiplier_proven',
        ]);

        $this->assertTrue($eval['claim_allowed']);
        $this->assertTrue($eval['raise_curriculum_not_ceiling']);
        $this->assertSame(RivalsCurriculumLadder::ROLE_FRONTIER, $eval['curriculum_role']);
    }
}
