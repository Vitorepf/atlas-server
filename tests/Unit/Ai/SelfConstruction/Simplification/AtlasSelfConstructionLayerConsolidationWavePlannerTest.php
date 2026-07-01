<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionLayerConsolidationWavePlanner;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionLayerConsolidationWavePlannerTest extends TestCase
{
    public function test_high_proof_low_risk_tasks_are_scheduled_before_broad_risky_merges(): void
    {
        $plan = (new AtlasSelfConstructionLayerConsolidationWavePlanner)->plan([
            ['id' => 'risky-merge', 'layer' => 'domain', 'dependency_risk' => 9, 'consumer_count' => 40, 'proof_ready' => true],
            ['id' => 'safe-consolidation', 'layer' => 'domain', 'dependency_risk' => 1, 'consumer_count' => 2, 'proof_ready' => true],
        ]);

        $domainWave = $plan['waves'][0];
        $this->assertSame('domain', $domainWave['layer']);
        $this->assertSame(['safe-consolidation', 'risky-merge'], $domainWave['candidate_ids']);
    }

    public function test_every_wave_carries_required_fields(): void
    {
        $plan = (new AtlasSelfConstructionLayerConsolidationWavePlanner)->plan([
            ['id' => 'a', 'layer' => 'application', 'dependency_risk' => 2, 'consumer_count' => 1, 'proof_ready' => true],
            ['id' => 'b', 'layer' => 'application', 'dependency_risk' => 3, 'consumer_count' => 5, 'proof_ready' => false, 'missing_proof' => 'consumer_replay_evidence'],
        ]);

        $wave = $plan['waves'][0];
        $this->assertArrayHasKey('wave_id', $wave);
        $this->assertArrayHasKey('layer', $wave);
        $this->assertArrayHasKey('candidate_ids', $wave);
        $this->assertArrayHasKey('blocked_candidates', $wave);
        $this->assertArrayHasKey('next_required_proof', $wave);

        $this->assertSame(['a'], $wave['candidate_ids']);
        $this->assertSame([['id' => 'b', 'reason' => 'consumer_replay_evidence']], $wave['blocked_candidates']);
        $this->assertSame('consumer_replay_evidence', $wave['next_required_proof']);
    }

    public function test_layers_are_ordered_canonically(): void
    {
        $plan = (new AtlasSelfConstructionLayerConsolidationWavePlanner)->plan([
            ['id' => 'p', 'layer' => 'presentation', 'dependency_risk' => 1, 'consumer_count' => 1, 'proof_ready' => true],
            ['id' => 'd', 'layer' => 'domain', 'dependency_risk' => 1, 'consumer_count' => 1, 'proof_ready' => true],
            ['id' => 'i', 'layer' => 'infrastructure', 'dependency_risk' => 1, 'consumer_count' => 1, 'proof_ready' => true],
            ['id' => 'a', 'layer' => 'application', 'dependency_risk' => 1, 'consumer_count' => 1, 'proof_ready' => true],
        ]);

        $this->assertSame(
            ['domain', 'application', 'infrastructure', 'presentation'],
            array_column($plan['waves'], 'layer'),
        );
    }

    public function test_unknown_layer_sorts_after_canonical_layers(): void
    {
        $plan = (new AtlasSelfConstructionLayerConsolidationWavePlanner)->plan([
            ['id' => 'x', 'layer' => 'mystery', 'dependency_risk' => 1, 'consumer_count' => 1, 'proof_ready' => true],
            ['id' => 'd', 'layer' => 'domain', 'dependency_risk' => 1, 'consumer_count' => 1, 'proof_ready' => true],
        ]);

        $this->assertSame(['domain', 'mystery'], array_column($plan['waves'], 'layer'));
    }

    public function test_wave_with_no_blocked_candidates_has_null_next_required_proof(): void
    {
        $plan = (new AtlasSelfConstructionLayerConsolidationWavePlanner)->plan([
            ['id' => 'a', 'layer' => 'domain', 'dependency_risk' => 1, 'consumer_count' => 1, 'proof_ready' => true],
        ]);

        $this->assertNull($plan['waves'][0]['next_required_proof']);
        $this->assertSame([], $plan['waves'][0]['blocked_candidates']);
    }
}
