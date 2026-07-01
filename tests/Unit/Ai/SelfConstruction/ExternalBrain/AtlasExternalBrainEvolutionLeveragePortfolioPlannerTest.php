<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEvolutionLeveragePortfolioPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainEvolutionLeveragePortfolioPlannerTest extends TestCase
{
    private function candidate(string $id, string $layer, float $leverage, array $overrides = []): array
    {
        return array_merge([
            'task_id' => $id,
            'layer' => $layer,
            'template_family' => 'default',
            'structural_leverage' => $leverage,
            'recent_commits' => 5,
            'blocker_impact' => 0.0,
            'implementable' => true,
        ], $overrides);
    }

    public function test_batch_spans_at_least_four_distinct_layers_and_ranks_leverage(): void
    {
        $candidates = [
            $this->candidate('a', 'bug_hunt', 0.9),
            $this->candidate('b', 'task_fabric', 0.8),
            $this->candidate('c', 'outcome_learning', 0.7),
            $this->candidate('d', 'simplification', 0.6),
            $this->candidate('e', 'model_amplifier', 0.5),
            $this->candidate('f', 'research_to_task', 0.4),
            $this->candidate('g', 'control_plane', 0.3),
        ];

        $result = (new AtlasExternalBrainEvolutionLeveragePortfolioPlanner)->plan($candidates);

        $this->assertGreaterThanOrEqual(4, $result['distinct_layer_count']);
        $this->assertFalse($result['tunnel_risk']);
    }

    public function test_dominant_layer_and_template_family_triggers_tunnel_risk_and_withholds_duplicates(): void
    {
        $candidates = [];
        for ($i = 0; $i < 8; $i++) {
            $candidates[] = $this->candidate("bug{$i}", 'bug_hunt', 0.2, ['template_family' => 'wrapper_farm']);
        }
        $candidates[] = $this->candidate('other1', 'simplification', 0.9);
        $candidates[] = $this->candidate('other2', 'control_plane', 0.85);

        $result = (new AtlasExternalBrainEvolutionLeveragePortfolioPlanner)->plan($candidates, ['max_batch' => 10]);

        $this->assertTrue($result['tunnel_risk']);
        $this->assertNotEmpty($result['withheld_low_leverage_duplicates']);

        $batchIds = array_column($result['batch'], 'task_id');
        $bugHuntCount = count(array_filter($batchIds, static fn (string $id): bool => str_starts_with($id, 'bug')));
        $this->assertLessThan(8, $bugHuntCount, 'low-leverage tunnel duplicates must be withheld');
    }

    public function test_zero_commit_high_blocker_layer_emits_coverage_gap_and_is_included(): void
    {
        $candidates = [
            $this->candidate('starved', 'model_amplifier', 0.1, ['recent_commits' => 0, 'blocker_impact' => true]),
            $this->candidate('a', 'bug_hunt', 0.9),
            $this->candidate('b', 'task_fabric', 0.8),
        ];

        $result = (new AtlasExternalBrainEvolutionLeveragePortfolioPlanner)->plan($candidates);

        $this->assertContains('coverage_gap:model_amplifier', $result['reasons']);
        $batchIds = array_column($result['batch'], 'task_id');
        $this->assertContains('starved', $batchIds);
    }

    public function test_non_implementable_starved_candidate_is_not_force_included(): void
    {
        $candidates = [
            $this->candidate('starved', 'model_amplifier', 0.1, ['recent_commits' => 0, 'blocker_impact' => true, 'implementable' => false]),
            $this->candidate('a', 'bug_hunt', 0.9),
        ];

        $result = (new AtlasExternalBrainEvolutionLeveragePortfolioPlanner)->plan($candidates);

        $batchIds = array_column($result['batch'], 'task_id');
        $this->assertNotContains('starved', $batchIds);
    }

    public function test_empty_candidates_yields_empty_batch(): void
    {
        $result = (new AtlasExternalBrainEvolutionLeveragePortfolioPlanner)->plan([]);

        $this->assertSame([], $result['batch']);
        $this->assertFalse($result['tunnel_risk']);
        $this->assertSame(0, $result['distinct_layer_count']);
    }
}
