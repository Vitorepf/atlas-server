<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPortfolioBalancer;
use Tests\TestCase;

final class AtlasExternalBrainPortfolioBalancerTest extends TestCase
{
    private function balancer(): AtlasExternalBrainPortfolioBalancer
    {
        return new AtlasExternalBrainPortfolioBalancer;
    }

    /** @param list<string> $categories */
    private function candidates(array $categories, float $score = 0.5): array
    {
        return array_map(
            static fn (string $cat, int $i): array => [
                'label'       => $cat.'-'.$i,
                'category'    => $cat,
                'risk_tier'   => 'low',
                'final_score' => $score,
            ],
            $categories,
            array_keys($categories),
        );
    }

    public function test_schema_constant(): void
    {
        $this->assertSame(
            'atlas.external_brain.portfolio_balancer.v1',
            AtlasExternalBrainPortfolioBalancer::SCHEMA,
        );
    }

    public function test_diverse_wave_passes_as_balanced(): void
    {
        $cats = [
            'bug_fix', 'architecture_unlock', 'test_gate',
            'runtime_continuity', 'task_quality_repair', 'docs_sync', 'learning_loop',
        ];
        $result = $this->balancer()->balance($this->candidates($cats));

        $this->assertSame('balanced', $result['status']);
        $this->assertTrue($result['passed']);
        $this->assertSame([], $result['deficits']);
        $this->assertSame([], $result['surpluses']);
        $this->assertSame(7, $result['total_out']);
    }

    public function test_bug_only_set_is_rebalanced_with_clear_deficits(): void
    {
        $candidates = $this->candidates(array_fill(0, 10, 'bug_fix'));
        $result = $this->balancer()->balance($candidates);

        $this->assertSame('rebalanced', $result['status']);
        $this->assertFalse($result['passed']);

        // All non-bug_fix categories are deficits.
        $deficitCategories = array_column($result['deficits'], 'category');
        $this->assertContains('architecture_unlock', $deficitCategories);
        $this->assertContains('test_gate',           $deficitCategories);
        $this->assertContains('runtime_continuity',  $deficitCategories);
        $this->assertNotContains('bug_fix', $deficitCategories);

        // bug_fix is in surplus.
        $surplusCategories = array_column($result['surpluses'], 'category');
        $this->assertContains('bug_fix', $surplusCategories);
    }

    public function test_wrapper_only_set_is_rebalanced_with_clear_deficits(): void
    {
        // architecture_unlock used as proxy for wrapper-only (all same category).
        $candidates = $this->candidates(array_fill(0, 10, 'architecture_unlock'));
        $result = $this->balancer()->balance($candidates);

        $this->assertSame('rebalanced', $result['status']);
        $this->assertFalse($result['passed']);

        $surplusCategories = array_column($result['surpluses'], 'category');
        $this->assertContains('architecture_unlock', $surplusCategories);

        $deficitCategories = array_column($result['deficits'], 'category');
        $this->assertContains('bug_fix',            $deficitCategories);
        $this->assertContains('test_gate',           $deficitCategories);
        $this->assertNotContains('architecture_unlock', $deficitCategories);
    }

