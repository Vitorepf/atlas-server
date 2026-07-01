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

    public function test_batch_never_exceeds_max_batch_cap(): void
    {
        $candidates = [];
        $layers = ['bug_hunt', 'task_fabric', 'outcome_learning', 'simplification', 'model_amplifier', 'research_to_task', 'control_plane'];
        foreach ($layers as $i => $layer) {
            for ($j = 0; $j < 3; $j++) {
                $candidates[] = $this->candidate("{$layer}-{$j}", $layer, 0.9 - $i * 0.05 - $j * 0.01, ['template_family' => "fam-{$layer}-{$j}"]);
            }
        }

        $result = (new AtlasExternalBrainEvolutionLeveragePortfolioPlanner)->plan($candidates, ['max_batch' => 5]);

        $this->assertLessThanOrEqual(5, count($result['batch']));
    }

    public function test_coverage_by_layer_alias_mirrors_layer_coverage(): void
    {
        $result = (new AtlasExternalBrainEvolutionLeveragePortfolioPlanner)->plan([
            $this->candidate('a', 'bug_hunt', 0.9),
        ]);

        $this->assertSame($result['layer_coverage'], $result['coverage_by_layer']);
        $this->assertTrue($result['coverage_by_layer']['bug_hunt']);
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

    public function test_high_leverage_duplicate_without_bypass_reason_is_still_withheld(): void
    {
        $candidates = [];
        for ($i = 0; $i < 7; $i++) {
            $candidates[] = $this->candidate("bug{$i}", 'bug_hunt', 0.95, ['template_family' => 'wrapper_farm']);
        }
        $candidates[] = $this->candidate('other1', 'simplification', 0.5);

        $result = (new AtlasExternalBrainEvolutionLeveragePortfolioPlanner)->plan($candidates, ['max_batch' => 10]);

        $this->assertTrue($result['tunnel_risk']);
        $this->assertNotEmpty($result['withheld_low_leverage_duplicates']);

        $batchIds = array_column($result['batch'], 'task_id');
        $bugHuntCount = count(array_filter($batchIds, static fn (string $id): bool => str_starts_with($id, 'bug')));
        $this->assertSame(1, $bugHuntCount, 'without bypass_reason, high leverage alone must not bypass diversity — only the diversity-round pick survives');
    }

    public function test_high_leverage_duplicate_with_explicit_bypass_reason_is_included(): void
    {
        $candidates = [];
        $candidates[] = $this->candidate('bug_diversity_pick', 'bug_hunt', 0.99, ['template_family' => 'wrapper_farm']);
        for ($i = 0; $i < 6; $i++) {
            $candidates[] = $this->candidate("bug{$i}", 'bug_hunt', 0.3, ['template_family' => 'wrapper_farm']);
        }
        $candidates[] = $this->candidate('bug_highlev', 'bug_hunt', 0.95, [
            'template_family' => 'wrapper_farm',
            'bypass_reason' => 'critical_security_regression',
        ]);
        $candidates[] = $this->candidate('other1', 'simplification', 0.5);

        $result = (new AtlasExternalBrainEvolutionLeveragePortfolioPlanner)->plan($candidates, ['max_batch' => 10]);

        $batchIds = array_column($result['batch'], 'task_id');
        $this->assertContains('bug_highlev', $batchIds, 'a candidate with an explicit bypass_reason must survive tunnel withholding even outside the diversity-round pick');
        $this->assertNotContains('bug_highlev', $result['withheld_low_leverage_duplicates']);
    }

    public function test_selected_portfolio_includes_layer_coverage_field(): void
    {
        $candidates = [
            $this->candidate('a', 'bug_hunt', 0.9),
            $this->candidate('b', 'task_fabric', 0.8),
        ];

        $result = (new AtlasExternalBrainEvolutionLeveragePortfolioPlanner)->plan($candidates);

        $this->assertArrayHasKey('layer_coverage', $result);
        $this->assertTrue($result['layer_coverage']['bug_hunt']);
        $this->assertTrue($result['layer_coverage']['task_fabric']);
        $this->assertFalse($result['layer_coverage']['control_plane']);
    }

    public function test_empty_candidates_yields_empty_batch(): void
    {
        $result = (new AtlasExternalBrainEvolutionLeveragePortfolioPlanner)->plan([]);

        $this->assertSame([], $result['batch']);
        $this->assertFalse($result['tunnel_risk']);
        $this->assertSame(0, $result['distinct_layer_count']);
    }
}
