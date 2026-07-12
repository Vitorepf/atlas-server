<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Governance;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainControlPlaneSnapshot;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainStructuralLeverageComparator;
use PHPUnit\Framework\TestCase;

final class GovernanceFloorElev09SentinelTest extends TestCase
{
    public function test_external_brain_control_plane_maturity_and_freshness_floors_are_frozen(): void
    {
        $snapshot = new AtlasExternalBrainControlPlaneSnapshot;
        $base = [
            'queue_health' => ['status' => 'healthy'],
            'audit_result' => ['verdict' => 'pass'],
            'ledger_summary' => ['total' => 10, 'success_rate' => 0.70],
        ];

        $advanced = $snapshot->snapshot($base + ['rubric_scores' => ['overall' => 0.70]]);
        $functional = $snapshot->snapshot($base + ['rubric_scores' => ['overall' => 0.69]]);
        $staleLearning = $snapshot->snapshot([
            'queue_health' => ['status' => 'healthy'],
            'audit_result' => ['verdict' => 'pass'],
            'ledger_summary' => ['total' => 10, 'success_rate' => 0.69],
            'rubric_scores' => ['overall' => 0.70],
        ]);

        $this->assertSame('advanced', $advanced['maturity_band']);
        $this->assertSame('functional', $functional['maturity_band']);
        $this->assertSame('fresh', $advanced['learning_freshness']['status']);
        $this->assertSame('stale', $staleLearning['learning_freshness']['status']);
    }

    public function test_external_brain_structural_leverage_weights_are_frozen(): void
    {
        $ranked = (new AtlasExternalBrainStructuralLeverageComparator)->rank([
            [
                'task_id' => 'elev-09-sentinel',
                'leverage_category' => 'closed_loop_learning',
                'expected_downstream_gain' => 0.0,
                'dependency_reach' => 1.0,
                'recurrence' => 1.0,
                'outcome_gap' => 1.0,
                'simplification_opportunity' => 1.0,
                'verification_strength' => 1.0,
                'blast_radius' => 1.0,
                'outcome_freshness' => 'fresh',
                'outcome_evidence_present' => true,
                'queue_depth' => 0,
                'test_count' => 0,
                'wrapper_count' => 0,
            ],
        ]);

        $this->assertSame(1.0, $ranked['top_candidate']['structural_score']);
        $this->assertSame(1.0, $ranked['top_candidate']['leverage_score']);
    }
}
