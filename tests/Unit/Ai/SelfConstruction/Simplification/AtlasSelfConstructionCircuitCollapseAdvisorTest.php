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

    private function fullSafeEvidence(): array
    {
        return [
            'cluster' => ['merge_ready' => true, 'duplicate_confidence' => 'medium'],
            'cohesion' => ['recommendation' => 'split_or_collapse'],
            'consumer_impact' => ['risk_level' => 'low'],
            'parity' => ['replacement_allowed' => true],
            'replay' => ['promotion_allowed' => true],
            'rollback' => ['reversible' => true],
        ];
    }

    public function test_duplicate_cluster_with_full_proof_and_low_risk_recommends_merge(): void
    {
        $result = (new AtlasSelfConstructionCircuitCollapseAdvisor)->decide($this->fullSafeEvidence());

        self::assertSame(AtlasSelfConstructionCircuitCollapseAdvisor::ACTION_MERGE, $result['action']);
        self::assertSame([], $result['blockers']);
    }

    public function test_duplicate_cluster_with_high_confidence_recommends_delete(): void
    {
        $evidence = $this->fullSafeEvidence();
        $evidence['cluster']['duplicate_confidence'] = 'high';

        $result = (new AtlasSelfConstructionCircuitCollapseAdvisor)->decide($evidence);

        self::assertSame(AtlasSelfConstructionCircuitCollapseAdvisor::ACTION_DELETE, $result['action']);
    }

    public function test_high_impact_candidate_without_parity_or_replay_is_blocked(): void
    {
        $result = (new AtlasSelfConstructionCircuitCollapseAdvisor)->decide([
            'cluster' => ['merge_ready' => false],
            'consumer_impact' => ['risk_level' => 'high'],
            'parity' => ['replacement_allowed' => false],
            'replay' => ['promotion_allowed' => false],
            'rollback' => ['reversible' => false],
        ]);

        self::assertSame(AtlasSelfConstructionCircuitCollapseAdvisor::ACTION_BLOCK, $result['action']);
        self::assertContains('capability_parity_not_proven', $result['blockers']);
        self::assertContains('shadow_replay_not_promoted', $result['blockers']);
        self::assertContains('consumer_risk_high', $result['blockers']);
    }

    public function test_merge_ready_but_missing_proof_is_blocked_not_silently_merged(): void
    {
        $evidence = $this->fullSafeEvidence();
        $evidence['parity']['replacement_allowed'] = false;

        $result = (new AtlasSelfConstructionCircuitCollapseAdvisor)->decide($evidence);

        self::assertSame(AtlasSelfConstructionCircuitCollapseAdvisor::ACTION_BLOCK, $result['action']);
        self::assertContains('capability_parity_not_proven', $result['blockers']);
    }

    public function test_cohesive_non_duplicate_circuit_recommends_keep(): void
    {
        $result = (new AtlasSelfConstructionCircuitCollapseAdvisor)->decide([
            'cluster' => ['merge_ready' => false],
            'cohesion' => ['recommendation' => 'keep'],
            'consumer_impact' => ['risk_level' => 'low'],
        ]);

        self::assertSame(AtlasSelfConstructionCircuitCollapseAdvisor::ACTION_KEEP, $result['action']);
        self::assertSame([], $result['blockers']);
    }

    public function test_non_duplicate_but_bloated_circuit_recommends_extract(): void
    {
        $result = (new AtlasSelfConstructionCircuitCollapseAdvisor)->decide([
            'cluster' => ['merge_ready' => false],
            'cohesion' => ['recommendation' => 'split_or_collapse'],
            'consumer_impact' => ['risk_level' => 'low'],
        ]);

        self::assertSame(AtlasSelfConstructionCircuitCollapseAdvisor::ACTION_EXTRACT, $result['action']);
    }
}
