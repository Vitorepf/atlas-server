<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLocalResearchFrontierTriageEngine;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainLocalResearchFrontierTriageEngineTest extends TestCase
{
    private function engine(): AtlasExternalBrainLocalResearchFrontierTriageEngine
    {
        return new AtlasExternalBrainLocalResearchFrontierTriageEngine;
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'id'                               => 'r1',
            'title'                            => 'Test paper',
            'evidence_strength'                => 0.8,
            'has_code'                         => true,
            'has_benchmark'                    => true,
            'hype_signals'                     => [],
            'atlas_fit_score'                  => 1.0,
            'implementation_risk'              => 0.0,
            'provider_steady_state_dependency' => false,
            'expected_compounding_impact'      => 0.5,
        ], $overrides);
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->engine()->triage([]);
        $this->assertSame(AtlasExternalBrainLocalResearchFrontierTriageEngine::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('promising', $r);
        $this->assertArrayHasKey('exploratory', $r);
        $this->assertArrayHasKey('hype_rejected', $r);
        $this->assertArrayHasKey('ungrounded_rejected', $r);
        $this->assertArrayHasKey('provider_dependent_rejected', $r);
        $this->assertArrayHasKey('high_risk_rejected', $r);
        $this->assertArrayHasKey('no_atlas_fit_rejected', $r);
        $this->assertArrayHasKey('promising_count', $r);
        $this->assertArrayHasKey('next_research_action', $r);
        $this->assertArrayHasKey('leverage_rank', $r);
        $this->assertArrayHasKey('hold_for_review', $r);
        $this->assertArrayHasKey('duplicate_family_warnings', $r);
        $this->assertArrayHasKey('source_diversity_summary', $r);
    }

    // ── task_seed_hints carries source_family + dedup_key ──────────────────────

    public function test_task_seed_hints_includes_source_family_and_dedup_key(): void
    {
        $r = $this->engine()->triage(['frontier_rows' => [
            $this->row(['source_family' => 'arxiv', 'dedup_key' => 'arxiv:paper-1']),
        ]]);

        $hints = $r['promising'][0]['task_seed_hints'];
        $this->assertSame('arxiv', $hints['source_family']);
        $this->assertSame('arxiv:paper-1', $hints['dedup_key']);
    }

    // ── duplicate family pressure ──────────────────────────────────────────────

    public function test_duplicate_family_warning_emitted_when_multiple_promising_rows_share_family(): void
    {
        $r = $this->engine()->triage(['frontier_rows' => [
            $this->row(['id' => 'a', 'source_family' => 'github']),
            $this->row(['id' => 'b', 'source_family' => 'github']),
        ]]);

        $this->assertNotEmpty($r['duplicate_family_warnings']);
        $this->assertTrue($r['leverage_rank'][0]['duplicate_family_pressure']);
    }

    public function test_no_duplicate_family_warning_when_families_distinct(): void
    {
        $r = $this->engine()->triage(['frontier_rows' => [
            $this->row(['id' => 'a', 'source_family' => 'github']),
            $this->row(['id' => 'b', 'source_family' => 'arxiv']),
        ]]);

        $this->assertSame([], $r['duplicate_family_warnings']);
    }

    // ── source diversity summary ────────────────────────────────────────────────

    public function test_source_diversity_summary_counts_unique_families(): void
    {
        $r = $this->engine()->triage(['frontier_rows' => [
            $this->row(['id' => 'a', 'source_family' => 'github']),
            $this->row(['id' => 'b', 'source_family' => 'arxiv']),
        ]]);

        $this->assertSame(2, $r['source_diversity_summary']['unique_family_count']);
        $this->assertSame(1, $r['source_diversity_summary']['counts_by_family']['github']);
        $this->assertSame(1, $r['source_diversity_summary']['counts_by_family']['arxiv']);
    }

    public function test_empty_rows_yields_zero_counts(): void
    {
        $r = $this->engine()->triage(['frontier_rows' => []]);
        $this->assertSame(0, $r['promising_count']);
        $this->assertSame(0, $r['exploratory_count']);
        $this->assertSame(0, $r['hype_rejected_count']);
        $this->assertSame(0, $r['ungrounded_rejected_count']);
    }

    // ── Promising ─────────────────────────────────────────────────────────────

    public function test_high_evidence_with_code_is_promising(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['evidence_strength' => 0.90, 'has_code' => true])],
        ]);
        $this->assertSame(1, $r['promising_count']);
        $this->assertEmpty($r['exploratory']);
    }

    public function test_high_evidence_with_benchmark_but_no_code_is_promising(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['evidence_strength' => 0.75, 'has_code' => false, 'has_benchmark' => true])],
        ]);
        $this->assertSame(1, $r['promising_count']);
    }

    public function test_high_evidence_but_no_code_no_benchmark_is_exploratory(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['evidence_strength' => 0.80, 'has_code' => false, 'has_benchmark' => false])],
        ]);
        $this->assertSame(0, $r['promising_count']);
        $this->assertSame(1, $r['exploratory_count']);
    }

    // ── Exploratory ───────────────────────────────────────────────────────────

    public function test_moderate_evidence_with_code_is_exploratory(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['evidence_strength' => 0.55, 'has_code' => true])],
        ]);
        $this->assertSame(1, $r['exploratory_count']);
        $this->assertSame(0, $r['promising_count']);
    }

    public function test_moderate_evidence_without_code_above_ungrounded_cap_is_exploratory(): void
    {
        // evidence 0.35 ≥ UNGROUNDED_CAP(0.30), no code, no benchmark → exploratory (not rejected).
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['evidence_strength' => 0.35, 'has_code' => false, 'has_benchmark' => false])],
        ]);
        $this->assertSame(1, $r['exploratory_count']);
        $this->assertSame(0, $r['ungrounded_rejected_count']);
    }

    // ── AC2: hype rejection ───────────────────────────────────────────────────

    public function test_two_hype_signals_with_low_evidence_is_hype_rejected(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row([
                'evidence_strength' => 0.2,
                'hype_signals'      => ['revolutionary', 'paradigm-shifting'],
            ])],
        ]);
        $this->assertSame(1, $r['hype_rejected_count']);
        $this->assertSame('hype_signals_with_low_evidence', $r['hype_rejected'][0]['reason']);
    }

    public function test_many_hype_signals_but_high_evidence_is_not_hype_rejected(): void
    {
        // evidence >= 0.40 threshold → survives hype check.
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row([
                'evidence_strength' => 0.50,
                'has_code'          => true,
                'hype_signals'      => ['revolutionary', 'breakthrough', 'paradigm'],
            ])],
        ]);
        $this->assertSame(0, $r['hype_rejected_count']);
    }

    public function test_one_hype_signal_does_not_trigger_hype_rejection(): void
    {
        // < HYPE_SIGNAL_MIN(2) → not rejected for hype.
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row([
                'evidence_strength' => 0.1,
                'hype_signals'      => ['revolutionary'],
                'has_code'          => false,
                'has_benchmark'     => false,
            ])],
        ]);
        $this->assertSame(0, $r['hype_rejected_count']);
        $this->assertSame(1, $r['ungrounded_rejected_count']); // falls to ungrounded
    }

    // ── AC2: ungrounded rejection ─────────────────────────────────────────────

    public function test_no_code_no_benchmark_low_evidence_is_ungrounded_rejected(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row([
                'evidence_strength' => 0.20,
                'has_code'          => false,
                'has_benchmark'     => false,
                'hype_signals'      => [],
            ])],
        ]);
        $this->assertSame(1, $r['ungrounded_rejected_count']);
        $this->assertSame('no_code_no_benchmark_low_evidence', $r['ungrounded_rejected'][0]['reason']);
    }

    public function test_hype_check_runs_before_ungrounded_check(): void
    {
        // 2 hype signals, evidence 0.1, no code → hype wins, not ungrounded.
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row([
                'evidence_strength' => 0.10,
                'has_code'          => false,
                'has_benchmark'     => false,
                'hype_signals'      => ['revolutionary', 'paradigm-shifting'],
            ])],
        ]);
        $this->assertSame(1, $r['hype_rejected_count']);
        $this->assertSame(0, $r['ungrounded_rejected_count']);
    }

    // ── Provider dependency rejection ─────────────────────────────────────────

    public function test_provider_steady_state_dependency_is_rejected(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row([
                'provider_steady_state_dependency' => true,
            ])],
        ]);
        $this->assertCount(1, $r['provider_dependent_rejected']);
        $this->assertSame('provider_steady_state_dependency', $r['provider_dependent_rejected'][0]['reason']);
        $this->assertSame(0, $r['promising_count']);
    }

    public function test_provider_dependent_rejected_before_promising_gate(): void
    {
        // Even with perfect scores, provider_steady_state_dependency blocks.
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row([
                'evidence_strength'                => 0.95,
                'atlas_fit_score'                  => 1.0,
                'implementation_risk'              => 0.0,
                'provider_steady_state_dependency' => true,
            ])],
        ]);
        $this->assertSame(0, $r['promising_count']);
        $this->assertCount(1, $r['provider_dependent_rejected']);
    }

    // ── High-risk rejection ───────────────────────────────────────────────────

    public function test_high_implementation_risk_is_rejected(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row([
                'implementation_risk' => 0.75,  // >= RISK_CEILING(0.70)
            ])],
        ]);
        $this->assertCount(1, $r['high_risk_rejected']);
        $this->assertSame('implementation_risk_above_ceiling', $r['high_risk_rejected'][0]['reason']);
        $this->assertSame(0, $r['promising_count']);
    }

    public function test_risk_at_ceiling_is_rejected(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['implementation_risk' => 0.70])],
        ]);
        $this->assertCount(1, $r['high_risk_rejected']);
    }

    public function test_risk_just_below_ceiling_passes(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['implementation_risk' => 0.69])],
        ]);
        $this->assertSame(0, count($r['high_risk_rejected']));
    }

    // ── No Atlas fit rejection ────────────────────────────────────────────────

    public function test_low_atlas_fit_score_is_rejected(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row([
                'atlas_fit_score' => 0.30,  // < ATLAS_FIT_FLOOR(0.50)
            ])],
        ]);
        $this->assertCount(1, $r['no_atlas_fit_rejected']);
        $this->assertSame('atlas_fit_score_below_floor', $r['no_atlas_fit_rejected'][0]['reason']);
        $this->assertSame(0, $r['promising_count']);
    }

    public function test_atlas_fit_at_floor_passes(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['atlas_fit_score' => 0.50])],
        ]);
        $this->assertSame(0, count($r['no_atlas_fit_rejected']));
    }

    // ── next_research_action ──────────────────────────────────────────────────

    public function test_next_action_is_harvest_when_promising_rows_exist(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['evidence_strength' => 0.90])],
        ]);
        $this->assertSame('harvest_top_promising', $r['next_research_action']);
    }

    public function test_next_action_is_deepen_when_only_exploratory(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['evidence_strength' => 0.55])],
        ]);
        $this->assertSame('deepen_evidence_for_exploratory', $r['next_research_action']);
    }

    public function test_next_action_is_provider_free_when_only_provider_dependent(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['provider_steady_state_dependency' => true])],
        ]);
        $this->assertSame('identify_provider_free_alternatives', $r['next_research_action']);
    }

    public function test_next_action_is_expand_when_all_rejected_and_no_exploratory(): void
    {
        $r = $this->engine()->triage(['frontier_rows' => []]);
        $this->assertSame('expand_research_breadth', $r['next_research_action']);
    }

    // ── leverage_rank ─────────────────────────────────────────────────────────

    public function test_leverage_rank_sorts_promising_by_compounding_impact_desc(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [
                $this->row(['id' => 'low',  'evidence_strength' => 0.80, 'expected_compounding_impact' => 0.2]),
                $this->row(['id' => 'high', 'evidence_strength' => 0.80, 'expected_compounding_impact' => 0.9]),
                $this->row(['id' => 'mid',  'evidence_strength' => 0.80, 'expected_compounding_impact' => 0.6]),
            ],
        ]);
        $ids = array_column($r['leverage_rank'], 'id');
        $this->assertSame(['high', 'mid', 'low'], $ids);
    }

    public function test_leverage_rank_empty_when_no_promising(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['evidence_strength' => 0.55])],
        ]);
        $this->assertSame([], $r['leverage_rank']);
    }

    // ── AC1: new deterministic score fields ──────────────────────────────────

    public function test_atlas_fit_score_in_per_row_output(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['atlas_fit_score' => 0.75, 'evidence_strength' => 0.90])],
        ]);
        $this->assertSame(0.75, $r['promising'][0]['atlas_fit_score']);
    }

    public function test_evidence_quality_score_includes_code_and_benchmark_bonus(): void
    {
        // evidence=0.70, has_code=true, has_benchmark=true → 0.70+0.10+0.10 = 0.90
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['evidence_strength' => 0.70, 'has_code' => true, 'has_benchmark' => true])],
        ]);
        $this->assertSame(0.9, $r['promising'][0]['evidence_quality_score']);
    }

    public function test_evidence_quality_score_no_bonus_without_code_or_benchmark(): void
    {
        // evidence=0.80, no code, no benchmark → score = 0.80
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['evidence_strength' => 0.80, 'has_code' => false, 'has_benchmark' => false])],
        ]);
        $this->assertSame(0.8, $r['exploratory'][0]['evidence_quality_score']);
    }

    public function test_evidence_quality_score_capped_at_one(): void
    {
        // evidence=0.95, has_code=true, has_benchmark=true → 0.95+0.10+0.10 = 1.15 → capped at 1.0
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['evidence_strength' => 0.95, 'has_code' => true, 'has_benchmark' => true])],
        ]);
        $this->assertSame(1.0, $r['promising'][0]['evidence_quality_score']);
    }

    public function test_implementation_risk_score_increases_for_provider_dependency(): void
    {
        // risk=0.20, provider_dep=true → 0.20+0.30 = 0.50
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row([
                'implementation_risk'              => 0.20,
                'provider_steady_state_dependency' => true,
            ])],
        ]);
        $this->assertSame(0.5, $r['provider_dependent_rejected'][0]['implementation_risk_score']);
    }

    public function test_implementation_risk_score_equals_risk_when_no_provider_dep(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['implementation_risk' => 0.30])],
        ]);
        $this->assertSame(0.3, $r['promising'][0]['implementation_risk_score']);
    }

    public function test_provider_dependency_score_is_one_when_dependent(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row(['provider_steady_state_dependency' => true])],
        ]);
        $this->assertSame(1.0, $r['provider_dependent_rejected'][0]['provider_dependency_score']);
    }

    public function test_provider_dependency_score_is_zero_when_not_dependent(): void
    {
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row()],
        ]);
        $this->assertSame(0.0, $r['promising'][0]['provider_dependency_score']);
    }

    // ── AC2: reject hype/provider even with high novelty; promote grounded ────

    public function test_high_compounding_impact_does_not_bypass_hype_rejection(): void
    {
        // High novelty (compounding_impact=0.99) but hype+low evidence → still hype_rejected.
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row([
                'evidence_strength'          => 0.20,
                'hype_signals'               => ['revolutionary', 'paradigm'],
                'expected_compounding_impact' => 0.99,
            ])],
        ]);
        $this->assertSame(1, $r['hype_rejected_count']);
        $this->assertSame(0, $r['promising_count']);
    }

    public function test_provider_dependent_rejected_even_with_perfect_atlas_fit_and_high_impact(): void
    {
        // Provider dependency is a hard block regardless of other scores.
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row([
                'atlas_fit_score'                  => 1.0,
                'expected_compounding_impact'      => 1.0,
                'evidence_strength'                => 0.99,
                'provider_steady_state_dependency' => true,
            ])],
        ]);
        $this->assertSame(0, $r['promising_count']);
        $this->assertCount(1, $r['provider_dependent_rejected']);
    }

    public function test_grounded_local_code_research_promoted_to_promising(): void
    {
        // Good evidence, has_code, no hype, no provider dep → promised.
        $r = $this->engine()->triage([
            'frontier_rows' => [$this->row([
                'evidence_strength'                => 0.80,
                'has_code'                         => true,
                'hype_signals'                     => [],
                'provider_steady_state_dependency' => false,
                'atlas_fit_score'                  => 0.90,
            ])],
        ]);
        $this->assertSame(1, $r['promising_count']);
        $this->assertGreaterThan(0.80, $r['promising'][0]['evidence_quality_score']); // bonus from has_code
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = ['frontier_rows' => [
            $this->row(['id' => 'a', 'evidence_strength' => 0.9]),
            $this->row(['id' => 'b', 'evidence_strength' => 0.1, 'has_code' => false, 'has_benchmark' => false]),
        ]];
        $a = $this->engine()->triage($facts);
        $b = $this->engine()->triage($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
