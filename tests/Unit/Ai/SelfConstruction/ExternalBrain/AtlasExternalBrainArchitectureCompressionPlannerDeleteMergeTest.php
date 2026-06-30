<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainArchitectureCompressionPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainArchitectureCompressionPlannerDeleteMergeTest extends TestCase
{
    private function planner(): AtlasExternalBrainArchitectureCompressionPlanner
    {
        return new AtlasExternalBrainArchitectureCompressionPlanner;
    }

    public function test_duplicate_organs_rank_merge_delete_moves_above_new_organ_proposals(): void
    {
        $inventory = [
            'organs' => [
                ['id' => 'organ-a', 'capability_labels' => ['dup_label'], 'files' => ['app/A.php'], 'line_count' => 50],
                ['id' => 'organ-b', 'capability_labels' => ['dup_label'], 'files' => ['app/B.php'], 'line_count' => 50],
            ],
        ];
        $additiveProposals = [
            ['proposal_id' => 'new-organ-1', 'objective' => 'Add new capability X'],
        ];

        $result = $this->planner()->rankWithAdditiveProposals($inventory, $additiveProposals);

        $this->assertGreaterThan(0, $result['compression_candidates_count']);
        $this->assertSame(1, $result['additive_proposal_count']);

        $ranked = $result['ranked'];
        $additiveIndex = null;
        $mergeIndex = null;
        foreach ($ranked as $i => $item) {
            if (($item['action'] ?? '') === 'merge') {
                $mergeIndex = $i;
            }
            if ($item['is_additive_proposal'] ?? false) {
                $additiveIndex = $i;
            }
        }

        $this->assertNotNull($mergeIndex);
        $this->assertNotNull($additiveIndex);
        $this->assertLessThan($additiveIndex, $mergeIndex);
    }

    public function test_real_missing_capability_with_no_duplication_still_permits_additive_proposal_after_compression(): void
    {
        $inventory = [
            'organs' => [
                ['id' => 'organ-solo', 'capability_labels' => ['unique_label'], 'files' => ['app/Solo.php'], 'line_count' => 10],
            ],
        ];
        $additiveProposals = [
            ['proposal_id' => 'new-missing-capability', 'objective' => 'Add missing real capability Y'],
        ];

        $result = $this->planner()->rankWithAdditiveProposals($inventory, $additiveProposals);

        $this->assertSame(0, $result['compression_candidates_count']);
        $this->assertSame(1, $result['additive_proposal_count']);
        $this->assertTrue($result['ranked'][0]['is_additive_proposal']);
        $this->assertSame('new-missing-capability', $result['ranked'][0]['proposal_id']);
    }

    // ── worker-feed preservation ──────────────────────────────────────────────

    public function test_worker_feed_preserved_true_only_when_replacement_path_explicit(): void
    {
        $inventory = [
            'organs' => [
                ['id' => 'a', 'capability_labels' => ['dup'], 'files' => ['app/A.php'], 'line_count' => 50, 'feeds_active_workers' => true, 'replacement_claimable_path' => true],
                ['id' => 'b', 'capability_labels' => ['dup'], 'files' => ['app/B.php'], 'line_count' => 50, 'feeds_active_workers' => true, 'replacement_claimable_path' => true],
            ],
        ];

        $result = $this->planner()->plan($inventory);
        $merge = $result['candidates'][0];

        $this->assertSame('merge', $merge['action']);
        $this->assertTrue($merge['worker_feed_preserved']);
    }

    public function test_delete_candidate_without_worker_feed_facts_is_preserved_by_default(): void
    {
        $inventory = [
            'organs' => [
                ['id' => 'stale-a', 'stale_scaffold_marker' => true, 'replacement_owner' => 'team-x', 'test_coverage' => true, 'files' => ['app/StaleA.php'], 'line_count' => 30],
            ],
        ];

        $result = $this->planner()->plan($inventory);
        $delete = $result['candidates'][0];

        $this->assertSame('delete', $delete['action']);
        $this->assertTrue($delete['worker_feed_preserved']);
    }

    public function test_compression_plan_rejects_delete_that_would_strand_active_workers_at_low_floor(): void
    {
        $inventory = [
            'worker_floor_low' => true,
            'organs' => [
                [
                    'id' => 'stale-feeder',
                    'stale_scaffold_marker' => true,
                    'replacement_owner' => 'team-x',
                    'test_coverage' => true,
                    'files' => ['app/StaleFeeder.php'],
                    'line_count' => 40,
                    'feeds_active_workers' => true,
                    'replacement_claimable_path' => false,
                ],
            ],
        ];

        $result = $this->planner()->plan($inventory);
        $candidate = $result['candidates'][0];

        $this->assertSame('keep', $candidate['action']);
        $this->assertSame('worker_feed_capacity_protected', $candidate['reason']);
        $this->assertFalse($candidate['worker_feed_preserved']);
    }

    public function test_compression_plan_allows_delete_at_low_floor_when_replacement_path_present(): void
    {
        $inventory = [
            'worker_floor_low' => true,
            'organs' => [
                [
                    'id' => 'stale-feeder',
                    'stale_scaffold_marker' => true,
                    'replacement_owner' => 'team-x',
                    'test_coverage' => true,
                    'files' => ['app/StaleFeeder.php'],
                    'line_count' => 40,
                    'feeds_active_workers' => true,
                    'replacement_claimable_path' => true,
                ],
            ],
        ];

        $result = $this->planner()->plan($inventory);
        $candidate = $result['candidates'][0];

        $this->assertSame('delete', $candidate['action']);
        $this->assertTrue($candidate['worker_feed_preserved']);
    }

    public function test_compression_plan_rejects_merge_that_would_strand_active_workers_at_low_floor(): void
    {
        $inventory = [
            'worker_floor_low' => true,
            'organs' => [
                ['id' => 'a', 'capability_labels' => ['dup'], 'files' => ['app/A.php'], 'line_count' => 50, 'feeds_active_workers' => true, 'replacement_claimable_path' => false],
                ['id' => 'b', 'capability_labels' => ['dup'], 'files' => ['app/B.php'], 'line_count' => 50],
            ],
        ];

        $result = $this->planner()->plan($inventory);
        $candidate = $result['candidates'][0];

        $this->assertSame('keep', $candidate['action']);
        $this->assertSame('worker_feed_capacity_protected', $candidate['reason']);
    }
}
