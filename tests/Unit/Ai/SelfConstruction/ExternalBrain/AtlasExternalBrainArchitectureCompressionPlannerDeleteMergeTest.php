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
}
