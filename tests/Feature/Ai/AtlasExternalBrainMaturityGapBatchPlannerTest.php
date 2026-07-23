<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityMapDriftDetector;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMaturityGapBatchPlanner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMaturityGapIndex;
use Tests\TestCase;

final class AtlasExternalBrainMaturityGapBatchPlannerTest extends TestCase
{
    public function test_merges_gap_index_and_drift_findings_into_ranked_batch(): void
    {
        $gapIndex = (new AtlasExternalBrainMaturityGapIndex())->compute(
            [
                [
                    'dimension' => 'evidence_ledger',
                    'leverage' => 0.9,
                    'required_evidence_signals' => ['ledger_append_proof'],
                    'task_family' => 'evidence_ledger_hardening',
                ],
            ],
            ['proven_evidence' => []],
        );

        $drift = (new AtlasExternalBrainCapabilityMapDriftDetector())->detect([
            'map_entries' => [
                [
                    'area_id' => 'domain_x',
                    'state' => 'integrated',
                    'has_completion_evidence' => false,
                    'owner' => 'atlas',
                    'maturity_band' => 'advanced',
                    'last_updated_age_days' => 1,
                ],
            ],
            'queued_areas' => [],
        ]);

        $result = (new AtlasExternalBrainMaturityGapBatchPlanner())->plan($gapIndex, $drift);

        $this->assertNotEmpty($result['batch']);
        $categories = array_column($result['batch'], 'category');
        $this->assertContains('proof_gap_closure', $categories);
        $this->assertContains('contradictory_stale_repair', $categories);

        // proof_gap_closure must sort before contradictory_stale_repair.
        $proofIndex = array_search('proof_gap_closure', $categories, true);
        $contradictoryIndex = array_search('contradictory_stale_repair', $categories, true);
        $this->assertLessThan($contradictoryIndex, $proofIndex);
    }

    public function test_missing_next_leverage_recovery_ranks_before_other_drift(): void
    {
        $emptyGaps = ['gaps' => []];

        $drift = [
            'findings' => [
                ['area_id' => 'a', 'drift_type' => AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MISSING_OWNER, 'impact' => 'medium', 'repair_action' => 'assign_owner'],
                ['area_id' => 'b', 'drift_type' => AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MISSING_NEXT_LEVERAGE, 'impact' => 'medium', 'repair_action' => 'run_leverage_assessment'],
            ],
        ];

        $result = (new AtlasExternalBrainMaturityGapBatchPlanner())->plan($emptyGaps, $drift);

        $this->assertSame('missing_next_leverage_recovery', $result['batch'][0]['category']);
        $this->assertSame('other_drift_repair', $result['batch'][1]['category']);
    }

    public function test_each_batch_item_carries_resolution_approach_and_leverage(): void
    {
        $gapIndex = ['gaps' => [
            ['dimension' => 'd1', 'leverage' => 0.5, 'next_best_task_family' => 'd1_bootstrap'],
        ]];
        $drift = ['findings' => []];

        $result = (new AtlasExternalBrainMaturityGapBatchPlanner())->plan($gapIndex, $drift);

        $this->assertSame('d1_bootstrap', $result['batch'][0]['resolution_approach']);
        $this->assertSame(0.5, $result['batch'][0]['leverage']);
    }

    public function test_each_batch_item_carries_unlock_count_and_dependency_blockers(): void
    {
        $gapIndex = ['gaps' => [
            ['dimension' => 'd1', 'leverage' => 0.5, 'next_best_task_family' => 'd1_bootstrap', 'unlocks' => ['d2', 'd3'], 'blocked_by' => ['d0']],
        ]];
        $drift = ['findings' => []];

        $result = (new AtlasExternalBrainMaturityGapBatchPlanner())->plan($gapIndex, $drift);

        $this->assertSame(2, $result['batch'][0]['unlock_count']);
        $this->assertSame(['d0'], $result['batch'][0]['dependency_blockers']);
        $this->assertArrayHasKey('compound_leverage', $result['batch'][0]);
        $this->assertSame(0.7, $result['batch'][0]['compound_leverage']);
    }

    public function test_item_that_unblocks_others_ranks_before_isolated_medium_impact_repair_in_same_category(): void
    {
        $emptyGaps = ['gaps' => []];

        $drift = [
            'findings' => [
                ['area_id' => 'isolated', 'drift_type' => AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MISSING_OWNER, 'impact' => 'medium', 'repair_action' => 'assign_owner'],
                ['area_id' => 'unblocker', 'drift_type' => AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MISSING_OWNER, 'impact' => 'medium', 'repair_action' => 'assign_owner', 'unlocks' => ['x', 'y']],
            ],
        ];

        $result = (new AtlasExternalBrainMaturityGapBatchPlanner())->plan($emptyGaps, $drift);

        $this->assertSame('unblocker', $result['batch'][0]['id']);
        $this->assertSame('isolated', $result['batch'][1]['id']);
    }

    public function test_empty_inputs_yield_empty_batch(): void
    {
        $result = (new AtlasExternalBrainMaturityGapBatchPlanner())->plan(['gaps' => []], ['findings' => []]);

        $this->assertSame([], $result['batch']);
    }
}
