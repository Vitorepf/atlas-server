<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEvolutionLeveragePortfolioPlanner;
use Tests\TestCase;

final class AtlasExternalBrainEvolutionLeveragePortfolioPlannerTest extends TestCase
{
    private function planner(): AtlasExternalBrainEvolutionLeveragePortfolioPlanner
    {
        return new AtlasExternalBrainEvolutionLeveragePortfolioPlanner;
    }

    private function candidate(string $id, string $layer, string $family, array $overrides = []): array
    {
        return array_merge([
            'task_id' => $id,
            'layer' => $layer,
            'template_family' => $family,
            'structural_leverage' => 0.5,
            'implementable' => true,
            'recent_commits' => 1,
            'blocker_impact' => false,
        ], $overrides);
    }

    public function test_tunnel_risk_detected_when_one_layer_and_family_dominates(): void
    {
        $candidates = [
            $this->candidate('a', 'bug_hunt', 'templateA'),
            $this->candidate('b', 'bug_hunt', 'templateA'),
            $this->candidate('c', 'bug_hunt', 'templateA'),
            $this->candidate('d', 'bug_hunt', 'templateA'),
            $this->candidate('e', 'simplification', 'templateB'),
        ];

        $r = $this->planner()->plan($candidates);

        self::assertTrue($r['tunnel_risk']);
        self::assertSame('bug_hunt|templateA', $r['dominant_tunnel_key']);
    }

    public function test_starved_high_blocker_impact_layer_with_zero_commits_force_includes_best_candidate(): void
    {
        $candidates = [
            $this->candidate('starved-1', 'control_plane', 'x', ['recent_commits' => 0, 'blocker_impact' => true, 'structural_leverage' => 0.9]),
            $this->candidate('starved-2', 'control_plane', 'x', ['recent_commits' => 0, 'blocker_impact' => true, 'structural_leverage' => 0.2]),
            $this->candidate('other', 'bug_hunt', 'y'),
        ];

        $r = $this->planner()->plan($candidates);

        $selectedIds = array_column($r['batch'], 'task_id');
        self::assertContains('starved-1', $selectedIds);
        self::assertContains('coverage_gap:control_plane', $r['reasons']);
    }

    public function test_diversity_round_selects_one_high_leverage_candidate_per_layer_before_fill(): void
    {
        $candidates = [
            $this->candidate('bh-low', 'bug_hunt', 'f1', ['structural_leverage' => 0.2]),
            $this->candidate('bh-high', 'bug_hunt', 'f1', ['structural_leverage' => 0.9]),
            $this->candidate('sl-low', 'simplification', 'f2', ['structural_leverage' => 0.3]),
            $this->candidate('sl-high', 'simplification', 'f2', ['structural_leverage' => 0.8]),
        ];

        $r = $this->planner()->plan($candidates, ['max_batch' => 2]);

        $selectedIds = array_column($r['batch'], 'task_id');
        self::assertContains('bh-high', $selectedIds);
        self::assertContains('sl-high', $selectedIds);
        self::assertNotContains('bh-low', $selectedIds);
        self::assertNotContains('sl-low', $selectedIds);
        self::assertSame(2, $r['distinct_layer_count']);
    }

    public function test_tunnel_family_candidates_withheld_unless_high_leverage_bypass(): void
    {
        $candidates = array_merge(
            [
                $this->candidate('t1', 'bug_hunt', 'templateA', ['structural_leverage' => 0.4]),
                $this->candidate('t2', 'bug_hunt', 'templateA', ['structural_leverage' => 0.4]),
                $this->candidate('t3', 'bug_hunt', 'templateA', ['structural_leverage' => 0.4]),
                $this->candidate('t4', 'bug_hunt', 'templateA', ['structural_leverage' => 0.4]),
                $this->candidate('t-bypass', 'bug_hunt', 'templateA', ['structural_leverage' => 0.95]),
            ],
            [$this->candidate('other-layer', 'simplification', 'f2')],
        );

        $r = $this->planner()->plan($candidates, ['max_batch' => 10]);

        self::assertTrue($r['tunnel_risk']);
        $selectedIds = array_column($r['batch'], 'task_id');
        self::assertContains('t-bypass', $selectedIds);
        self::assertNotEmpty($r['withheld_low_leverage_duplicates']);
    }
}