    public function test_high_leverage_candidates_preserved_during_surplus_trim(): void
    {
        // 10 bug_fix candidates with varying scores; max_allowed = ceil(10×0.5) = 5.
        $candidates = [
            ['label' => 'low-1',  'category' => 'bug_fix', 'risk_tier' => 'low', 'final_score' => 0.1],
            ['label' => 'low-2',  'category' => 'bug_fix', 'risk_tier' => 'low', 'final_score' => 0.2],
            ['label' => 'low-3',  'category' => 'bug_fix', 'risk_tier' => 'low', 'final_score' => 0.3],
            ['label' => 'low-4',  'category' => 'bug_fix', 'risk_tier' => 'low', 'final_score' => 0.4],
            ['label' => 'low-5',  'category' => 'bug_fix', 'risk_tier' => 'low', 'final_score' => 0.5],
            ['label' => 'high-1', 'category' => 'bug_fix', 'risk_tier' => 'low', 'final_score' => 0.9],
            ['label' => 'high-2', 'category' => 'bug_fix', 'risk_tier' => 'low', 'final_score' => 0.8],
            ['label' => 'high-3', 'category' => 'bug_fix', 'risk_tier' => 'low', 'final_score' => 0.7],
            ['label' => 'high-4', 'category' => 'bug_fix', 'risk_tier' => 'low', 'final_score' => 0.6],
            ['label' => 'high-5', 'category' => 'bug_fix', 'risk_tier' => 'low', 'final_score' => 0.55],
        ];

        $result = $this->balancer()->balance($candidates);

        // After trim, no more than 5 bug_fix remain (max_allowed = ceil(10×0.5)=5).
        $remaining = array_filter($result['candidates'], static fn (array $c): bool => $c['category'] === 'bug_fix');
        $this->assertLessThanOrEqual(5, count($remaining));

        // All kept candidates have score >= 0.55 (top 5).
        foreach ($remaining as $c) {
            $this->assertGreaterThanOrEqual(0.55, $c['final_score']);
        }

        // Low-score ones dropped.
        $labels = array_column($result['candidates'], 'label');
        $this->assertNotContains('low-1', $labels);
        $this->assertNotContains('low-2', $labels);
    }

    public function test_small_wave_below_threshold_skips_minimum_enforcement(): void
    {
        // 5 bug_fix — below MIN_WAVE_SIZE=7, so no deficit enforcement.
        $candidates = $this->candidates(array_fill(0, 5, 'bug_fix'));
        $result = $this->balancer()->balance($candidates);

        // No deficits (minimum not enforced), but still surplus if >50%.
        $this->assertSame([], $result['deficits']);
        // 5 of 5 = 100% > 50% → surplus.
        $this->assertNotEmpty($result['surpluses']);
        $this->assertSame('rebalanced', $result['status']);
    }

    public function test_empty_candidates_balanced(): void
    {
        $result = $this->balancer()->balance([]);

        $this->assertSame('balanced', $result['status']);
        $this->assertTrue($result['passed']);
        $this->assertSame(0, $result['total_in']);
        $this->assertSame(0, $result['total_out']);
        $this->assertSame([], $result['deficits']);
        $this->assertSame([], $result['surpluses']);
    }

    public function test_deficit_only_when_no_surplus(): void
    {
        // 7 candidates all in one category, but let's try: 7 entries with one category missing
        // → deficit but no surplus if none exceeds 50%.
        $cats = [
            'bug_fix', 'bug_fix', 'architecture_unlock',
            'test_gate', 'runtime_continuity', 'task_quality_repair', 'docs_sync',
            // learning_loop missing
        ];
        $result = $this->balancer()->balance($this->candidates($cats));

        // bug_fix count=2 out of 7 = 28.5%, max_allowed=ceil(7×0.5)=4 → no surplus.
        $this->assertSame([], $result['surpluses']);

        // learning_loop is missing (high priority) + docs_sync/runtime_continuity filler present
        // → upgrade marks as unbalanced (not merely deficit).
        $deficitCategories = array_column($result['deficits'], 'category');
        $this->assertContains('learning_loop', $deficitCategories);
        $this->assertSame('unbalanced', $result['status']);
        $this->assertFalse($result['passed']);
    }

