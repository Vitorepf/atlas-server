<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionCircuitCollapseAdvisor;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionCircuitCollapseAdvisorTest extends TestCase
{
    public function test_high_overlap_and_ready_behavior_is_recommended(): void
    {
        $result = (new AtlasSelfConstructionCircuitCollapseAdvisor)->advise([
            [
                'group_id' => 'g1',
                'organs' => ['OrganA', 'OrganB'],
                'overlap_score' => 0.85,
                'behavior_equivalence_status' => AtlasSelfConstructionCircuitCollapseAdvisor::STATUS_READY,
            ],
        ]);

        self::assertTrue($result['recommendations'][0]['recommended']);
        self::assertSame([], $result['recommendations'][0]['blockers']);
    }

    public function test_low_overlap_is_blocked_even_with_ready_behavior(): void
    {
        $result = (new AtlasSelfConstructionCircuitCollapseAdvisor)->advise([
            [
                'group_id' => 'g1',
                'organs' => ['OrganA', 'OrganB'],
                'overlap_score' => 0.20,
                'behavior_equivalence_status' => AtlasSelfConstructionCircuitCollapseAdvisor::STATUS_READY,
            ],
        ]);

        self::assertFalse($result['recommendations'][0]['recommended']);
        self::assertStringContainsString('overlap_below_threshold', $result['recommendations'][0]['blockers'][0]);
    }

    public function test_missing_behavior_proof_is_blocked_even_with_high_overlap(): void
    {
        $result = (new AtlasSelfConstructionCircuitCollapseAdvisor)->advise([
            [
                'group_id' => 'g1',
                'organs' => ['OrganA', 'OrganB'],
                'overlap_score' => 0.90,
                'behavior_equivalence_status' => 'blocked',
            ],
        ]);

        self::assertFalse($result['recommendations'][0]['recommended']);
        self::assertContains('behavior_equivalence_not_ready', $result['recommendations'][0]['blockers']);
    }

    public function test_fewer_than_two_organs_is_blocked(): void
    {
        $result = (new AtlasSelfConstructionCircuitCollapseAdvisor)->advise([
            [
                'group_id' => 'g1',
                'organs' => ['OrganA'],
                'overlap_score' => 0.90,
                'behavior_equivalence_status' => AtlasSelfConstructionCircuitCollapseAdvisor::STATUS_READY,
            ],
        ]);

        self::assertFalse($result['recommendations'][0]['recommended']);
        self::assertContains('fewer_than_two_organs', $result['recommendations'][0]['blockers']);
    }

    public function test_multiple_groups_are_evaluated_independently(): void
    {
        $result = (new AtlasSelfConstructionCircuitCollapseAdvisor)->advise([
            [
                'group_id' => 'g1',
                'organs' => ['A', 'B'],
                'overlap_score' => 0.90,
                'behavior_equivalence_status' => AtlasSelfConstructionCircuitCollapseAdvisor::STATUS_READY,
            ],
            [
                'group_id' => 'g2',
                'organs' => ['C', 'D'],
                'overlap_score' => 0.10,
                'behavior_equivalence_status' => 'blocked',
            ],
        ]);

        self::assertTrue($result['recommendations'][0]['recommended']);
        self::assertFalse($result['recommendations'][1]['recommended']);
    }
}
