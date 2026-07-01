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

    // ── AC: max_wave_size / risk_budget split a heavy layer into reversible waves ──

    public function test_max_wave_size_splits_a_layer_into_multiple_deterministic_waves(): void
    {
        $plan = (new AtlasSelfConstructionLayerConsolidationWavePlanner)->plan([
            ['id' => 'a', 'layer' => 'domain', 'dependency_risk' => 1, 'consumer_count' => 1, 'proof_ready' => true],
            ['id' => 'b', 'layer' => 'domain', 'dependency_risk' => 1, 'consumer_count' => 2, 'proof_ready' => true],
            ['id' => 'c', 'layer' => 'domain', 'dependency_risk' => 1, 'consumer_count' => 3, 'proof_ready' => true],
        ], ['max_wave_size' => 2]);

        $domainWaves = array_values(array_filter($plan['waves'], static fn (array $w): bool => $w['layer'] === 'domain'));
        $this->assertCount(2, $domainWaves);
        $this->assertSame(['a', 'b'], $domainWaves[0]['candidate_ids']);
        $this->assertSame(['c'], $domainWaves[1]['candidate_ids']);
        $this->assertSame('wave_domain_1', $domainWaves[0]['wave_id']);
        $this->assertSame('wave_domain_2', $domainWaves[1]['wave_id']);
    }

    public function test_risk_budget_splits_a_layer_when_cumulative_risk_would_exceed_it(): void
    {
        $plan = (new AtlasSelfConstructionLayerConsolidationWavePlanner)->plan([
            ['id' => 'a', 'layer' => 'domain', 'dependency_risk' => 3, 'consumer_count' => 1, 'proof_ready' => true],
            ['id' => 'b', 'layer' => 'domain', 'dependency_risk' => 3, 'consumer_count' => 2, 'proof_ready' => true],
            ['id' => 'c', 'layer' => 'domain', 'dependency_risk' => 3, 'consumer_count' => 3, 'proof_ready' => true],
        ], ['risk_budget' => 5]);

        $this->assertCount(3, $plan['waves']);
        $this->assertSame(['a'], $plan['waves'][0]['candidate_ids']);
        $this->assertSame(['b'], $plan['waves'][1]['candidate_ids']);
        $this->assertSame(['c'], $plan['waves'][2]['candidate_ids']);
    }

    public function test_single_candidate_over_risk_budget_is_never_dropped(): void
    {
        $plan = (new AtlasSelfConstructionLayerConsolidationWavePlanner)->plan([
            ['id' => 'huge', 'layer' => 'domain', 'dependency_risk' => 100, 'consumer_count' => 1, 'proof_ready' => true],
            ['id' => 'small', 'layer' => 'domain', 'dependency_risk' => 1, 'consumer_count' => 2, 'proof_ready' => true],
        ], ['risk_budget' => 5]);

        $allIds = array_merge(...array_column($plan['waves'], 'candidate_ids'));
        $this->assertContains('huge', $allIds);
        $this->assertContains('small', $allIds);
        // huge alone exceeds the budget, so it must occupy its own wave, never merged with small.
        $hugeWave = array_values(array_filter($plan['waves'], static fn (array $w): bool => in_array('huge', $w['candidate_ids'], true)))[0];
        $this->assertNotContains('small', $hugeWave['candidate_ids']);
    }

    public function test_blocked_candidates_ride_on_the_last_subwave_when_layer_is_split(): void
    {
        $plan = (new AtlasSelfConstructionLayerConsolidationWavePlanner)->plan([
            ['id' => 'a', 'layer' => 'domain', 'dependency_risk' => 1, 'consumer_count' => 1, 'proof_ready' => true],
            ['id' => 'b', 'layer' => 'domain', 'dependency_risk' => 1, 'consumer_count' => 2, 'proof_ready' => true],
            ['id' => 'c', 'layer' => 'domain', 'dependency_risk' => 1, 'proof_ready' => false, 'missing_proof' => 'gate_evidence'],
        ], ['max_wave_size' => 1]);

        $domainWaves = array_values(array_filter($plan['waves'], static fn (array $w): bool => $w['layer'] === 'domain'));
        $this->assertCount(2, $domainWaves);
        $this->assertSame([], $domainWaves[0]['blocked_candidates']);
        $this->assertSame([['id' => 'c', 'reason' => 'gate_evidence']], $domainWaves[1]['blocked_candidates']);
        $this->assertSame('gate_evidence', $domainWaves[1]['next_required_proof']);
    }

    public function test_no_options_preserves_the_unsplit_one_wave_per_layer_behavior(): void
    {
        $plan = (new AtlasSelfConstructionLayerConsolidationWavePlanner)->plan([
            ['id' => 'a', 'layer' => 'domain', 'dependency_risk' => 1, 'consumer_count' => 1, 'proof_ready' => true],
            ['id' => 'b', 'layer' => 'domain', 'dependency_risk' => 1, 'consumer_count' => 2, 'proof_ready' => true],
        ]);

        $this->assertCount(1, $plan['waves']);
        $this->assertSame('wave_domain', $plan['waves'][0]['wave_id']);
        $this->assertSame(['a', 'b'], $plan['waves'][0]['candidate_ids']);
    }
}