    public function test_output_has_canonical_keys(): void
    {
        $result = $this->balancer()->balance($this->candidates(['bug_fix']));

        foreach (['schema', 'status', 'passed', 'total_in', 'total_out', 'deficits', 'surpluses', 'category_counts', 'risk_tier_counts', 'candidates'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame(AtlasExternalBrainPortfolioBalancer::SCHEMA, $result['schema']);
    }

    public function test_category_counts_reflects_input(): void
    {
        $candidates = [
            ['label' => 'a', 'category' => 'bug_fix',             'risk_tier' => 'low',    'final_score' => 0.5],
            ['label' => 'b', 'category' => 'architecture_unlock',  'risk_tier' => 'medium', 'final_score' => 0.5],
            ['label' => 'c', 'category' => 'bug_fix',             'risk_tier' => 'high',   'final_score' => 0.5],
        ];
        $result = $this->balancer()->balance($candidates);

        $this->assertSame(2, $result['category_counts']['bug_fix']);
        $this->assertSame(1, $result['category_counts']['architecture_unlock']);
        $this->assertSame(0, $result['category_counts']['test_gate']);
        $this->assertSame(1, $result['risk_tier_counts']['low']);
        $this->assertSame(1, $result['risk_tier_counts']['medium']);
        $this->assertSame(1, $result['risk_tier_counts']['high']);
    }

    // ── leverage / give-back / proxy risk dimensions ──────────────────────────

    public function test_high_give_back_risk_marks_diverse_batch_unbalanced_with_replacement(): void
    {
        $cats = [
            'bug_fix', 'architecture_unlock', 'test_gate',
            'runtime_continuity', 'task_quality_repair', 'docs_sync', 'learning_loop',
        ];
        $candidates = array_map(
            static fn (string $cat, int $i): array => [
                'label'          => $cat.'-'.$i,
                'category'       => $cat,
                'risk_tier'      => 'low',
                'final_score'    => 0.5,
                'give_back_risk' => 0.8,  // high
                'proxy_risk'     => 0.1,
                'leverage_score' => 0.5,
            ],
            $cats, array_keys($cats),
        );

        $result = $this->balancer()->balance($candidates);

        $this->assertSame('unbalanced', $result['status']);
        $this->assertFalse($result['passed']);
        $this->assertContains('high_give_back_risk', $result['unbalanced_risk_flags']);
        $this->assertNotNull($result['replacement_category']);
        $this->assertIsString($result['replacement_category']);
        $found = false;
        foreach ($result['balance_reasons'] as $r) {
            if (str_contains($r, 'give_back_risk:high')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'balance_reasons must include give_back_risk:high reason');
    }

    public function test_high_proxy_risk_diverse_batch_emits_replacement_category(): void
    {
        $cats = [
            'bug_fix', 'architecture_unlock', 'test_gate',
            'runtime_continuity', 'task_quality_repair', 'docs_sync', 'learning_loop',
        ];
        $candidates = array_map(
            static fn (string $cat, int $i): array => [
                'label'          => $cat.'-'.$i,
                'category'       => $cat,
                'risk_tier'      => 'low',
                'final_score'    => 0.5,
                'give_back_risk' => 0.1,
                'proxy_risk'     => 0.9,  // high
                'leverage_score' => 0.5,
            ],
            $cats, array_keys($cats),
        );

        $result = $this->balancer()->balance($candidates);

        $this->assertSame('unbalanced', $result['status']);
        $this->assertContains('high_proxy_risk', $result['unbalanced_risk_flags']);
        $this->assertNotNull($result['replacement_category']);
        $found = false;
        foreach ($result['balance_reasons'] as $r) {
            if (str_contains($r, 'proxy_risk:high')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'balance_reasons must include proxy_risk:high reason');
    }

    public function test_low_leverage_score_surfaces_in_balance_reasons(): void
    {
        $cats = ['bug_fix', 'architecture_unlock', 'test_gate'];
        $candidates = array_map(
            static fn (string $cat, int $i): array => [
                'label'          => $cat.'-'.$i,
                'category'       => $cat,
                'risk_tier'      => 'low',
                'final_score'    => 0.1,
                'give_back_risk' => 0.0,
                'proxy_risk'     => 0.0,
                'leverage_score' => 0.1,  // below MIN_AVG_LEVERAGE_SCORE (0.3)
            ],
            $cats, array_keys($cats),
        );

        $result = $this->balancer()->balance($candidates);

        $this->assertContains('low_leverage_score', $result['unbalanced_risk_flags']);
        $found = false;
        foreach ($result['balance_reasons'] as $r) {
            if (str_contains($r, 'leverage_score:low')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'balance_reasons must include leverage_score:low reason');
    }

    public function test_balance_reasons_include_missing_capability_dimension_for_deficits(): void
    {
        // 7 candidates all bug_fix → all other categories are deficits.
        $candidates = $this->candidates(array_fill(0, 7, 'bug_fix'));
        $result = $this->balancer()->balance($candidates);

        $dimensionReasons = array_filter(
            $result['balance_reasons'],
            static fn (string $r): bool => str_starts_with($r, 'missing_capability_dimension:'),
        );
        $this->assertNotEmpty($dimensionReasons);
        $dimensionCats = array_map(
            static fn (string $r): string => substr($r, strlen('missing_capability_dimension:')),
            array_values($dimensionReasons),
        );
        $this->assertContains('architecture_unlock', $dimensionCats);
        $this->assertContains('test_gate', $dimensionCats);
    }

    public function test_avg_risk_fields_present_in_output(): void
    {
        $result = $this->balancer()->balance($this->candidates(['bug_fix']));

        $this->assertArrayHasKey('avg_leverage_score', $result);
        $this->assertArrayHasKey('avg_give_back_risk', $result);
        $this->assertArrayHasKey('avg_proxy_risk', $result);
        $this->assertArrayHasKey('unbalanced_risk_flags', $result);
        $this->assertArrayHasKey('balance_reasons', $result);
        $this->assertArrayHasKey('replacement_category', $result);
    }

    public function test_categories_constant_has_seven_entries(): void
    {
        $this->assertCount(7, AtlasExternalBrainPortfolioBalancer::CATEGORIES);
        $this->assertContains('bug_fix',            AtlasExternalBrainPortfolioBalancer::CATEGORIES);
        $this->assertContains('architecture_unlock', AtlasExternalBrainPortfolioBalancer::CATEGORIES);
        $this->assertContains('learning_loop',       AtlasExternalBrainPortfolioBalancer::CATEGORIES);
    }

    // ── Operator ranking: missing high-priority + low-priority filler ─────────

    public function test_missing_task_quality_repair_with_docs_sync_filler_is_unbalanced(): void
    {
        // Has docs_sync (filler) but no task_quality_repair (high-priority)
        $cats = ['bug_fix', 'architecture_unlock', 'test_gate', 'learning_loop', 'docs_sync', 'runtime_continuity', 'docs_sync'];
        $result = $this->balancer()->balance($this->candidates($cats));

        $this->assertSame('unbalanced', $result['status']);
        $this->assertFalse($result['passed']);
        $this->assertContains('missing_high_priority_coverage', $result['unbalanced_risk_flags']);
    }

    public function test_missing_learning_loop_with_runtime_continuity_filler_is_unbalanced(): void
    {
        $cats = ['bug_fix', 'architecture_unlock', 'test_gate', 'task_quality_repair', 'runtime_continuity', 'docs_sync', 'runtime_continuity'];
        $result = $this->balancer()->balance($this->candidates($cats));

        $this->assertSame('unbalanced', $result['status']);
        $this->assertContains('missing_high_priority_coverage', $result['unbalanced_risk_flags']);
    }

    public function test_replacement_recommendations_emitted_for_missing_high_priority(): void
    {
        // task_quality_repair missing, docs_sync present → replace docs_sync with task_quality_repair
        $cats = ['bug_fix', 'architecture_unlock', 'test_gate', 'learning_loop', 'docs_sync', 'runtime_continuity'];
        $result = $this->balancer()->balance($this->candidates($cats));

        $this->assertNotEmpty($result['replacement_recommendations']);
        $withValues = array_column($result['replacement_recommendations'], 'with');
        $this->assertContains('task_quality_repair', $withValues);
    }

    public function test_no_replacement_recommendations_when_high_priority_present(): void
    {
        $cats = [
            'bug_fix', 'architecture_unlock', 'test_gate',
            'runtime_continuity', 'task_quality_repair', 'docs_sync', 'learning_loop',
        ];
        $result = $this->balancer()->balance($this->candidates($cats));

        $this->assertSame([], $result['replacement_recommendations']);
        $this->assertNotContains('missing_high_priority_coverage', $result['unbalanced_risk_flags']);
    }

    public function test_missing_high_priority_without_filler_does_not_trigger_replacement(): void
    {
        // No filler (docs_sync/runtime_continuity) in wave → no replacement_recommendations trigger
        $cats = ['bug_fix', 'bug_fix', 'architecture_unlock', 'test_gate'];
        $result = $this->balancer()->balance($this->candidates($cats));

        $this->assertSame([], $result['replacement_recommendations']);
        $this->assertNotContains('missing_high_priority_coverage', $result['unbalanced_risk_flags']);
    }

    public function test_balance_reasons_name_missing_high_priority_categories(): void
    {
        $cats = ['bug_fix', 'architecture_unlock', 'test_gate', 'docs_sync', 'runtime_continuity'];
        $result = $this->balancer()->balance($this->candidates($cats));

        $found = false;
        foreach ($result['balance_reasons'] as $r) {
            if (str_contains($r, 'missing_high_priority_category:')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'balance_reasons must name missing high-priority categories');
    }

    // ── Scaffold dominance + consolidation_debt ───────────────────────────────

    public function test_scaffold_dominated_wave_with_high_consolidation_debt_is_unbalanced(): void
    {
        // 6 of 7 candidates are new_organ/scaffold subtypes
        $candidates = [];
        for ($i = 0; $i < 6; $i++) {
            $candidates[] = ['label' => "scaffold-{$i}", 'category' => 'architecture_unlock', 'risk_tier' => 'low', 'final_score' => 0.5, 'category_subtype' => 'new_organ'];
        }
        $candidates[] = ['label' => 'real-1', 'category' => 'bug_fix', 'risk_tier' => 'low', 'final_score' => 0.5];

        $result = $this->balancer()->balance($candidates, ['consolidation_debt' => 0.80]);

        $this->assertSame('unbalanced', $result['status']);
        $this->assertContains('scaffold_dominance_with_high_consolidation_debt', $result['unbalanced_risk_flags']);
        $this->assertTrue($result['consolidation_debt_flag']);
    }

    public function test_scaffold_dominated_wave_with_low_consolidation_debt_is_not_flagged(): void
    {
        $candidates = [];
        for ($i = 0; $i < 6; $i++) {
            $candidates[] = ['label' => "scaffold-{$i}", 'category' => 'architecture_unlock', 'risk_tier' => 'low', 'final_score' => 0.5, 'category_subtype' => 'scaffold'];
        }
        $candidates[] = ['label' => 'real-1', 'category' => 'bug_fix', 'risk_tier' => 'low', 'final_score' => 0.5];

        // consolidation_debt below threshold (0.60)
        $result = $this->balancer()->balance($candidates, ['consolidation_debt' => 0.30]);

        $this->assertFalse($result['consolidation_debt_flag']);
        $this->assertNotContains('scaffold_dominance_with_high_consolidation_debt', $result['unbalanced_risk_flags']);
    }

    public function test_diverse_wave_with_high_consolidation_debt_but_low_scaffold_ratio_not_flagged(): void
    {
        // Only 1 of 7 is scaffold → scaffold_ratio = 0.14 ≤ 0.50 threshold
        $cats = [
            'bug_fix', 'architecture_unlock', 'test_gate',
            'runtime_continuity', 'task_quality_repair', 'docs_sync', 'learning_loop',
        ];
        $candidates = $this->candidates($cats);
        $candidates[0]['category_subtype'] = 'new_organ'; // only one scaffold

        $result = $this->balancer()->balance($candidates, ['consolidation_debt' => 0.90]);

        $this->assertNotContains('scaffold_dominance_with_high_consolidation_debt', $result['unbalanced_risk_flags']);
    }

    // ── New output fields ─────────────────────────────────────────────────────

    public function test_output_includes_replacement_recommendations_and_consolidation_debt_flag(): void
    {
        $result = $this->balancer()->balance($this->candidates(['bug_fix']));

        $this->assertArrayHasKey('replacement_recommendations', $result);
        $this->assertArrayHasKey('consolidation_debt_flag', $result);
    }

    public function test_priority_ranking_constant_lists_high_priority_first(): void
    {
        $ranking = AtlasExternalBrainPortfolioBalancer::PRIORITY_RANKING;
        $this->assertSame('task_quality_repair', $ranking[0]);
        $this->assertSame('learning_loop', $ranking[1]);
        // filler must be at the end
        $docsSyncIdx        = array_search('docs_sync',         $ranking, true);
        $runtimeIdx         = array_search('runtime_continuity', $ranking, true);
        $taskQualityIdx     = array_search('task_quality_repair', $ranking, true);
        $this->assertGreaterThan($taskQualityIdx, $docsSyncIdx);
        $this->assertGreaterThan($taskQualityIdx, $runtimeIdx);
    }
}
